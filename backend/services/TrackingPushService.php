<?php
require_once __DIR__.'/LogRetentionService.php';
require_once __DIR__ . '/CargoMetricsService.php';
require_once __DIR__ . '/ShipmentAssignmentService.php';

/**
 * Tracking Push Service - Phase 3
 * Idempotent push to Lebanon tracking API with retries, logging, dry-run.
 * Decision B: Finalize locally; push can fail and be retried later.
 */

class TrackingPushBusyException extends RuntimeException {}

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
        if ($this->pdo->inTransaction()) throw new RuntimeException("Tracking cannot be sent before the local transaction commits");
        $lockName = 'clms-push-' . substr(hash('sha256', (string)$this->pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 16) . '-' . $shipmentDraftId;
        $claim = $this->pdo->prepare('SELECT GET_LOCK(?,0)');
        $claim->execute([$lockName]);
        if ((int)$claim->fetchColumn() !== 1) throw new TrackingPushBusyException('Tracking push is already in progress; wait for its result');
        try {
            return $this->pushLocked($shipmentDraftId);
        } finally {
            $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        }
    }

    public function mode(): string
    {
        if(!(int)($this->config['tracking_push_enabled']??0))return 'disabled';
        return (int)($this->config['tracking_push_dry_run']??0)?'dry_run':'live';
    }

    /** Persist the immutable delivery snapshot in the finalization transaction. No network. */
    public function prepare(int $shipmentDraftId): array
    {
        $owned=!$this->pdo->inTransaction();if($owned)$this->pdo->beginTransaction();
        try{$log=$this->prepareLocked($shipmentDraftId);if($owned)$this->pdo->commit();return $log;}
        catch(Throwable $e){if($owned&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function prepareLocked(int $shipmentDraftId): array
    {
        $parent=$this->pdo->prepare('SELECT container_id FROM shipment_drafts WHERE deleted_at IS NULL AND id=?');$parent->execute([$shipmentDraftId]);$containerId=$parent->fetchColumn();
        if($containerId){$lock=$this->pdo->prepare('SELECT id FROM containers WHERE id=? FOR UPDATE');$lock->execute([$containerId]);}
        $stmt = $this->pdo->prepare("SELECT sd.*, c.id as container_id, c.code as container_code FROM shipment_drafts sd LEFT JOIN containers c ON sd.container_id = c.id WHERE sd.deleted_at IS NULL AND sd.id = ? FOR UPDATE");
        $stmt->execute([$shipmentDraftId]);
        $sd = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sd) {
            throw new RuntimeException('Shipment draft not found');
        }

        if ($sd['status'] !== 'finalized') throw new RuntimeException('Shipment draft must be finalized before tracking push');

        $existing=$this->pdo->prepare("SELECT * FROM tracking_push_log WHERE idempotency_key=?");$existing->execute(['clms-draft-'.$shipmentDraftId]);$log=$existing->fetch(PDO::FETCH_ASSOC);if($log)return $log;
        $so = $this->pdo->prepare("SELECT order_id FROM shipment_draft_orders WHERE shipment_draft_id = ? ORDER BY order_id");
        $so->execute([$shipmentDraftId]);
        $orderIds = array_column($so->fetchAll(PDO::FETCH_ASSOC), 'order_id');

        if(!$orderIds||empty($sd['container_id']))throw new RuntimeException('Finalized shipment requires a container and received cargo');
        foreach($orderIds as $orderId)ShipmentAssignmentService::assertReceived($this->pdo,(int)$orderId);
        $payload = $this->buildPayload($shipmentDraftId, $sd, $orderIds);
        return $this->getOrCreateLog('clms-draft-'.$shipmentDraftId,'shipment_draft',$shipmentDraftId,$payload);
    }

    private function pushLocked(int $shipmentDraftId): array
    {
        $idempotencyKey = 'clms-draft-' . $shipmentDraftId;

        $enabled = (int) ($this->config['tracking_push_enabled'] ?? 0);
        $dryRun = (int) ($this->config['tracking_push_dry_run'] ?? 1);

        $log = $this->prepare($shipmentDraftId);
        if (!in_array($log['status'], ['pending','disabled','dry_run','failed','success'], true)) throw new RuntimeException('Unknown tracking delivery state requires reconciliation');

        if ($log['status'] === 'success') {
            return [
                'success' => true,
                'status' => 'success',
                'message' => 'Already pushed (idempotent skip)',
                'external_id' => $log['external_id'],
                'log_id' => $log['id'],
            ];
        }

        $payload=json_decode((string)$log['request_payload'],true);
        if(!is_array($payload)||($payload['header']['shipment_draft_id']??null)!==$shipmentDraftId||empty($payload['items']))throw new RuntimeException('Tracking snapshot is missing or invalid; reconcile before retrying');
        if (!$enabled || $dryRun) {
            $status = !$enabled ? 'disabled' : 'dry_run';
            $message = !$enabled ? 'Tracking push disabled; no request sent. Enable tracking push in Configuration.' : 'Dry-run: payload logged, no remote call';
            $this->updateLog($log['id'], $status, null, null, null, $message);

            return [
                'success' => false,
                'status' => $status,
                'message' => $message,
                'log_id' => $log['id'],
            ];
        }
        if (empty(trim($this->config['tracking_api_base_url'] ?? ''))) {
            $message = 'Tracking API endpoint is not configured; no request sent.';
            $this->updateLog($log['id'], 'failed', null, null, null, $message);
            $this->diagnostic($shipmentDraftId, $payload, 'failed', 0);
            throw new RuntimeException($message);
        }

        $timeout = max(1,min(30,(int) ($this->config['tracking_api_timeout_sec'] ?? 15)));
        $retryCount = max(0,min(3,(int) ($this->config['tracking_api_retry_count'] ?? 3)));
        $backoffMs = max(0,min(2000,(int) ($this->config['tracking_api_retry_backoff_ms'] ?? 800)));
        $baseUrl = rtrim($this->config['tracking_api_base_url'], '/');
        $path = ltrim($this->config['tracking_api_path'] ?? '/api/import/clms', '/');
        $url = $baseUrl . '/' . $path;
        $token = $this->config['tracking_api_token'] ?? '';
        $parts=parse_url($url);
        if(!$parts||!in_array(strtolower($parts['scheme']??''),['https','http'],true)||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||preg_match('/[\x00-\x20\x7f]/',$url)||preg_match('/[\r\n]/',$token)){
            $this->updateLog($log['id'],'failed',null,null,null,'Invalid tracking endpoint or credentials; no request sent');
            throw new RuntimeException('Invalid tracking endpoint or credentials; no request sent');
        }

        $lastError = null;
        $responseCode = null;
        $responseBody = null;

        for ($r = 0; $r <= $retryCount; $r++) {
            $this->updateLogAttempt($log['id'], (int) $log['attempt_count'] + $r + 1);
            try {
                $response=$this->sendRequest($url,(string)$log['request_payload'],$token,$idempotencyKey,$timeout);
                $body=$response['body'];$code=$response['code'];$err=$response['error'];

                if ($err) {
                    throw new RuntimeException('cURL: ' . $err);
                }

                $responseCode = $code;
                $responseBody = is_string($body) ? substr($body, 0, 65535) : '';

                if ($code >= 200 && $code < 300) {
                    $decoded = json_decode($body, true);
                    // Some APIs return HTTP 200 for an application-level rejection.
                    if (is_array($decoded) && (($decoded['success'] ?? null) === false || ($decoded['accepted'] ?? null) === false || !empty($decoded['error']))) {
                        $lastError = 'Tracking API rejected request: ' . substr((string) ($decoded['message'] ?? (is_string($decoded['error'] ?? null) ? $decoded['error'] : 'not accepted')), 0, 200);
                        break;
                    }
                    $externalId = $decoded['external_shipment_id'] ?? $decoded['id'] ?? null;
                    if(!is_array($decoded)||(!is_string($externalId)&&!is_int($externalId)&&($decoded['success']??null)!==true&&($decoded['accepted']??null)!==true)||$externalId===''||is_array($externalId)){
                        $lastError='Tracking API returned no verifiable acceptance; reconcile remote outcome before retrying';break;
                    }
                    $externalId=$externalId!==null?(string)$externalId:null;
                    if($externalId!==null&&strlen($externalId)>255){$lastError='Tracking API returned an invalid external shipment reference';break;}
                    $this->updateLog($log['id'], 'success', $code, $responseBody, $externalId, null);
                    $this->diagnostic($shipmentDraftId, $payload, 'success', $code);
                    return [
                        'success' => true,
                        'status' => 'success',
                        'message' => 'Pushed to tracking',
                        'external_id' => $externalId,
                        'response_code' => $code,
                        'log_id' => $log['id'],
                    ];
                }

                if ($code >= 400 && $code < 500 && $code !== 429) {
                    $lastError = 'Tracking API error ' . $code . ': ' . substr($responseBody, 0, 200);
                    break;
                }

                $lastError = 'HTTP ' . $code;
                if ($r < $retryCount) {
                    $this->waitBeforeRetry($backoffMs * ($r + 1));
                }
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                if ($r < $retryCount) {
                    $this->waitBeforeRetry($backoffMs * ($r + 1));
                } else {
                    $this->updateLog($log['id'], 'failed', $responseCode, $responseBody, null, $lastError);
                    $this->diagnostic($shipmentDraftId, $payload, 'failed', $responseCode ?? 0);
                    throw $e;
                }
            }
        }

        $this->updateLog($log['id'], 'failed', $responseCode, $responseBody, null, $lastError ?? 'Max retries exceeded');
        $this->diagnostic($shipmentDraftId, $payload, 'failed', $responseCode ?? 0);
        throw new RuntimeException('Push failed: ' . ($lastError ?? 'unknown'));
    }

    protected function sendRequest(string $url,string $body,string $token,string $key,int $timeout): array
    {
        $received='';$tooLarge=false;$ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token,'Idempotency-Key: '.$key],CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>min(5,$timeout),CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use(&$received,&$tooLarge){if(strlen($received)+strlen($chunk)>65535){$tooLarge=true;return 0;}$received.=$chunk;return strlen($chunk);}]);
        curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=$tooLarge?'Tracking response exceeds supported size':curl_error($ch);curl_close($ch);
        return ['code'=>$code,'body'=>$received,'error'=>$error];
    }

    protected function waitBeforeRetry(int $milliseconds): void { usleep($milliseconds*1000); }

    private function buildPayload(int $draftId, array $sd, array $orderIds): array
    {
        $header = [
            'shipment_draft_id' => $draftId,
            'container_id' => $sd['container_id'] ?? null,
            'container_code' => $sd['container_code'] ?? null,
            'container_number' => $sd['container_number'] ?? null,
            'booking_number' => $sd['booking_number'] ?? null,
            'tracking_url' => $sd['tracking_url'] ?? null,
            'order_ids' => $orderIds,
        ];

        $items = [];
        $documents = [];
        foreach ($orderIds as $oid) {
            $oi = $this->pdo->prepare("SELECT oi.*, o.customer_id, COALESCE(oi.supplier_id,o.supplier_id) as supplier_id, o.currency, o.destination_country_id, c.name as customer_name FROM order_items oi JOIN orders o ON oi.order_id = o.id JOIN customers c ON o.customer_id = c.id WHERE o.id = ? ORDER BY oi.id");
            $oi->execute([$oid]);
            foreach (CargoMetricsService::shippingItems($this->pdo, $oi->fetchAll(PDO::FETCH_ASSOC)) as $row) {
                if ($row['quantity']===null || $row['declared_cbm']===null || $row['declared_weight']===null) throw new RuntimeException('Historical item quantities require reconciliation before tracking payload generation');
                $items[] = [
                    'order_id' => $oid,
                    'customer_id' => $row['customer_id'],
                    'customer_name' => $row['customer_name'],
                    'supplier_id' => $row['supplier_id'],
                    'currency' => $row['currency'],
                    'destination_country_id' => $row['destination_country_id'],
                    'product_id' => $row['product_id'],
                    'item_no' => $row['item_no'] ?? null,
                    'quantity' => $row['quantity'],
                    'unit' => $row['unit'],
                    'declared_cbm' => $row['declared_cbm'],
                    'declared_weight' => $row['declared_weight'],
                    'unit_price' => $row['sell_price'] ?? $row['unit_price'] ?? null,
                    'total_amount' => $row['total_amount'] ?? null,
                    'description_cn' => $row['description_cn'],
                    'description_en' => $row['description_en'],
                    'image_paths' => is_array($row['image_paths']??null)?$row['image_paths']:(json_decode($row['image_paths']??'[]',true)?:[]),
                ];
            }
            $att = $this->pdo->prepare("SELECT file_path, type FROM order_attachments WHERE order_id = ? ORDER BY id");
            $att->execute([$oid]);
            while ($a = $att->fetch(PDO::FETCH_ASSOC)) {
                $documents[] = array_merge($a, ['order_id' => $oid]);
            }
        }
        $docs=$this->pdo->prepare('SELECT file_path,doc_type as type FROM shipment_draft_documents WHERE shipment_draft_id=? ORDER BY id');$docs->execute([$draftId]);
        foreach($docs->fetchAll(PDO::FETCH_ASSOC) as $document)$documents[]=$document+['shipment_draft_id'=>$draftId];

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
                ->execute([$entityType, $entityId, $idempotencyKey, 'pending', json_encode($payload,JSON_THROW_ON_ERROR)]);
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

    private function diagnostic(int $draftId,array $payload,string $result,int $code): void
    {
        // The canonical result is in the database; a diagnostic failure must not resend cargo.
        try{$this->appendFileLog($draftId,$payload,$result,$code);}catch(Throwable $e){}
    }

    protected function appendFileLog(int $draftId, array $payload, string $result, int $code = 0, $extra = ''): void
    {
        $logDir = dirname(__DIR__, 2) . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $line = date('Y-m-d H:i:s') . " | draft=$draftId | $result" . ($code ? " | HTTP $code" : '') . "\n";
        if ($extra) $line .= (is_string($extra) ? $extra : json_encode($extra)) . "\n";
        $line .= "---\n";
        @file_put_contents(LogRetentionService::path('tracking_push'), $line, FILE_APPEND | LOCK_EX);
    }

    public function getPushStatus(int $shipmentDraftId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM tracking_push_log WHERE entity_type='shipment_draft' AND entity_id=? ORDER BY updated_at DESC LIMIT 1");
        $stmt->execute([$shipmentDraftId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    public static function statusResult(?array $log): array
    {
        $status=$log['status']??'pending';return ['success'=>$status==='success','status'=>$status,'message'=>$status==='success'?'Already pushed (idempotent skip)':($log['last_error']??'Tracking delivery is pending'),'external_id'=>$log['external_id']??null,'log_id'=>$log['id']??null];
    }
}
