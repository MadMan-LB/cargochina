<?php

/**
 * Phase 2 integration test: item-level receive + variance + notification delivery log
 * Run: php tests/phase2_integration_test.php
 */

$root = dirname(__DIR__);
require_once $root . '/backend/config/database.php';
require_once $root . '/backend/services/NotificationService.php';

$pdo = getDb();
$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "PASS: $name\n";
        $passed++;
    } catch (Throwable $e) {
        echo "FAIL: $name - " . $e->getMessage() . "\n";
        $failed++;
    }
}

test('warehouse_receipt_items table exists and accepts inserts', function () use ($pdo) {
    $wr = $pdo->query("SELECT id FROM warehouse_receipts LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $oi = $pdo->query("SELECT id FROM order_items LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$wr || !$oi) return;
    $pdo->prepare("INSERT INTO warehouse_receipt_items (receipt_id, order_item_id, actual_cbm, actual_weight, variance_detected) VALUES (?,?,?,?,0)")
        ->execute([$wr['id'], $oi['id'], 1.5, 30]);
    $id = (int) $pdo->lastInsertId();
    $pdo->exec("DELETE FROM warehouse_receipt_items WHERE id = $id");
});

test('NotificationService creates dashboard notifications', function () use ($pdo) {
    $before = (int) $pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
    $svc = new NotificationService($pdo);
    $svc->notifyOrderSubmitted(1);
    $after = (int) $pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
    if ($after <= $before) throw new Exception('Expected new notification');
    $pdo->exec("DELETE FROM notifications WHERE type = 'order_submitted' AND title = 'Order #1 submitted'");
});

test('notification_delivery_log table exists', function () use ($pdo) {
    $pdo->query("SELECT 1 FROM notification_delivery_log LIMIT 1");
});

test('user_notification_preferences table accepts inserts', function () use ($pdo) {
    $u = $pdo->query("SELECT id FROM users LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$u) return;
    $pdo->prepare("INSERT INTO user_notification_preferences (user_id, channel, event_type, enabled) VALUES (?,?,?,1)")
        ->execute([$u['id'], 'email', 'order_received']);
    $pdo->prepare("DELETE FROM user_notification_preferences WHERE user_id = ? AND channel = 'email' AND event_type = 'order_received'")
        ->execute([$u['id']]);
});

echo "\nTotal: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
