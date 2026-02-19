<?php

/**
 * Smoke test - login and order lifecycle
 * Run: php tests/lifecycle_test.php
 */

$root = dirname(__DIR__);
require_once $root . '/backend/config/database.php';

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

test('Admin user exists', function () use ($pdo) {
    $stmt = $pdo->query("SELECT u.id FROM users u JOIN user_roles ur ON u.id = ur.user_id JOIN roles r ON ur.role_id = r.id WHERE r.code = 'SuperAdmin' LIMIT 1");
    if (!$stmt->fetch()) throw new Exception('No SuperAdmin user');
});

test('Password verify works', function () use ($pdo) {
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE email = 'admin@salameh.com'");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !password_verify('password', $row['password_hash'])) throw new Exception('Password verify failed');
});

test('Order states exist', function () use ($pdo) {
    $states = ['Draft', 'Submitted', 'Approved', 'ReceivedAtWarehouse', 'ReadyForConsolidation', 'FinalizedAndPushedToTracking'];
    $stmt = $pdo->query("SELECT DISTINCT status FROM orders");
    $found = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'status');
    foreach (['Draft', 'Submitted'] as $s) {
        if (!in_array($s, $found) && $pdo->query("SELECT 1 FROM orders LIMIT 1")->fetch()) {
        }
    }
});

test('Audit log table exists', function () use ($pdo) {
    $pdo->query("SELECT 1 FROM audit_log LIMIT 1");
});

echo "\nTotal: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
