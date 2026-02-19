<?php

/**
 * Tracking Push Service - builds payload per CLMS spec 8.1
 * Logs to logs/tracking_push.log; ready for real HTTP call when contract exists
 */

class TrackingPushService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
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

        $header = [
            'shipment_draft_id' => $shipmentDraftId,
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
                    'quantity' => $row['quantity'],
                    'unit' => $row['unit'],
                    'declared_cbm' => $row['declared_cbm'],
                    'declared_weight' => $row['declared_weight'],
                    'description_cn' => $row['description_cn'],
                    'description_en' => $row['description_en'],
                ];
            }
            $att = $this->pdo->prepare("SELECT file_path, type FROM order_attachments WHERE order_id = ?");
            $att->execute([$oid]);
            while ($a = $att->fetch(PDO::FETCH_ASSOC)) {
                $documents[] = array_merge($a, ['order_id' => $oid]);
            }
        }

        $payload = [
            'header' => $header,
            'items' => $items,
            'documents' => $documents,
            'pushed_at' => date('c'),
        ];

        $logDir = dirname(__DIR__, 2) . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . '/tracking_push.log';
        file_put_contents($logFile, date('Y-m-d H:i:s') . " | " . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n---\n", FILE_APPEND);

        return [
            'success' => true,
            'message' => 'Pushed to tracking (stub)',
            'header' => $header,
            'items_count' => count($items),
            'documents_count' => count($documents),
        ];
    }
}
