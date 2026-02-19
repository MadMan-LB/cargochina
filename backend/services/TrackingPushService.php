<?php

/**
 * Tracking Push Service - Phase 3
 * Idempotent push to Lebanon tracking API with retries, logging, dry-run.
 * Decision B: Finalize locally; push can fail and be retried later.
 */

class TrackingPushService
{
    private PDO $pdo;
    private array $config;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->config = require dirname(__DIR__, 2) . '/backend/config/config.php';
    }

    public function push(int $shipmentDraftId): array
    {
        $stmt = $this->pdo->prepare("SELECT sd.*, c.id as container_id, c.code as container_code FROM shipment_drafts sd LEFT JOIN containers c ON sd.container_id = c.id WHERE sd.id = ?");
        $stmt->execute([$shipmentDraftId]);
        $sd = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sd) {
            throw new RuntimeException('Shipment draft not found');
        }

        $so = $this->pdo->prepare("SELECT order_id FROM shipment_draft_orders WHERE shipment_draft_id = ?");
        $so->execute([$shipmentDraftId]);
        $orderIds = array_column($so->fetchAll(PDO::FETCH_ASSOC), 'order_id');

        $payload = $this->buildPayload($shipmentDraftId, $sd, $orderIds);
        $idempotencyKey = 'clms-draft-' . $shipmentDraftId;

        $enabled = (int) ($this->config['tracking_push_enabled'] ?? 0);
        $dryRun = (int) ($this->config['tracking_push_dry_run'] ?? 1);

        $log = $this->getOrCreateLog($idempotencyKey, 'shipment_draft', $shipmentDraftId, $payload);

        if ($log['status'] === 'success') {
            return [
                'success' => true,
                'message' => 'Already pushed (idempotent skip)',
                'external_id' => $log['external_id'],
                'log_id' => $log['id'],
            ];
        }

        if ($dryRun || !$enabled || empty(trim($this->config['tracking_api_base_url'] ?? ''))) {
            $this->updateLog($log['id'], 'dry_run', null, null, null, 'Dry-run or disabled; payload logged only');
            $this->appendFileLog($shipmentDraftId, $payload, 'dry_run');
            return [
                'success' => true,
                'message' => $dryRun ? 'Dry-run: payload logged, no remote call' : 'Push disabled',
                'log_id' => $log['id'],
            ];
        }

        $attempt = (int) $log['attempt_count'] + 1;
        $this->updateLogAttempt($log['id'], $attempt);

        $timeout = (int) ($this->config['tracking_api_timeout_sec'] ?? 15);
        $retryCount = (int) ($this->config['tracking_api_retry_count'] ?? 3);
        $backoffMs = (int) ($this->config['tracking_api_retry_backoff_ms'] ?? 800);
        $baseUrl = rtrim($this->config['tracking_api_base_url'], '/');
        $path = ltrim($this->config['tracking_api_path'] ?? '/api/import/clms', '/');
        $url = $baseUrl . '/' . $path;
        $token = $this->config['tracking_api_token'] ?? '';

        $lastError = null;
        $responseCode = null;
        $responseBody = null;

        for ($r = 0; $r <= $retryCount; $r++) {
            try {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($payload),
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $token,
                        'Idempotency-Key: ' . $idempotencyKey,
                    ],
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_RETURNTRANSFER => true,
                ]);
                $body = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);

                if ($err) {
                    throw new RuntimeException('cURL: ' . $err);
                }

                $responseCode = $code;
                $responseBody = is_string($body) ? substr($body, 0, 65535) : '';

                if ($code >= 200 && $code < 300) {
                    $decoded = json_decode($body, true);
                    $externalId = $decoded['external_shipment_id'] ?? $decoded['id'] ?? null;
                    $this->updateLog($log['id'], 'success', $code, $responseBody, $externalId, null);
                    $this->appendFileLog($shipmentDraftId, $payload, 'success', $code, $body);
                    return [
                        'success' => true,
                        'message' => 'Pushed to tracking',
                        'external_id' => $externalId,
                        'response_code' => $code,
                        'log_id' => $log['id'],
                    ];
                }

                if ($code >= 400 && $code < 500 && $code !== 429) {
                    $this->updateLog($log['id'], 'failed', $code, $responseBody, null, 'Client error ' . $code);
                    throw new RuntimeException('Tracking API error ' . $code . ': ' . substr($responseBody, 0, 200));
                }

                $lastError = 'HTTP ' . $code;
                if ($r < $retryCount) {
                    usleep($backoffMs * 1000 * ($r + 1));
                }
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                if ($r < $retryCount) {
                    usleep($backoffMs * 1000 * ($r + 1));
                } else {
                    $this->updateLog($log['id'], 'failed', $responseCode, $responseBody, null, $lastError);
                    $this->appendFileLog($shipmentDraftId, $payload, 'failed', $responseCode ?? 0, $lastError);
                    throw $e;
                }
            }
        }

        $this->updateLog($log['id'], 'failed', $responseCode, $responseBody, null, $lastError ?? 'Max retries exceeded');
        throw new RuntimeException('Push failed after retries: ' . ($lastError ?? 'unknown'));
    }

    private function buildPayload(int $draftId, array $sd, array $orderIds): array
    {
        $header = [
            'shipment_draft_id' => $draftId,
            'container_id' => $sd['container_id'] ?? null,
            'container_code' => $sd['container_code'] ?? null,
            'order_ids' => $orderIds,
        ];

        $items = [];
        $documents = [];
        foreach ($orderIds as $oid) {
            $oi = $this->pdo->prepare("SELECT oi.*, o.customer_id, o.supplier_id, c.name as customer_name FROM order_items oi JOIN orders o ON oi.order_id = o.id JOIN customers c ON o.customer_id = c.id WHERE o.id = ?");
            $oi->execute([$oid]);
            while ($row = $oi->fetch(PDO::FETCH_ASSOC)) {
                $items[] = [
                    'order_id' => $oid,
                    'product_id' => $row['product_id'],
                    'item_no' => $row['item_no'] ?? null,
                    'quantity' => $row['quantity'],
                    'unit' => $row['unit'],
                    'declared_cbm' => $row['declared_cbm'],
                    'declared_weight' => $row['declared_weight'],
                    'unit_price' => $row['unit_price'] ?? null,
                    'total_amount' => $row['total_amount'] ?? null,
                    'description_cn' => $row['description_cn'],
                    'description_en' => $row['description_en'],
                    'image_paths' => $row['image_paths'] ? json_decode($row['image_paths'], true) : [],
                ];
            }
            $att = $this->pdo->prepare("SELECT file_path, type FROM order_attachments WHERE order_id = ?");
            $att->execute([$oid]);
            while ($a = $att->fetch(PDO::FETCH_ASSOC)) {
                $documents[] = array_merge($a, ['order_id' => $oid]);
            }
        }

        return [
            'header' => $header,
            'items' => $items,
            'documents' => $documents,
            'pushed_at' => date('c'),
        ];
    }

    private function getOrCreateLog(string $idempotencyKey, string $entityType, int $entityId, array $payload): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM tracking_push_log WHERE idempotency_key = ?");
        $stmt->execute([$idempotencyKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
        try {
            $this->pdo->prepare("INSERT INTO tracking_push_log (entity_type, entity_id, idempotency_key, status, request_payload, attempt_count) VALUES (?,?,?,?,?,0)")
                ->execute([$entityType, $entityId, $idempotencyKey, 'pending', json_encode($payload)]);
        } catch (PDOException $e) {
            if ($e->getCode() != 23000) throw $e;
        }
        $stmt->execute([$idempotencyKey]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function updateLog(int $logId, string $status, ?int $responseCode, ?string $responseBody, ?string $externalId, ?string $lastError): void
    {
        $this->pdo->prepare("UPDATE tracking_push_log SET status=?, response_code=?, response_body=?, external_id=?, last_error=?, updated_at=NOW() WHERE id=?")
            ->execute([$status, $responseCode, $responseBody, $externalId, $lastError, $logId]);
    }

    private function updateLogAttempt(int $logId, int $attemptCount): void
    {
        $this->pdo->prepare("UPDATE tracking_push_log SET attempt_count=?, updated_at=NOW() WHERE id=?")
            ->execute([$attemptCount, $logId]);
    }

    private function appendFileLog(int $draftId, array $payload, string $result, int $code = 0, $extra = ''): void
    {
        $logDir = dirname(__DIR__, 2) . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $line = date('Y-m-d H:i:s') . " | draft=$draftId | $result" . ($code ? " | HTTP $code" : '') . "\n";
        if ($extra) $line .= (is_string($extra) ? $extra : json_encode($extra)) . "\n";
        $line .= "---\n";
        file_put_contents($logDir . '/tracking_push.log', $line, FILE_APPEND);
    }

    public function getPushStatus(int $shipmentDraftId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM tracking_push_log WHERE entity_type='shipment_draft' AND entity_id=? ORDER BY updated_at DESC LIMIT 1");
        $stmt->execute([$shipmentDraftId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
