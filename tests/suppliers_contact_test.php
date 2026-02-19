<?php

/**
 * Suppliers contact test - phone and additional_ids
 * Run: php tests/suppliers_contact_test.php
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

test('Suppliers table has phone column', function () use ($pdo) {
    $stmt = $pdo->query("SHOW COLUMNS FROM suppliers LIKE 'phone'");
    if (!$stmt->fetch()) throw new Exception('phone column not found');
});

test('Suppliers table has additional_ids column', function () use ($pdo) {
    $stmt = $pdo->query("SHOW COLUMNS FROM suppliers LIKE 'additional_ids'");
    if (!$stmt->fetch()) throw new Exception('additional_ids column not found');
});

test('Can insert supplier with phone and additional_ids', function () use ($pdo) {
    $pdo->prepare("INSERT INTO suppliers (code, name, phone, additional_ids) VALUES (?, ?, ?, ?)")
        ->execute(['TEST' . time(), 'Test Supplier', '+86 123 4567 8900', json_encode(['Tax ID' => '12345', 'VAT' => 'CN123'])]);
    $id = (int) $pdo->lastInsertId();
    $row = $pdo->prepare("SELECT phone, additional_ids FROM suppliers WHERE id = ?");
    $row->execute([$id]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if ($r['phone'] !== '+86 123 4567 8900') throw new Exception('Phone not stored');
    $ids = json_decode($r['additional_ids'], true);
    if (($ids['Tax ID'] ?? '') !== '12345') throw new Exception('additional_ids not stored');
    $pdo->prepare("DELETE FROM suppliers WHERE id = ?")->execute([$id]);
});

echo "\nTotal: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
