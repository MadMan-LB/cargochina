<?php

/**
 * Smoke test - run after migrations
 * php tests/smoke_test.php
 */

$root = dirname(__DIR__);
require_once $root . '/backend/config/database.php';

try {
    $pdo = getDb();
    $tables = ['users', 'roles', 'customers', 'suppliers', 'products', 'orders', 'order_items'];
    foreach ($tables as $t) {
        $pdo->query("SELECT 1 FROM $t LIMIT 1");
        echo "OK: $t\n";
    }
    echo "Smoke test passed.\n";
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    exit(1);
}
