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
    // Migration 008: suppliers contact fields
    $cols = $pdo->query("SHOW COLUMNS FROM suppliers WHERE Field IN ('phone','additional_ids')")->fetchAll(PDO::FETCH_COLUMN);
    if (count($cols) !== 2) {
        throw new Exception('suppliers missing phone or additional_ids (run migration 008)');
    }
    echo "OK: suppliers.phone, suppliers.additional_ids\n";
    $pdo->query("SELECT 1 FROM tracking_push_log LIMIT 1");
    echo "OK: tracking_push_log\n";
    $pdo->query("SELECT 1 FROM warehouse_receipt_items LIMIT 1");
    echo "OK: warehouse_receipt_items\n";
    $pdo->query("SELECT 1 FROM user_notification_preferences LIMIT 1");
    echo "OK: user_notification_preferences\n";
    $pdo->query("SELECT 1 FROM notification_delivery_log LIMIT 1");
    echo "OK: notification_delivery_log\n";
    // Migration 026
    $pdo->query("SELECT 1 FROM product_description_entries LIMIT 1");
    echo "OK: product_description_entries\n";
    $cols = $pdo->query("SHOW COLUMNS FROM products WHERE Field IN ('pieces_per_carton','unit_price')")->fetchAll(PDO::FETCH_COLUMN);
    if (count($cols) !== 2) {
        throw new Exception('products missing pieces_per_carton or unit_price (run migration 026)');
    }
    echo "OK: products.pieces_per_carton, products.unit_price\n";
    $cols = $pdo->query("SHOW COLUMNS FROM customers WHERE Field = 'payment_links'")->fetchAll(PDO::FETCH_COLUMN);
    if (count($cols) !== 1) {
        throw new Exception('customers missing payment_links (run migration 026)');
    }
    echo "OK: customers.payment_links\n";
    $pdo->query("SELECT 1 FROM expense_categories LIMIT 1");
    echo "OK: expense_categories\n";
    $pdo->query("SELECT 1 FROM expenses LIMIT 1");
    echo "OK: expenses\n";
    $pdo->query("SELECT 1 FROM hs_code_tariff_catalog LIMIT 1");
    echo "OK: hs_code_tariff_catalog\n";
    echo "Smoke test passed.\n";
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    exit(1);
}
