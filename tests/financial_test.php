<?php

/**
 * Financial features test: supplier payment tolerance, customer deposits, currency
 * Run: php tests/financial_test.php
 */

$root = dirname(__DIR__);
require_once $root . '/backend/config/database.php';
require_once $root . '/backend/api/helpers.php';
require_once $root . '/backend/services/DecimalMath.php';
require_once $root . '/backend/services/FinancialReconciliationService.php';

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

test('Migration 061 tables exist: balance_transactions', function () use ($pdo) {
    $pdo->query("SELECT 1 FROM balance_transactions LIMIT 0");
});

test('Migration 061/063 columns: balance_transactions employee ledger fields', function () use ($pdo) {
    $cols = $pdo->query("SHOW COLUMNS FROM balance_transactions WHERE Field IN ('party_type','party_id','transaction_type','amount','currency','payment_method','payment_account_label','payment_account_value','payment_account_qr_path','reference_number','notes','created_by','transaction_date','created_at')")->fetchAll(PDO::FETCH_COLUMN);
    if (count($cols) < 14) throw new Exception('balance_transactions ledger columns missing');
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

test('DecimalMath preserves exact financial precision', function () {
    $line = DecimalMath::multiply('3.0000', '0.1000');
    $remaining = DecimalMath::subtract($line, '0.1000');
    $roundedPositive = DecimalMath::multiply('12.3456', '0.1400');
    $roundedNegative = DecimalMath::round('-1.23455');
    if ($line !== '0.3000' || $remaining !== '0.2000' || $roundedPositive !== '1.7284' || $roundedNegative !== '-1.2346') {
        throw new Exception("Unexpected exact decimal result: $line / $remaining");
    }
});

test('Order financial reconciliation traces customer, supplier, and inventory sources', function () use ($pdo) {
    $customerId = (int) $pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn();
    $supplierId = (int) $pdo->query('SELECT id FROM suppliers ORDER BY id LIMIT 1')->fetchColumn();
    $userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
    if (!$customerId || !$supplierId || !$userId) throw new Exception('Seed customer, supplier, or user is missing');
    $orderId = 0;
    try {
        $pdo->prepare("INSERT INTO orders (customer_id,supplier_id,expected_ready_date,status,currency,created_by) VALUES (?,?,CURDATE(),'Approved','USD',?)")
            ->execute([$customerId,$supplierId,$userId]);
        $orderId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO order_items (order_id,supplier_id,quantity,unit,sell_price,buy_price,description_en) VALUES (?,?,3,'pieces',0.1000,0.0700,'Exact reconciliation item')")
            ->execute([$orderId,$supplierId]);
        $pdo->prepare("INSERT INTO customer_deposits (customer_id,order_id,amount,currency,payment_method,created_by) VALUES (?,?,0.1000,'USD','Cash',?)")
            ->execute([$customerId,$orderId,$userId]);
        $depositId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO supplier_payments (supplier_id,order_id,amount,currency,payment_type,invoice_amount,discount_amount) VALUES (?,?,0.1000,'USD','partial',0.2100,0.0000)")
            ->execute([$supplierId,$orderId]);
        $supplierPaymentId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO warehouse_receipts (order_id,actual_cartons,actual_cbm,actual_weight,receipt_condition,received_by,receiving_operation_id) VALUES (?,3,0.0300,2.5000,'good',?,?)")
            ->execute([$orderId,$userId,'financial-reconcile-' . $orderId]);
        $receiptId = (int) $pdo->lastInsertId();

        $report = (new FinancialReconciliationService($pdo))->reconcileOrder($orderId);
        if (($report['customer']['order_sell_value'] ?? '') !== '0.3000') throw new Exception('Customer order value did not reconcile to 0.3000');
        if (($report['customer']['reconciled_due_in_order_currency'] ?? '') !== '0.2000') throw new Exception('Customer remaining due did not reconcile to 0.2000');
        $supplier = $report['suppliers']['linked_payment_ledger'][0] ?? [];
        if (($supplier['balance'] ?? '') !== '0.1100') throw new Exception('Supplier remaining balance did not reconcile to 0.1100');
        if (($report['inventory']['active_receipts']['actual_cbm'] ?? '') !== '0.0300') throw new Exception('Receipt source was not traced');
    } finally {
        if ($orderId) {
            $pdo->exec("DELETE FROM warehouse_receipts WHERE order_id=$orderId");
            $pdo->exec("DELETE FROM supplier_payments WHERE order_id=$orderId");
            $pdo->exec("DELETE FROM customer_deposits WHERE order_id=$orderId");
            $pdo->exec("DELETE FROM balance_transactions WHERE order_id=$orderId");
            $pdo->exec("DELETE FROM order_items WHERE order_id=$orderId");
            $pdo->exec("DELETE FROM orders WHERE id=$orderId");
        }
    }
});

echo "\nTotal: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
