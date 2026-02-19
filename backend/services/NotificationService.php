<?php

/**
 * Notification Service - creates dashboard notifications
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

    public function notifyOrderCreated(int $orderId, int $userId): void
    {
        $this->notify($userId, 'order_created', 'Order #' . $orderId . ' created', 'New order has been created.');
    }

    public function notifyOrderReceived(int $orderId, int $userId, bool $varianceDetected): void
    {
        $title = $varianceDetected ? 'Order #' . $orderId . ' — confirmation required' : 'Order #' . $orderId . ' received';
        $body = $varianceDetected ? 'CBM/weight variance detected. Customer confirmation required.' : 'Order received at warehouse.';
        $this->notify($userId, 'order_received', $title, $body);
    }
}
