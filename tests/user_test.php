<?php

/**
 * User and UMS tests — schema, create flow
 * Run: php tests/user_test.php
 */

$root = dirname(__DIR__);
require_once $root . '/backend/config/database.php';

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

test('users table exists', function () use ($pdo) {
    $pdo->query("SELECT 1 FROM users LIMIT 0");
});

test('user create flow: insert user with roles', function () use ($pdo) {
    $email = 'test_ums_' . bin2hex(random_bytes(4)) . '@test.local';
    $hash = password_hash('test123', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (email, password_hash, full_name, is_active) VALUES (?, ?, ?, 1)")
        ->execute([$email, $hash, 'UMS Test User']);
    $userId = (int) $pdo->lastInsertId();
    if ($userId <= 0) throw new Exception('User insert failed');

    $roleId = $pdo->query("SELECT id FROM roles LIMIT 1")->fetchColumn();
    if ($roleId) {
        $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)")
            ->execute([$userId, $roleId]);
    }

    $stmt = $pdo->prepare("SELECT id, email, full_name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['email'] !== $email) throw new Exception('User fetch failed');

    $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$userId]);
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
});

test('audit_log idx_audit_user exists', function () use ($pdo) {
    $idx = $pdo->query("SHOW INDEX FROM audit_log WHERE Key_name = 'idx_audit_user'")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($idx)) throw new Exception('idx_audit_user not found');
});

test('user activity data sources queryable', function () use ($pdo) {
    $userId = (int) $pdo->query("SELECT id FROM users LIMIT 1")->fetchColumn();
    if ($userId <= 0) return;
    $pdo->prepare("SELECT a.id FROM audit_log a WHERE a.user_id = ? LIMIT 1")->execute([$userId]);
    $pdo->prepare("SELECT cc.id FROM customer_confirmations cc WHERE cc.confirmed_by = ? AND cc.confirmed_at IS NOT NULL LIMIT 1")->execute([$userId]);
    $pdo->prepare("SELECT id FROM orders WHERE created_by = ? LIMIT 1")->execute([$userId]);
});

echo "\nUser test: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
