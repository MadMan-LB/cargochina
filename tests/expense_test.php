<?php

/**
 * Expense and expense category tests
 * Run: php tests/expense_test.php
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

test('expense_categories table exists', function () use ($pdo) {
    $pdo->query("SELECT 1 FROM expense_categories LIMIT 0");
});

test('expenses table exists', function () use ($pdo) {
    $pdo->query("SELECT 1 FROM expenses LIMIT 0");
});

test('expense category create-on-save flow: insert category then expense', function () use ($pdo) {
    $name = 'TestCategory_' . bin2hex(random_bytes(4));
    $code = 'test-' . bin2hex(random_bytes(4));
    $pdo->prepare("INSERT INTO expense_categories (code, name, category_type) VALUES (?, ?, 'operational')")
        ->execute([$code, $name]);
    $catId = (int) $pdo->lastInsertId();
    if ($catId <= 0) throw new Exception('Category insert failed');

    $userId = $pdo->query("SELECT id FROM users LIMIT 1")->fetchColumn();
    $userId = $userId ? (int) $userId : null;
    $pdo->prepare("INSERT INTO expenses (category_id, amount, currency, expense_date, created_by) VALUES (?, 10.50, 'USD', ?, ?)")
        ->execute([$catId, date('Y-m-d'), $userId]);

    $stmt = $pdo->prepare("SELECT e.id, e.amount, ec.name as category_name FROM expenses e JOIN expense_categories ec ON e.category_id = ec.id WHERE e.id = ?");
    $stmt->execute([$pdo->lastInsertId()]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['category_name'] !== $name) throw new Exception('Expense join failed');

    $pdo->prepare("DELETE FROM expenses WHERE category_id = ?")->execute([$catId]);
    $pdo->prepare("DELETE FROM expense_categories WHERE id = ?")->execute([$catId]);
});

test('expense categories code uniqueness enforced', function () use ($pdo) {
    $code = 'uniquetest-' . bin2hex(random_bytes(4));
    $pdo->prepare("INSERT INTO expense_categories (code, name, category_type) VALUES (?, 'Unique1', 'operational')")
        ->execute([$code]);
    $id1 = (int) $pdo->lastInsertId();
    try {
        $pdo->prepare("INSERT INTO expense_categories (code, name, category_type) VALUES (?, 'Unique2', 'operational')")
            ->execute([$code]);
        $pdo->prepare("DELETE FROM expense_categories WHERE id = ?")->execute([$pdo->lastInsertId()]);
        throw new Exception('Duplicate code should have failed');
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') === false) throw $e;
    }
    $pdo->prepare("DELETE FROM expense_categories WHERE id = ?")->execute([$id1]);
});

echo "\nTotal: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
