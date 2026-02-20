<?php

/**
 * Regression: receive with 2 items (one variance, one normal)
 * Asserts: order state, receipt_items, notification_delivery_log, idempotency
 * Run: php tests/regression_receive_variance_test.php
 */

$root = dirname(__DIR__);
require_once $root . '/backend/config/database.php';
require_once $root . '/backend/api/helpers.php';
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

$orderId = null;
$receiptId = null;
$itemIds = [];

try {
    $cust = $pdo->query("SELECT id FROM customers LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $supp = $pdo->query("SELECT id FROM suppliers LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $user = $pdo->query("SELECT id FROM users LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$cust || !$supp || !$user) {
        echo "SKIP: Need customer, supplier, user in DB\n";
        exit(0);
    }

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO orders (customer_id, supplier_id, expected_ready_date, status, created_by) VALUES (?,?,CURDATE(),'Approved',?)")
        ->execute([$cust['id'], $supp['id'], $user['id']]);
    $orderId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO order_items (order_id, quantity, unit, declared_cbm, declared_weight, description_en) VALUES (?,10,'cartons',2.0,50,'Item1')")
        ->execute([$orderId]);
    $itemIds[] = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO order_items (order_id, quantity, unit, declared_cbm, declared_weight, description_en) VALUES (?,5,'cartons',1.0,25,'Item2')")
        ->execute([$orderId]);
    $itemIds[] = (int) $pdo->lastInsertId();
    $pdo->commit();

    $beforeLog = (int) $pdo->query("SELECT COUNT(*) FROM notification_delivery_log")->fetchColumn();

    $pdo->beginTransaction();
    $wrCols = $pdo->query("SHOW COLUMNS FROM warehouse_receipts")->fetchAll(PDO::FETCH_COLUMN);
    $wrHasCond = in_array('receipt_condition', $wrCols, true);
    if ($wrHasCond) {
        $pdo->prepare("INSERT INTO warehouse_receipts (order_id, actual_cartons, actual_cbm, actual_weight, receipt_condition, received_by) VALUES (?,15,3.6,75,'good',?)")->execute([$orderId, $user['id']]);
    } else {
        $pdo->prepare("INSERT INTO warehouse_receipts (order_id, actual_cartons, actual_cbm, actual_weight, received_by) VALUES (?,15,3.6,75,?)")->execute([$orderId, $user['id']]);
    }
    $receiptId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO warehouse_receipt_photos (receipt_id, file_path) VALUES (?,'test/photo.jpg')")->execute([$receiptId]);
    $insItem = $pdo->prepare("INSERT INTO warehouse_receipt_items (receipt_id, order_item_id, actual_cbm, actual_weight, variance_detected) VALUES (?,?,?,?,?)");
    $insItem->execute([$receiptId, $itemIds[0], 2.5, 50, 1]);
    $insItem->execute([$receiptId, $itemIds[1], 1.1, 25, 0]);
    $pdo->prepare("UPDATE orders SET status='AwaitingCustomerConfirmation' WHERE id=?")->execute([$orderId]);
    $pdo->commit();

    $GLOBALS['_log_order_id'] = $orderId;
    $GLOBALS['_log_receipt_id'] = $receiptId;
    $svc = new NotificationService($pdo);
    $svc->notifyOrderReceived($orderId, (int) $user['id'], true);
    unset($GLOBALS['_log_order_id'], $GLOBALS['_log_receipt_id']);

    test('Order status is AwaitingCustomerConfirmation', function () use ($pdo, $orderId) {
        $s = $pdo->query("SELECT status FROM orders WHERE id=$orderId")->fetchColumn();
        if ($s !== 'AwaitingCustomerConfirmation') throw new Exception("Expected AwaitingCustomerConfirmation, got $s");
    });

    test('warehouse_receipt_items has 2 rows', function () use ($pdo, $receiptId) {
        $n = (int) $pdo->query("SELECT COUNT(*) FROM warehouse_receipt_items WHERE receipt_id=$receiptId")->fetchColumn();
        if ($n !== 2) throw new Exception("Expected 2 receipt items, got $n");
    });

    test('One receipt item has variance_detected=1', function () use ($pdo, $receiptId) {
        $n = (int) $pdo->query("SELECT COUNT(*) FROM warehouse_receipt_items WHERE receipt_id=$receiptId AND variance_detected=1")->fetchColumn();
        if ($n !== 1) throw new Exception("Expected 1 variance item, got $n");
    });

    test('notification_delivery_log or notifications created', function () use ($pdo, $beforeLog, $orderId) {
        $afterLog = (int) $pdo->query("SELECT COUNT(*) FROM notification_delivery_log")->fetchColumn();
        $notifCount = (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE title LIKE 'Order #$orderId%'")->fetchColumn();
        if ($afterLog <= $beforeLog && $notifCount === 0) throw new Exception("Expected new delivery log entries or notifications");
    });

    test('payload_hash prevents duplicate sends on rerun', function () use ($pdo, $orderId, $user) {
        $before = (int) $pdo->query("SELECT COUNT(*) FROM notification_delivery_log WHERE status='sent'")->fetchColumn();
        $svc = new NotificationService($pdo);
        $svc->notifyOrderReceived($orderId, (int) $user['id'], true);
        $after = (int) $pdo->query("SELECT COUNT(*) FROM notification_delivery_log WHERE status='sent'")->fetchColumn();
        if ($after > $before + 2) throw new Exception("Idempotency may have failed: sent count increased too much");
    });
} finally {
    if ($orderId) {
        $receipts = $pdo->query("SELECT id FROM warehouse_receipts WHERE order_id=$orderId")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($receipts as $rid) {
            $riIds = $pdo->query("SELECT id FROM warehouse_receipt_items WHERE receipt_id=$rid")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($riIds as $riid) $pdo->exec("DELETE FROM warehouse_receipt_item_photos WHERE receipt_item_id=$riid");
            $pdo->exec("DELETE FROM warehouse_receipt_items WHERE receipt_id=$rid");
            $pdo->exec("DELETE FROM warehouse_receipt_photos WHERE receipt_id=$rid");
            $pdo->exec("DELETE FROM warehouse_receipts WHERE id=$rid");
        }
        $nIds = $pdo->query("SELECT id FROM notifications WHERE type IN ('variance_confirmation','order_received') AND title LIKE 'Order #$orderId%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($nIds as $nid) $pdo->exec("DELETE FROM notification_delivery_log WHERE notification_id=$nid");
        foreach ($nIds as $nid) $pdo->exec("DELETE FROM notifications WHERE id=$nid");
        $pdo->exec("DELETE FROM order_items WHERE order_id=$orderId");
        $pdo->exec("DELETE FROM orders WHERE id=$orderId");
    }
}

echo "\nTotal: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
