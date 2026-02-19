<?php

/**
 * Tracking push idempotency test
 * Push same draft twice → when first succeeds, second is skipped
 * Run: php tests/tracking_push_idempotency_test.php
 */

$root = dirname(__DIR__);
require_once $root . '/backend/config/database.php';
require_once $root . '/backend/services/TrackingPushService.php';

$pdo = getDb();

$pdo->exec("INSERT INTO system_config (key_name, key_value) VALUES ('TRACKING_PUSH_DRY_RUN', '1') ON DUPLICATE KEY UPDATE key_value = '1'");
$pdo->exec("INSERT INTO system_config (key_name, key_value) VALUES ('TRACKING_PUSH_ENABLED', '0') ON DUPLICATE KEY UPDATE key_value = '0'");

$oid = $pdo->query("SELECT id FROM orders LIMIT 1")->fetchColumn();
if (!$oid) {
    $pdo->exec("INSERT INTO customers (code, name) VALUES ('TST', 'Test')");
    $cid = $pdo->lastInsertId();
    $pdo->exec("INSERT INTO suppliers (code, name) VALUES ('TST', 'Test')");
    $sid = $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO orders (customer_id, supplier_id, expected_ready_date, status) VALUES (?,?,CURDATE(),'AssignedToContainer')")->execute([$cid, $sid]);
    $oid = $pdo->lastInsertId();
}
$pdo->exec("INSERT INTO shipment_drafts (status) VALUES ('finalized')");
$draftId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT IGNORE INTO shipment_draft_orders (shipment_draft_id, order_id) VALUES (?,?)")->execute([$draftId, $oid]);

$svc = new TrackingPushService($pdo);
$r1 = $svc->push($draftId);
$pdo->prepare("UPDATE tracking_push_log SET status='success' WHERE entity_type='shipment_draft' AND entity_id=?")->execute([$draftId]);
$r2 = $svc->push($draftId);

$logs = $pdo->prepare("SELECT id, status FROM tracking_push_log WHERE entity_id = ?");
$logs->execute([$draftId]);
$rows = $logs->fetchAll(PDO::FETCH_ASSOC);

$pdo->prepare("DELETE FROM shipment_draft_orders WHERE shipment_draft_id = ?")->execute([$draftId]);
$pdo->prepare("DELETE FROM shipment_drafts WHERE id = ?")->execute([$draftId]);
$pdo->prepare("DELETE FROM tracking_push_log WHERE entity_id = ?")->execute([$draftId]);

if (count($rows) !== 1) {
    echo "FAIL: Expected 1 log row, got " . count($rows) . "\n";
    exit(1);
}
if (!isset($r2['message']) || strpos($r2['message'], 'idempotent') === false) {
    echo "FAIL: Second push should skip with idempotent message, got: " . json_encode($r2) . "\n";
    exit(1);
}
echo "PASS: Idempotency - second push skipped\n";
exit(0);
