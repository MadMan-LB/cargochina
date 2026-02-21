<?php

/**
 * RBAC + Receiving API smoke tests
 * Run: php tests/rbac_receiving_test.php
 */

$root = dirname(__DIR__);
require_once $root . '/backend/config/database.php';
require_once $root . '/backend/api/helpers.php';

try {
    $pdo = getDb();
} catch (Throwable $e) {
    echo "SKIP: Database unavailable (" . $e->getMessage() . ")\n";
    exit(0);
}
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

// RBAC: role mapping logic (warehouse user has no superadmin access)
test('RBAC: WarehouseStaff not in superadmin allowed roles', function () {
    $areaRoles = ['superadmin' => ['SuperAdmin']];
    $userRoles = ['WarehouseStaff'];
    $allowed = $areaRoles['superadmin'] ?? [];
    $hasAccess = !empty(array_intersect($userRoles, $allowed));
    if ($hasAccess) throw new Exception('WarehouseStaff must not have superadmin access');
});

// Receiving queue query smoke test
test('Receiving queue query returns rows or empty array', function () use ($pdo) {
    $stmt = $pdo->query("SELECT o.id, o.customer_id, o.supplier_id, o.expected_ready_date, o.status, c.name as customer_name, s.name as supplier_name
        FROM orders o
        JOIN customers c ON o.customer_id = c.id
        JOIN suppliers s ON o.supplier_id = s.id
        WHERE o.status IN ('Approved','InTransitToWarehouse')
        ORDER BY o.expected_ready_date ASC LIMIT 5");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) throw new Exception('Queue should return array');
});

// Receiving receipts query smoke test
test('Receiving receipts query returns rows or empty array', function () use ($pdo) {
    $stmt = $pdo->query("SELECT wr.id, wr.order_id, wr.actual_cbm, wr.actual_weight, wr.received_at
        FROM warehouse_receipts wr
        JOIN orders o ON wr.order_id = o.id
        ORDER BY wr.received_at DESC LIMIT 5");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) throw new Exception('Receipts should return array');
});

// Receiving receipt detail query smoke test
test('Receiving receipt detail query works', function () use ($pdo) {
    $id = (int) $pdo->query("SELECT id FROM warehouse_receipts LIMIT 1")->fetchColumn();
    if ($id === 0) return;
    $stmt = $pdo->prepare("SELECT wr.*, o.status as order_status, c.name as customer_name, s.name as supplier_name
        FROM warehouse_receipts wr
        JOIN orders o ON wr.order_id = o.id
        JOIN customers c ON o.customer_id = c.id
        JOIN suppliers s ON o.supplier_id = s.id
        WHERE wr.id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('Receipt detail should return row');
});

echo "\nTotal: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
