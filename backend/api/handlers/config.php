<?php

/**
 * Config API - GET (SuperAdmin), PUT (SuperAdmin)
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();
    if ($id !== 'receiving' && $id !== 'upload') {
        requireRole(['SuperAdmin']);
    }

    switch ($method) {
        case 'GET':
            if ($id === 'upload') {
                $fileConfig = require dirname(__DIR__, 2) . '/config/config.php';
                $stmt = @$pdo->query("SELECT key_name, key_value FROM system_config WHERE key_name IN ('UPLOAD_MAX_MB','UPLOAD_ALLOWED_TYPES')");
                if ($stmt) {
                    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        if ($r['key_name'] === 'UPLOAD_MAX_MB') $fileConfig['upload_max_mb'] = (float) $r['key_value'];
                        elseif ($r['key_name'] === 'UPLOAD_ALLOWED_TYPES') $fileConfig['upload_allowed_types'] = array_map('trim', explode(',', $r['key_value'] ?? ''));
                    }
                }
                jsonResponse(['data' => [
                    'max_upload_mb' => (float) ($fileConfig['upload_max_mb'] ?? 8),
                    'allowed_types' => $fileConfig['upload_allowed_types'] ?? ['jpg', 'jpeg', 'png', 'webp'],
                ]]);
                return;
            }
            if ($id === 'receiving') {
                $fileConfig = require dirname(__DIR__, 2) . '/config/config.php';
                $stmt = @$pdo->query("SELECT key_name, key_value FROM system_config WHERE key_name = 'ITEM_LEVEL_RECEIVING_ENABLED'");
                $val = 0;
                if ($stmt && $r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $val = (int) $r['key_value'];
                } else {
                    $val = (int) ($fileConfig['item_level_receiving_enabled'] ?? 0);
                }
                jsonResponse(['data' => ['item_level_receiving_enabled' => $val]]);
                return;
            }
            $fileConfig = require dirname(__DIR__, 2) . '/config/config.php';
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
                    elseif ($k === 'EMAIL_FROM_ADDRESS') $fileConfig['email_from_address'] = trim($r['key_value'] ?? '');
                    elseif ($k === 'EMAIL_FROM_NAME') $fileConfig['email_from_name'] = trim($r['key_value'] ?? '');
                    elseif ($k === 'WHATSAPP_API_URL') $fileConfig['whatsapp_api_url'] = trim($r['key_value'] ?? '');
                    elseif ($k === 'WHATSAPP_API_TOKEN') $fileConfig['whatsapp_api_token'] = trim($r['key_value'] ?? '');
                    elseif ($k === 'WHATSAPP_PROVIDER') $fileConfig['whatsapp_provider'] = $r['key_value'] ?? 'generic';
                    elseif ($k === 'WHATSAPP_TWILIO_ACCOUNT_SID') $fileConfig['whatsapp_twilio_account_sid'] = trim($r['key_value'] ?? '');
                    elseif ($k === 'WHATSAPP_TWILIO_AUTH_TOKEN') $fileConfig['whatsapp_twilio_auth_token'] = trim($r['key_value'] ?? '');
                    elseif ($k === 'WHATSAPP_TWILIO_FROM') $fileConfig['whatsapp_twilio_from'] = trim($r['key_value'] ?? '');
                    elseif ($k === 'WHATSAPP_TWILIO_TO') $fileConfig['whatsapp_twilio_to'] = trim($r['key_value'] ?? '');
                    elseif ($k === 'ITEM_LEVEL_RECEIVING_ENABLED') $fileConfig['item_level_receiving_enabled'] = (int) $r['key_value'];
                    elseif ($k === 'PHOTO_EVIDENCE_PER_ITEM') $fileConfig['photo_evidence_per_item'] = (int) $r['key_value'];
                    elseif ($k === 'NOTIFICATION_MAX_ATTEMPTS') $fileConfig['notification_max_attempts'] = (int) ($r['key_value'] ?? 3);
                    elseif ($k === 'NOTIFICATION_RETRY_SECONDS') $fileConfig['notification_retry_seconds'] = (int) ($r['key_value'] ?? 60);
                }
            }
            $provider = $fileConfig['whatsapp_provider'] ?? 'generic';
            $waUrl = trim($fileConfig['whatsapp_api_url'] ?? '');
            $waToken = trim($fileConfig['whatsapp_api_token'] ?? '');
            $waSid = trim($fileConfig['whatsapp_twilio_account_sid'] ?? '');
            $waAuth = trim($fileConfig['whatsapp_twilio_auth_token'] ?? '');
            $waFrom = trim($fileConfig['whatsapp_twilio_from'] ?? '');
            $waTo = trim($fileConfig['whatsapp_twilio_to'] ?? '');
            $fileConfig['whatsapp_available'] = ($provider === 'generic' && $waUrl !== '' && $waToken !== '')
                || ($provider === 'twilio' && $waSid !== '' && $waAuth !== '' && $waFrom !== '' && $waTo !== '');
            if (!empty($fileConfig['tracking_api_token'])) {
                $fileConfig['tracking_api_token'] = '********';
                $fileConfig['tracking_api_token_set'] = true;
            } else {
                $fileConfig['tracking_api_token_set'] = false;
            }
            if (!empty($fileConfig['whatsapp_api_token'] ?? '')) {
                $fileConfig['whatsapp_api_token'] = '********';
                $fileConfig['whatsapp_api_token_set'] = true;
            } else {
                $fileConfig['whatsapp_api_token_set'] = false;
            }
            if (!empty($fileConfig['whatsapp_twilio_auth_token'] ?? '')) {
                $fileConfig['whatsapp_twilio_auth_token'] = '********';
                $fileConfig['whatsapp_twilio_auth_token_set'] = true;
            } else {
                $fileConfig['whatsapp_twilio_auth_token_set'] = false;
            }
            jsonResponse(['data' => $fileConfig]);
            break;

        case 'PUT':
            $updates = $input['config'] ?? $input;
            if (empty($updates)) jsonError('No config to update', 400);
            $allowed = ['VARIANCE_THRESHOLD_PERCENT', 'VARIANCE_THRESHOLD_ABS_CBM', 'CONFIRMATION_REQUIRED', 'CUSTOMER_PHOTO_VISIBILITY', 'MIN_PHOTOS_PER_ITEM', 'NOTIFICATION_CHANNELS', 'TRACKING_API_BASE_URL', 'TRACKING_API_TOKEN', 'TRACKING_API_TIMEOUT_SEC', 'TRACKING_API_RETRY_COUNT', 'TRACKING_API_RETRY_BACKOFF_MS', 'TRACKING_PUSH_ENABLED', 'TRACKING_PUSH_DRY_RUN', 'TRACKING_API_PATH', 'EMAIL_FROM_ADDRESS', 'EMAIL_FROM_NAME', 'WHATSAPP_API_URL', 'WHATSAPP_API_TOKEN', 'WHATSAPP_PROVIDER', 'WHATSAPP_TWILIO_ACCOUNT_SID', 'WHATSAPP_TWILIO_AUTH_TOKEN', 'WHATSAPP_TWILIO_FROM', 'WHATSAPP_TWILIO_TO', 'ITEM_LEVEL_RECEIVING_ENABLED', 'PHOTO_EVIDENCE_PER_ITEM', 'NOTIFICATION_MAX_ATTEMPTS', 'NOTIFICATION_RETRY_SECONDS', 'UPLOAD_MAX_MB', 'UPLOAD_ALLOWED_TYPES'];
            $maskedKeys = ['TRACKING_API_TOKEN', 'WHATSAPP_API_TOKEN', 'WHATSAPP_TWILIO_AUTH_TOKEN'];
            $errors = [];
            foreach (['VARIANCE_THRESHOLD_PERCENT', 'VARIANCE_THRESHOLD_ABS_CBM'] as $k) {
                if (isset($updates[$k])) {
                    $v = (float) $updates[$k];
                    if ($k === 'VARIANCE_THRESHOLD_PERCENT' && ($v < 0 || $v > 100)) $errors[$k] = 'Must be 0–100';
                    if ($k === 'VARIANCE_THRESHOLD_ABS_CBM' && $v < 0) $errors[$k] = 'Must be ≥ 0';
                }
            }
            if (isset($updates['CUSTOMER_PHOTO_VISIBILITY']) && !in_array($updates['CUSTOMER_PHOTO_VISIBILITY'], ['internal-only', 'customer-visible'], true)) {
                $errors['CUSTOMER_PHOTO_VISIBILITY'] = 'Must be internal-only or customer-visible';
            }
            if (isset($updates['WHATSAPP_API_URL']) && $updates['WHATSAPP_API_URL'] !== '' && !preg_match('#^https?://.+#', $updates['WHATSAPP_API_URL'])) {
                $errors['WHATSAPP_API_URL'] = 'Must be valid HTTP(S) URL';
            }
            if (isset($updates['WHATSAPP_PROVIDER']) && !in_array($updates['WHATSAPP_PROVIDER'], ['generic', 'twilio'], true)) {
                $errors['WHATSAPP_PROVIDER'] = 'Must be generic or twilio';
            }
            if (isset($updates['NOTIFICATION_MAX_ATTEMPTS'])) {
                $n = (int) $updates['NOTIFICATION_MAX_ATTEMPTS'];
                if ($n < 1 || $n > 10) $errors['NOTIFICATION_MAX_ATTEMPTS'] = 'Must be 1–10';
            }
            if (isset($updates['NOTIFICATION_RETRY_SECONDS'])) {
                $n = (int) $updates['NOTIFICATION_RETRY_SECONDS'];
                if ($n < 1 || $n > 3600) $errors['NOTIFICATION_RETRY_SECONDS'] = 'Must be 1–3600';
            }
            if (isset($updates['UPLOAD_MAX_MB'])) {
                $m = (float) $updates['UPLOAD_MAX_MB'];
                if ($m < 0.5 || $m > 50) $errors['UPLOAD_MAX_MB'] = 'Must be 0.5–50';
            }
            if (isset($updates['UPLOAD_ALLOWED_TYPES'])) {
                $t = is_array($updates['UPLOAD_ALLOWED_TYPES']) ? $updates['UPLOAD_ALLOWED_TYPES'] : array_map('trim', explode(',', (string) $updates['UPLOAD_ALLOWED_TYPES']));
                $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                if (!empty(array_diff(array_map('strtolower', $t), $allowed))) $errors['UPLOAD_ALLOWED_TYPES'] = 'Only jpg,jpeg,png,webp,gif allowed';
            }
            if (!empty($errors)) {
                jsonError('Validation failed', 400, $errors);
            }
            $stmt = $pdo->prepare("INSERT INTO system_config (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
            foreach ($updates as $k => $v) {
                if (in_array($k, $allowed)) {
                    if (in_array($k, $maskedKeys) && $v === '********') continue;
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
