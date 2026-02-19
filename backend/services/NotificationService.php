<?php

/**
 * Notification Service - creates dashboard notifications
 * Phase 2: Notify admins at key workflow points
 */

class NotificationService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function notify(int $userId, string $type, string $title, ?string $body = null): void
    {
        $this->pdo->prepare("INSERT INTO notifications (user_id, type, title, body) VALUES (?,?,?,?)")
            ->execute([$userId, $type, $title, $body]);
    }

    /** Notify all users with ChinaAdmin, LebanonAdmin, or SuperAdmin */
    public function notifyAdmins(string $type, string $title, ?string $body = null): void
    {
        $stmt = $this->pdo->query("SELECT DISTINCT u.id FROM users u
            JOIN user_roles ur ON u.id = ur.user_id
            JOIN roles r ON ur.role_id = r.id
            WHERE r.code IN ('ChinaAdmin','LebanonAdmin','SuperAdmin') AND u.is_active = 1");
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $this->notify((int) $row[0], $type, $title, $body);
        }
    }

    public function notifyOrderCreated(int $orderId, int $userId): void
    {
        $this->notify($userId, 'order_created', 'Order #' . $orderId . ' created', 'New order has been created.');
    }

    public function notifyOrderSubmitted(int $orderId): void
    {
        $this->notifyAdmins('order_submitted', 'Order #' . $orderId . ' submitted', 'Order ready for approval.');
    }

    public function notifyOrderApproved(int $orderId): void
    {
        $this->notifyAdmins('order_approved', 'Order #' . $orderId . ' approved', 'Order approved for warehouse receiving.');
    }

    public function notifyOrderReceived(int $orderId, int $userId, bool $varianceDetected): void
    {
        $title = $varianceDetected ? 'Order #' . $orderId . ' — confirmation required' : 'Order #' . $orderId . ' received';
        $body = $varianceDetected ? 'CBM/weight variance detected. Customer confirmation required.' : 'Order received at warehouse.';
        $this->notifyAdmins('order_received', $title, $body);
    }

    public function notifyShipmentFinalized(int $shipmentDraftId, int $orderCount): void
    {
        $this->notifyAdmins(
            'shipment_finalized',
            'Shipment draft #' . $shipmentDraftId . ' finalized',
            $orderCount . ' order(s) pushed to tracking.'
        );
    }
}
