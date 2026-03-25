<?php

require_once __DIR__ . '/NotificationService.php';

final class OrderReceiptWorkflowService
{
    private static array $columnCache = [];

    public static function acceptAutoConfirmedOrder(PDO $pdo, int $orderId, ?int $userId = null, string $auditAction = 'confirm_by_token'): void
    {
        $order = self::fetchOrder($pdo, $orderId);
        if (!$order) {
            jsonError('Order not found', 404);
        }
        if (trim((string) ($order['confirmation_token'] ?? '')) === '') {
            jsonError('This order no longer has a pending customer response.', 400);
        }

        $receipt = self::fetchLatestReceipt($pdo, $orderId);
        $accepted = $receipt ? [
            'actual_cbm' => $receipt['actual_cbm'],
            'actual_weight' => $receipt['actual_weight'],
            'actual_cartons' => $receipt['actual_cartons'],
        ] : [];

        $pdo->prepare("UPDATE orders SET status='ReadyForConsolidation', confirmation_token=NULL WHERE id=?")
            ->execute([$orderId]);
        self::insertCustomerConfirmation($pdo, $orderId, $userId, $accepted, null);
        $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('order',?,?,?,?)")
            ->execute([$orderId, $auditAction, json_encode(['accepted_actuals' => $accepted], JSON_UNESCAPED_UNICODE), $userId]);
    }

    public static function declineAutoConfirmedOrder(PDO $pdo, int $orderId, string $reason, ?int $userId = null, string $auditAction = 'decline_by_token'): void
    {
        $order = self::fetchOrder($pdo, $orderId);
        if (!$order) {
            jsonError('Order not found', 404);
        }
        if (trim((string) ($order['confirmation_token'] ?? '')) === '') {
            jsonError('This order no longer has a pending customer response.', 400);
        }
        if (($order['status'] ?? '') === 'FinalizedAndPushedToTracking') {
            jsonError('This order has already been finalized to tracking and can no longer be declined from the customer portal.', 400);
        }

        self::detachOrderFromShipmentDrafts($pdo, $orderId);
        $pdo->prepare("UPDATE orders SET status='CustomerDeclinedAfterAutoConfirm', confirmation_token=NULL WHERE id=?")
            ->execute([$orderId]);
        self::insertCustomerConfirmation($pdo, $orderId, null, null, $reason);
        $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('order',?,?,?,?)")
            ->execute([$orderId, $auditAction, json_encode(['decline_reason' => $reason], JSON_UNESCAPED_UNICODE), $userId]);
        NotificationService::notifyOrderDeclined($orderId, $reason);
    }

    public static function resetDeclinedOrder(PDO $pdo, int $orderId, int $userId, ?string $reason = null): void
    {
        $order = self::fetchOrder($pdo, $orderId);
        if (!$order) {
            jsonError('Order not found', 404);
        }
        if (!in_array($order['status'] ?? '', ['CustomerDeclinedAfterAutoConfirm', 'CustomerDeclined'], true)) {
            jsonError('Only declined auto-confirmed orders can be reset to Submitted.', 400);
        }

        self::detachOrderFromShipmentDrafts($pdo, $orderId);
        self::voidActiveReceipts($pdo, $orderId, $userId, $reason ?: 'Reset after customer decline');
        $pdo->prepare("UPDATE orders SET status='Submitted', confirmation_token=NULL WHERE id=?")->execute([$orderId]);
        $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('order',?,?,?,?)")
            ->execute([$orderId, 'reset_after_customer_decline', json_encode(['status' => 'Submitted', 'reason' => $reason], JSON_UNESCAPED_UNICODE), $userId]);
    }

    public static function fetchLatestReceipt(PDO $pdo, int $orderId): ?array
    {
        $sql = "SELECT * FROM warehouse_receipts WHERE order_id = ?";
        if (self::receiptHasColumn($pdo, 'voided_at')) {
            $sql .= " AND voided_at IS NULL";
        }
        $sql .= " ORDER BY received_at DESC, id DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function fetchOrder(PDO $pdo, int $orderId): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function detachOrderFromShipmentDrafts(PDO $pdo, int $orderId): void
    {
        $draftStmt = $pdo->prepare("SELECT shipment_draft_id FROM shipment_draft_orders WHERE order_id = ?");
        $draftStmt->execute([$orderId]);
        $draftIds = array_map('intval', array_column($draftStmt->fetchAll(PDO::FETCH_ASSOC), 'shipment_draft_id'));
        if ($draftIds) {
            $pdo->prepare("DELETE FROM shipment_draft_orders WHERE order_id = ?")->execute([$orderId]);
            foreach ($draftIds as $draftId) {
                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM shipment_draft_orders WHERE shipment_draft_id = ?");
                $countStmt->execute([$draftId]);
                if ((int) $countStmt->fetchColumn() === 0) {
                    $pdo->prepare("UPDATE shipment_drafts SET container_id = NULL WHERE id = ? AND status != 'finalized'")->execute([$draftId]);
                }
            }
        }
    }

    private static function voidActiveReceipts(PDO $pdo, int $orderId, int $userId, string $reason): void
    {
        if (!self::receiptHasColumn($pdo, 'voided_at')) {
            return;
        }
        $stmt = $pdo->prepare(
            "UPDATE warehouse_receipts
             SET voided_at = NOW(),
                 voided_by = ?,
                 void_reason = ?
             WHERE order_id = ?
               AND voided_at IS NULL"
        );
        $stmt->execute([$userId, $reason, $orderId]);
    }

    private static function insertCustomerConfirmation(PDO $pdo, int $orderId, ?int $confirmedBy, ?array $acceptedActuals, ?string $declineReason): void
    {
        $hasDeclinedAt = self::customerConfirmationHasColumn($pdo, 'declined_at');
        if ($declineReason !== null && $hasDeclinedAt) {
            $pdo->prepare(
                "INSERT INTO customer_confirmations (order_id, confirmed_by, confirmed_at, accepted_actuals, declined_at, decline_reason)
                 VALUES (?, ?, ?, ?, NOW(), ?)"
            )->execute([
                $orderId,
                $confirmedBy,
                $confirmedBy ? date('Y-m-d H:i:s') : null,
                $acceptedActuals ? json_encode($acceptedActuals, JSON_UNESCAPED_UNICODE) : null,
                $declineReason,
            ]);
            return;
        }

        $payload = $acceptedActuals ?? [];
        if ($declineReason !== null) {
            $payload = array_merge($payload, ['declined' => true, 'reason' => $declineReason]);
        }
        $pdo->prepare("INSERT INTO customer_confirmations (order_id, confirmed_by, accepted_actuals) VALUES (?, ?, ?)")
            ->execute([$orderId, $confirmedBy, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    }

    private static function receiptHasColumn(PDO $pdo, string $column): bool
    {
        return self::tableHasColumn($pdo, 'warehouse_receipts', $column);
    }

    private static function customerConfirmationHasColumn(PDO $pdo, string $column): bool
    {
        return self::tableHasColumn($pdo, 'customer_confirmations', $column);
    }

    private static function tableHasColumn(PDO $pdo, string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (array_key_exists($key, self::$columnCache)) {
            return self::$columnCache[$key];
        }

        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $stmt->execute([$column]);
            self::$columnCache[$key] = (bool) $stmt->rowCount();
        } catch (Throwable $e) {
            self::$columnCache[$key] = false;
        }

        return self::$columnCache[$key];
    }
}
