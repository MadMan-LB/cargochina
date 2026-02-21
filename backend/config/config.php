<?php

/**
 * CLMS Configuration - single source of truth for thresholds and policies
 */

$rootDir = dirname(__DIR__, 2);
if (file_exists($rootDir . '/.env')) {
    $lines = file($rootDir . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
        }
    }
}

$base = [
    'variance_threshold_percent' => (float) ($_ENV['VARIANCE_THRESHOLD_PERCENT'] ?? 10),
    'variance_threshold_abs_cbm' => (float) ($_ENV['VARIANCE_THRESHOLD_ABS_CBM'] ?? 0.1),
    'confirmation_required' => $_ENV['CONFIRMATION_REQUIRED'] ?? 'variance-only',
    'customer_photo_visibility' => $_ENV['CUSTOMER_PHOTO_VISIBILITY'] ?? 'internal-only',
    'min_photos_per_item' => (int) ($_ENV['MIN_PHOTOS_PER_ITEM'] ?? 1),
    'notification_channels' => array_map('trim', explode(',', $_ENV['NOTIFICATION_CHANNELS'] ?? 'dashboard')),
    'upload_max_mb' => (float) ($_ENV['UPLOAD_MAX_MB'] ?? 8),
    'upload_max_size' => (int) ($_ENV['UPLOAD_MAX_SIZE'] ?? (int)(($_ENV['UPLOAD_MAX_MB'] ?? 8) * 1048576)),
    'upload_allowed_types' => array_map('trim', explode(',', $_ENV['UPLOAD_ALLOWED_TYPES'] ?? 'jpg,jpeg,png,webp')),
    'upload_allowed_extensions' => array_map('trim', explode(',', $_ENV['UPLOAD_ALLOWED_TYPES'] ?? 'jpg,jpeg,png,webp')),
    'tracking_api_base_url' => trim($_ENV['TRACKING_API_BASE_URL'] ?? ''),
    'tracking_api_token' => trim($_ENV['TRACKING_API_TOKEN'] ?? ''),
    'tracking_api_timeout_sec' => (int) ($_ENV['TRACKING_API_TIMEOUT_SEC'] ?? 15),
    'tracking_api_retry_count' => (int) ($_ENV['TRACKING_API_RETRY_COUNT'] ?? 3),
    'tracking_api_retry_backoff_ms' => (int) ($_ENV['TRACKING_API_RETRY_BACKOFF_MS'] ?? 800),
    'tracking_push_enabled' => (int) ($_ENV['TRACKING_PUSH_ENABLED'] ?? 0),
    'tracking_push_dry_run' => (int) ($_ENV['TRACKING_PUSH_DRY_RUN'] ?? 1),
    'tracking_api_path' => trim($_ENV['TRACKING_API_PATH'] ?? '/api/import/clms'),
    'email_from_address' => trim($_ENV['EMAIL_FROM_ADDRESS'] ?? 'noreply@example.com'),
    'email_from_name' => trim($_ENV['EMAIL_FROM_NAME'] ?? 'CLMS'),
    'whatsapp_api_url' => trim($_ENV['WHATSAPP_API_URL'] ?? ''),
    'whatsapp_api_token' => trim($_ENV['WHATSAPP_API_TOKEN'] ?? ''),
    'whatsapp_provider' => $_ENV['WHATSAPP_PROVIDER'] ?? 'generic',
    'whatsapp_twilio_account_sid' => trim($_ENV['WHATSAPP_TWILIO_ACCOUNT_SID'] ?? ''),
    'whatsapp_twilio_auth_token' => trim($_ENV['WHATSAPP_TWILIO_AUTH_TOKEN'] ?? ''),
    'whatsapp_twilio_from' => trim($_ENV['WHATSAPP_TWILIO_FROM'] ?? ''),
    'whatsapp_twilio_to' => trim($_ENV['WHATSAPP_TWILIO_TO'] ?? ''),
    'item_level_receiving_enabled' => (int) ($_ENV['ITEM_LEVEL_RECEIVING_ENABLED'] ?? 0),
    'photo_evidence_per_item' => (int) ($_ENV['PHOTO_EVIDENCE_PER_ITEM'] ?? 0),
    'notification_max_attempts' => (int) ($_ENV['NOTIFICATION_MAX_ATTEMPTS'] ?? 3),
    'notification_retry_seconds' => (int) ($_ENV['NOTIFICATION_RETRY_SECONDS'] ?? 60),
];
try {
    require_once __DIR__ . '/database.php';
    $pdo = getDb();
    if ($pdo instanceof PDO) {
        $stmt = @$pdo->query("SELECT key_name, key_value FROM system_config");
        if ($stmt) {
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $k = $r['key_name'];
                if ($k === 'VARIANCE_THRESHOLD_PERCENT') $base['variance_threshold_percent'] = (float) $r['key_value'];
                elseif ($k === 'VARIANCE_THRESHOLD_ABS_CBM') $base['variance_threshold_abs_cbm'] = (float) $r['key_value'];
                elseif ($k === 'CONFIRMATION_REQUIRED') $base['confirmation_required'] = $r['key_value'];
                elseif ($k === 'CUSTOMER_PHOTO_VISIBILITY') $base['customer_photo_visibility'] = $r['key_value'];
                elseif ($k === 'MIN_PHOTOS_PER_ITEM') $base['min_photos_per_item'] = (int) $r['key_value'];
                elseif ($k === 'NOTIFICATION_CHANNELS') $base['notification_channels'] = array_map('trim', explode(',', $r['key_value'] ?? 'dashboard'));
                elseif ($k === 'TRACKING_API_BASE_URL') $base['tracking_api_base_url'] = trim($r['key_value'] ?? '');
                elseif ($k === 'TRACKING_API_TOKEN') $base['tracking_api_token'] = trim($r['key_value'] ?? '');
                elseif ($k === 'TRACKING_API_TIMEOUT_SEC') $base['tracking_api_timeout_sec'] = (int) $r['key_value'];
                elseif ($k === 'TRACKING_API_RETRY_COUNT') $base['tracking_api_retry_count'] = (int) $r['key_value'];
                elseif ($k === 'TRACKING_API_RETRY_BACKOFF_MS') $base['tracking_api_retry_backoff_ms'] = (int) $r['key_value'];
                elseif ($k === 'TRACKING_PUSH_ENABLED') $base['tracking_push_enabled'] = (int) $r['key_value'];
                elseif ($k === 'TRACKING_PUSH_DRY_RUN') $base['tracking_push_dry_run'] = (int) $r['key_value'];
                elseif ($k === 'TRACKING_API_PATH') $base['tracking_api_path'] = trim($r['key_value'] ?? '/api/import/clms');
                elseif ($k === 'EMAIL_FROM_ADDRESS') $base['email_from_address'] = trim($r['key_value'] ?? 'noreply@example.com');
                elseif ($k === 'EMAIL_FROM_NAME') $base['email_from_name'] = trim($r['key_value'] ?? 'CLMS');
                elseif ($k === 'WHATSAPP_API_URL') $base['whatsapp_api_url'] = trim($r['key_value'] ?? '');
                elseif ($k === 'WHATSAPP_API_TOKEN') $base['whatsapp_api_token'] = trim($r['key_value'] ?? '');
                elseif ($k === 'WHATSAPP_PROVIDER') $base['whatsapp_provider'] = $r['key_value'] ?? 'generic';
                elseif ($k === 'WHATSAPP_TWILIO_ACCOUNT_SID') $base['whatsapp_twilio_account_sid'] = trim($r['key_value'] ?? '');
                elseif ($k === 'WHATSAPP_TWILIO_AUTH_TOKEN') $base['whatsapp_twilio_auth_token'] = trim($r['key_value'] ?? '');
                elseif ($k === 'WHATSAPP_TWILIO_FROM') $base['whatsapp_twilio_from'] = trim($r['key_value'] ?? '');
                elseif ($k === 'WHATSAPP_TWILIO_TO') $base['whatsapp_twilio_to'] = trim($r['key_value'] ?? '');
                elseif ($k === 'ITEM_LEVEL_RECEIVING_ENABLED') $base['item_level_receiving_enabled'] = (int) $r['key_value'];
                elseif ($k === 'PHOTO_EVIDENCE_PER_ITEM') $base['photo_evidence_per_item'] = (int) $r['key_value'];
                elseif ($k === 'NOTIFICATION_MAX_ATTEMPTS') $base['notification_max_attempts'] = (int) ($r['key_value'] ?? 3);
                elseif ($k === 'NOTIFICATION_RETRY_SECONDS') $base['notification_retry_seconds'] = (int) ($r['key_value'] ?? 60);
                elseif ($k === 'UPLOAD_MAX_MB') $base['upload_max_mb'] = (float) $r['key_value'];
                elseif ($k === 'UPLOAD_ALLOWED_TYPES') $base['upload_allowed_types'] = array_map('trim', explode(',', $r['key_value'] ?? ''));
            }
        }
        if (isset($base['upload_max_mb'])) {
            $base['upload_max_size'] = (int) ($base['upload_max_mb'] * 1048576);
        }
        if (isset($base['upload_allowed_types'])) {
            $base['upload_allowed_extensions'] = $base['upload_allowed_types'];
        }
    }
} catch (Throwable $e) {
}
return $base;
