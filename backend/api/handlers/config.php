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
                }
            }
            jsonResponse(['data' => $fileConfig]);
            break;

        case 'PUT':
            $updates = $input['config'] ?? $input;
            if (empty($updates)) jsonError('No config to update', 400);
            $allowed = ['VARIANCE_THRESHOLD_PERCENT', 'VARIANCE_THRESHOLD_ABS_CBM', 'CONFIRMATION_REQUIRED', 'CUSTOMER_PHOTO_VISIBILITY'];
            $stmt = $pdo->prepare("INSERT INTO system_config (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
            foreach ($updates as $k => $v) {
                if (in_array($k, $allowed)) {
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
