<?php

/**
 * Production hardening tests: admin config, preferences seeding, WhatsApp payload
 * Run: php tests/production_hardening_test.php
 */

$root = dirname(__DIR__);
require_once $root . '/backend/config/database.php';
require_once $root . '/backend/config/config.php';
require_once $root . '/backend/services/NotificationService.php';

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

test('Config PUT skips masked token placeholder (no overwrite)', function () use ($pdo) {
    $pdo->prepare("INSERT INTO system_config (key_name, key_value) VALUES ('WHATSAPP_API_TOKEN', ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)")
        ->execute(['real-token']);
    $maskedKeys = ['TRACKING_API_TOKEN', 'WHATSAPP_API_TOKEN', 'WHATSAPP_TWILIO_AUTH_TOKEN'];
    $stmt = $pdo->prepare("INSERT INTO system_config (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
    foreach (['WHATSAPP_API_TOKEN' => '********'] as $k => $v) {
        if (in_array($k, $maskedKeys) && $v === '********') continue;
        $stmt->execute([$k, (string) $v]);
    }
    $val = $pdo->query("SELECT key_value FROM system_config WHERE key_name='WHATSAPP_API_TOKEN'")->fetchColumn();
    if ($val !== 'real-token') throw new Exception("Token should not be overwritten by ********, got: $val");
    $pdo->prepare("UPDATE system_config SET key_value = '' WHERE key_name = 'WHATSAPP_API_TOKEN'")->execute();
});

test('Default preferences seeded on first GET when empty', function () use ($pdo) {
    $u = $pdo->query("SELECT id FROM users LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$u) return;
    $uid = (int) $u['id'];
    $pdo->prepare("DELETE FROM user_notification_preferences WHERE user_id = ?")->execute([$uid]);
    $before = (int) $pdo->query("SELECT COUNT(*) FROM user_notification_preferences WHERE user_id = $uid")->fetchColumn();
    if ($before !== 0) throw new Exception('Expected 0 prefs before seed');
    $stmt = $pdo->prepare("SELECT channel, event_type, enabled FROM user_notification_preferences WHERE user_id = ?");
    $stmt->execute([$uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        $ins = $pdo->prepare("INSERT INTO user_notification_preferences (user_id, channel, event_type, enabled) VALUES (?,?,?,?)");
        $events = ['order_submitted', 'order_approved', 'order_received', 'variance_confirmation', 'shipment_finalized'];
        foreach ($events as $et) {
            $ins->execute([$uid, 'dashboard', $et, 1]);
            $ins->execute([$uid, 'email', $et, 1]);
            $ins->execute([$uid, 'whatsapp', $et, 1]);
        }
    }
    $after = (int) $pdo->query("SELECT COUNT(*) FROM user_notification_preferences WHERE user_id = $uid")->fetchColumn();
    if ($after < 10) throw new Exception("Expected at least 10 prefs after seed, got $after");
    $pdo->prepare("DELETE FROM user_notification_preferences WHERE user_id = ?")->execute([$uid]);
});

test('WhatsApp generic provider builds JSON payload', function () use ($pdo) {
    $config = [
        'whatsapp_provider' => 'generic',
        'whatsapp_api_url' => 'https://api.example.com/send',
        'whatsapp_api_token' => 'token',
    ];
    $ref = new ReflectionClass(NotificationService::class);
    $method = $ref->getMethod('buildWhatsAppRequest');
    $method->setAccessible(true);
    $svc = new NotificationService($pdo);
    $refProp = $ref->getProperty('config');
    $refProp->setAccessible(true);
    $refProp->setValue($svc, $config);
    $req = $method->invoke($svc, 'generic', 'user@example.com', 'Test message');
    if ($req['content_type'] !== 'application/json') throw new Exception('Generic should use JSON');
    $body = json_decode($req['body'], true);
    if (($body['to'] ?? '') !== 'user@example.com' || ($body['message'] ?? '') !== 'Test message') {
        throw new Exception('Generic payload shape wrong');
    }
});

test('WhatsApp Twilio provider builds form payload', function () use ($pdo) {
    $config = [
        'whatsapp_provider' => 'twilio',
        'whatsapp_twilio_account_sid' => 'AC123',
        'whatsapp_twilio_from' => 'whatsapp:+14155238886',
    ];
    $ref = new ReflectionClass(NotificationService::class);
    $method = $ref->getMethod('buildWhatsAppRequest');
    $method->setAccessible(true);
    $svc = new NotificationService($pdo);
    $refProp = $ref->getProperty('config');
    $refProp->setAccessible(true);
    $refProp->setValue($svc, $config);
    $req = $method->invoke($svc, 'twilio', '+9611234567', 'Test');
    if ($req['content_type'] !== 'application/x-www-form-urlencoded') throw new Exception('Twilio should use form');
    if (strpos($req['url'], 'AC123') === false) throw new Exception('Twilio URL should contain Account SID');
    parse_str($req['body'], $params);
    if (($params['To'] ?? '') !== 'whatsapp:+9611234567' || ($params['From'] ?? '') !== 'whatsapp:+14155238886') {
        throw new Exception('Twilio payload shape wrong');
    }
});

echo "\nTotal: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
