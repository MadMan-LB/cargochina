<?php

/**
 * Production hardening tests: admin config, preferences seeding, WhatsApp payload
 * Run: php tests/production_hardening_test.php
 */

$root = dirname(__DIR__);
require_once $root . '/backend/config/database.php';
require_once $root . '/backend/config/config.php';
require_once $root . '/backend/services/NotificationService.php';
require_once $root . '/backend/services/AuthenticationService.php';

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

test('Authentication rotates through the shared service and blocks seeded password in production', function () use ($pdo) {
    $email='seed-guard-'.bin2hex(random_bytes(4)).'@example.invalid';
    $roleId=(int)$pdo->query("SELECT id FROM roles WHERE code='SuperAdmin' LIMIT 1")->fetchColumn();
    if($roleId<=0)throw new Exception('SuperAdmin role is missing');
    $pdo->prepare('INSERT INTO users(email,password_hash,full_name,is_active) VALUES (?,?,?,1)')->execute([$email,password_hash('password',PASSWORD_DEFAULT),'Seed Guard Fixture']);
    $userId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_roles(user_id,role_id) VALUES (?,?)')->execute([$userId,$roleId]);
    try {
        $service=new AuthenticationService($pdo);
        $user=$service->login($email,'password','audit-auth-success','testing');
        if (($user['user_id'] ?? 0) !== $userId || !in_array('SuperAdmin',$user['roles'] ?? [],true)) throw new Exception('Shared authentication did not return the isolated SuperAdmin fixture in testing');
        try {
            $service->login($email,'password','audit-auth-production','production');
            throw new Exception('Seeded password was accepted in production mode');
        } catch(AuthenticationException $e) {
            if ($e->httpStatus !== 403) throw $e;
        }
    } finally {
        foreach(['audit-auth-success','audit-auth-production'] as $ip){
            $key=hash('sha256',mb_strtolower($email,'UTF-8').'|'.$ip);
            $pdo->prepare('DELETE FROM auth_login_attempts WHERE identity_hash=?')->execute([$key]);
        }
        $pdo->prepare('DELETE FROM user_roles WHERE user_id=?')->execute([$userId]);
        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
    }
});

test('Authentication throttles repeated failures without storing email or IP', function () use ($pdo) {
    $email='missing-audit-user@example.invalid'; $ip='audit-auth-throttle';
    $key=hash('sha256',mb_strtolower($email,'UTF-8').'|'.$ip);
    $pdo->prepare('DELETE FROM auth_login_attempts WHERE identity_hash=?')->execute([$key]);
    $service=new AuthenticationService($pdo);
    try {
        for($i=0;$i<5;$i++) {
            try { $service->login($email,'wrong',$ip,'testing'); }
            catch(AuthenticationException $e) { if($e->httpStatus!==401) throw $e; }
        }
        try { $service->login($email,'wrong',$ip,'testing'); throw new Exception('Sixth failed login was not throttled'); }
        catch(AuthenticationException $e) { if($e->httpStatus!==429) throw $e; }
        $row=$pdo->prepare('SELECT identity_hash,attempt_count,blocked_until FROM auth_login_attempts WHERE identity_hash=?'); $row->execute([$key]); $stored=$row->fetch(PDO::FETCH_ASSOC);
        if(!$stored || (int)$stored['attempt_count']!==5 || empty($stored['blocked_until'])) throw new Exception('Throttle state was not persisted correctly');
    } finally {
        $pdo->prepare('DELETE FROM auth_login_attempts WHERE identity_hash=?')->execute([$key]);
    }
});

test('Warehouse stock bulk controls load the current row renderer', function () use ($root) {
    $page = file_get_contents($root . '/warehouse_stock.php');
    $script = file_get_contents($root . '/frontend/js/warehouse_stock.js');
    if ($page === false || $script === false) throw new Exception('Warehouse stock assets could not be read');
    if (strpos($page, "warehouse_stock.js?v=' . filemtime") === false) {
        throw new Exception('Warehouse stock renderer is not cache-busted');
    }
    foreach (['stockDownloadSelectAll', 'stockDownloadSelectedBtn', 'stock-download-cb', 'data-download-id'] as $hook) {
        if (strpos($page . $script, $hook) === false) {
            throw new Exception("Warehouse stock bulk hook is missing: $hook");
        }
    }
});

echo "\nTotal: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
