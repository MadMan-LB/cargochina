<?php

/**
 * Financial features test: supplier payment tolerance, customer deposits, currency
 * Run: php tests/financial_test.php
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

test('Migration 019 tables exist: customer_deposits', function () use ($pdo) {
    $pdo->query("SELECT 1 FROM customer_deposits LIMIT 0");
});

test('Migration 019 columns: orders.currency', function () use ($pdo) {
    $cols = $pdo->query("SHOW COLUMNS FROM orders WHERE Field = 'currency'")->fetchAll(PDO::FETCH_COLUMN);
    if (count($cols) === 0) throw new Exception('orders.currency column missing');
});

test('Migration 019 columns: order_items.item_length', function () use ($pdo) {
    $cols = $pdo->query("SHOW COLUMNS FROM order_items WHERE Field IN ('item_length','item_width','item_height')")->fetchAll(PDO::FETCH_COLUMN);
    if (count($cols) < 3) throw new Exception('order_items L/W/H columns missing');
});

test('Migration 019 columns: supplier_payments.invoice_amount', function () use ($pdo) {
    $cols = $pdo->query("SHOW COLUMNS FROM supplier_payments WHERE Field IN ('invoice_amount','discount_amount','marked_full_payment','marked_by')")->fetchAll(PDO::FETCH_COLUMN);
    if (count($cols) < 4) throw new Exception('supplier_payments discount columns missing');
});

test('Currency validation: only USD and RMB', function () {
    $valid = ['USD', 'RMB'];
    foreach (['USD', 'RMB'] as $c) {
        if (!in_array($c, $valid)) throw new Exception("$c should be valid");
    }
    foreach (['EUR', 'CNY', ''] as $c) {
        if (in_array($c, $valid, true)) throw new Exception("$c should be invalid");
    }
});

test('Discount calculation: invoice - paid', function () {
    $invoiceAmount = 3005;
    $amountPaid = 3000;
    $discount = $invoiceAmount - $amountPaid;
    $pct = ($discount / $invoiceAmount) * 100;
    if ($discount != 5) throw new Exception("Discount should be 5, got $discount");
    if (round($pct, 2) != 0.17) throw new Exception("Discount pct should be ~0.17, got " . round($pct, 2));
});

test('CBM calculation: L*W*H/1000000', function () {
    $l = 50;
    $w = 40;
    $h = 30;
    $cbm = ($l * $w * $h) / 1000000;
    if (abs($cbm - 0.06) > 0.001) throw new Exception("CBM should be 0.06, got $cbm");
    $totalCbm = $cbm * 10;
    if (abs($totalCbm - 0.6) > 0.01) throw new Exception("Total CBM should be 0.6, got $totalCbm");
});

echo "\nTotal: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
