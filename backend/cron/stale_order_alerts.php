<?php

/**
 * Stale Order Alerts - cron job
 * Run daily: php backend/cron/stale_order_alerts.php
 * Or via cron: 0 8 * * * cd /path/to/cargochina && php backend/cron/stale_order_alerts.php
 *
 * Checks for orders stuck in customer feedback follow-up or overdue (past expected_ready_date)
 * beyond STALE_ORDER_THRESHOLD_DAYS, and sends dashboard notifications to admins.
 */

$rootDir = dirname(__DIR__, 2);
chdir($rootDir);
require_once $rootDir . '/backend/config/database.php';
require_once $rootDir . '/backend/config/config.php';
require_once $rootDir . '/backend/services/NotificationService.php';

$pdo = getDb();
if (!$pdo) {
    fwrite(STDERR, "Database connection failed\n");
    exit(1);
}

$threshold = (int) ($pdo->query("SELECT key_value FROM system_config WHERE key_name='STALE_ORDER_THRESHOLD_DAYS' LIMIT 1")->fetchColumn() ?: 3);

$receiptVoidFilter = '';
$hasVoidedAt = false;
try {
    $check = $pdo->prepare("SHOW COLUMNS FROM warehouse_receipts LIKE ?");
    $check->execute(['voided_at']);
    $hasVoidedAt = (bool) $check->rowCount();
} catch (Throwable $e) {
    $hasVoidedAt = false;
}
if ($hasVoidedAt) {
    $receiptVoidFilter = ' AND wr.voided_at IS NULL';
}

$staleConfirm = $pdo->prepare("SELECT COUNT(*) FROM orders o JOIN warehouse_receipts wr ON wr.order_id = o.id WHERE COALESCE(o.confirmation_token, '') <> ''$receiptVoidFilter AND wr.received_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
$staleConfirm->execute([$threshold]);
$feedbackPendingCount = (int) $staleConfirm->fetchColumn();

$staleApproved = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status IN ('Approved','InTransitToWarehouse') AND expected_ready_date < DATE_SUB(CURDATE(), INTERVAL ? DAY)");
$staleApproved->execute([$threshold]);
$overdueCount = (int) $staleApproved->fetchColumn();

if ($feedbackPendingCount > 0 || $overdueCount > 0) {
    (new NotificationService($pdo))->notifyStaleOrders($feedbackPendingCount, $overdueCount, $threshold);
    if (php_sapi_name() === 'cli') {
        echo "Stale order alert sent: $feedbackPendingCount customer-feedback pending, $overdueCount overdue\n";
    }
}
