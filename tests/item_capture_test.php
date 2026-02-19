<?php

/**
 * Item capture test - order_items new fields
 * Run: php tests/item_capture_test.php
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

test('Order items has item_no column', function () use ($pdo) {
    $stmt = $pdo->query("SHOW COLUMNS FROM order_items WHERE Field = 'item_no'");
    if (!$stmt->fetch()) throw new Exception('item_no column not found');
});

test('Order items has image_paths column', function () use ($pdo) {
    $stmt = $pdo->query("SHOW COLUMNS FROM order_items WHERE Field = 'image_paths'");
    if (!$stmt->fetch()) throw new Exception('image_paths column not found');
});

test('Suppliers has store_id column', function () use ($pdo) {
    $stmt = $pdo->query("SHOW COLUMNS FROM suppliers WHERE Field = 'store_id'");
    if (!$stmt->fetch()) throw new Exception('store_id column not found');
});

test('supplier_payments table exists', function () use ($pdo) {
    $pdo->query("SELECT 1 FROM supplier_payments LIMIT 1");
});

test('MIN_PHOTOS_PER_ITEM config exists', function () use ($pdo) {
    $stmt = $pdo->query("SELECT key_value FROM system_config WHERE key_name = 'MIN_PHOTOS_PER_ITEM'");
    if (!$stmt->fetch()) throw new Exception('MIN_PHOTOS_PER_ITEM not in system_config');
});

echo "\nTotal: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
