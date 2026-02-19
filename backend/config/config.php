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

return [
    'variance_threshold_percent' => (float) ($_ENV['VARIANCE_THRESHOLD_PERCENT'] ?? 10),
    'variance_threshold_abs_cbm' => (float) ($_ENV['VARIANCE_THRESHOLD_ABS_CBM'] ?? 0.1),
    'confirmation_required' => $_ENV['CONFIRMATION_REQUIRED'] ?? 'variance-only',
    'customer_photo_visibility' => $_ENV['CUSTOMER_PHOTO_VISIBILITY'] ?? 'internal-only',
    'notification_channels' => array_map('trim', explode(',', $_ENV['NOTIFICATION_CHANNELS'] ?? 'dashboard')),
    'upload_max_size' => (int) ($_ENV['UPLOAD_MAX_SIZE'] ?? 5242880), // 5MB
    'upload_allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'],
];
