<?php

/**
 * Config API - GET (SuperAdmin), PUT (SuperAdmin)
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    requireRole(['SuperAdmin']);

    $pdo = getDb();

    switch ($method) {
        case 'GET':
            $fileConfig = require dirname(__DIR__, 2) . '/backend/config/config.php';
            $stmt = @$pdo->query("SELECT key_name, key_value FROM system_config");
            if ($stmt) {
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $k = $r['key_name'];
                    if ($k === 'VARIANCE_THRESHOLD_PERCENT') $fileConfig['variance_threshold_percent'] = (float) $r['key_value'];
                    elseif ($k === 'VARIANCE_THRESHOLD_ABS_CBM') $fileConfig['variance_threshold_abs_cbm'] = (float) $r['key_value'];
                    elseif ($k === 'CONFIRMATION_REQUIRED') $fileConfig['confirmation_required'] = $r['key_value'];
                    elseif ($k === 'CUSTOMER_PHOTO_VISIBILITY') $fileConfig['customer_photo_visibility'] = $r['key_value'];
                    elseif ($k === 'MIN_PHOTOS_PER_ITEM') $fileConfig['min_photos_per_item'] = (int) $r['key_value'];
                    elseif ($k === 'NOTIFICATION_CHANNELS') $fileConfig['notification_channels'] = array_map('trim', explode(',', $r['key_value'] ?? 'dashboard'));
                    elseif ($k === 'TRACKING_API_BASE_URL') $fileConfig['tracking_api_base_url'] = trim($r['key_value'] ?? '');
                    elseif ($k === 'TRACKING_API_TOKEN') $fileConfig['tracking_api_token'] = trim($r['key_value'] ?? '');
                    elseif ($k === 'TRACKING_API_TIMEOUT_SEC') $fileConfig['tracking_api_timeout_sec'] = (int) $r['key_value'];
                    elseif ($k === 'TRACKING_API_RETRY_COUNT') $fileConfig['tracking_api_retry_count'] = (int) $r['key_value'];
                    elseif ($k === 'TRACKING_API_RETRY_BACKOFF_MS') $fileConfig['tracking_api_retry_backoff_ms'] = (int) $r['key_value'];
                    elseif ($k === 'TRACKING_PUSH_ENABLED') $fileConfig['tracking_push_enabled'] = (int) $r['key_value'];
                    elseif ($k === 'TRACKING_PUSH_DRY_RUN') $fileConfig['tracking_push_dry_run'] = (int) $r['key_value'];
                    elseif ($k === 'TRACKING_API_PATH') $fileConfig['tracking_api_path'] = trim($r['key_value'] ?? '/api/import/clms');
                }
            }
            if (!empty($fileConfig['tracking_api_token'])) {
                $fileConfig['tracking_api_token'] = '********';
                $fileConfig['tracking_api_token_set'] = true;
            } else {
                $fileConfig['tracking_api_token_set'] = false;
            }
            jsonResponse(['data' => $fileConfig]);
            break;

        case 'PUT':
            $updates = $input['config'] ?? $input;
            if (empty($updates)) jsonError('No config to update', 400);
            $allowed = ['VARIANCE_THRESHOLD_PERCENT', 'VARIANCE_THRESHOLD_ABS_CBM', 'CONFIRMATION_REQUIRED', 'CUSTOMER_PHOTO_VISIBILITY', 'MIN_PHOTOS_PER_ITEM', 'NOTIFICATION_CHANNELS', 'TRACKING_API_BASE_URL', 'TRACKING_API_TOKEN', 'TRACKING_API_TIMEOUT_SEC', 'TRACKING_API_RETRY_COUNT', 'TRACKING_API_RETRY_BACKOFF_MS', 'TRACKING_PUSH_ENABLED', 'TRACKING_PUSH_DRY_RUN', 'TRACKING_API_PATH'];
            $stmt = $pdo->prepare("INSERT INTO system_config (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
            foreach ($updates as $k => $v) {
                if (in_array($k, $allowed)) {
                    if ($k === 'TRACKING_API_TOKEN' && $v === '********') continue;
                    $stmt->execute([$k, (string) $v]);
                }
            }
            $stmt = $pdo->query("SELECT key_name, key_value FROM system_config");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $config = [];
            foreach ($rows as $r) $config[$r['key_name']] = $r['key_value'];
            jsonResponse(['data' => $config]);
            break;
    }

    jsonError('Method not allowed', 405);
};
