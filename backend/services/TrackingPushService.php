<?php

/**
 * Tracking Push Service - stub implementation
 * Builds payload and "pushes" to tracking system (stub returns success until real API exists)
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
        $stmt = $this->pdo->prepare("SELECT sd.*, c.code as container_code FROM shipment_drafts sd LEFT JOIN containers c ON sd.container_id = c.id WHERE sd.id = ?");
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
            'container_code' => $sd['container_code'] ?? null,
            'order_count' => count($orderIds),
        ];

        $items = [];
        foreach ($orderIds as $oid) {
            $oi = $this->pdo->prepare("SELECT oi.*, o.customer_id, o.supplier_id FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE o.id = ?");
            $oi->execute([$oid]);
            while ($row = $oi->fetch(PDO::FETCH_ASSOC)) {
                $items[] = $row;
            }
        }

        // Stub: log and return success (replace with real API call when contract exists)
        error_log('[CLMS] TrackingPush: ' . json_encode(['header' => $header, 'item_count' => count($items)]));

        return [
            'success' => true,
            'message' => 'Pushed to tracking (stub)',
            'header' => $header,
            'items_count' => count($items),
        ];
    }
}
