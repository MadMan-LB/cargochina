<?php

/**
 * Business Settings API - ETA offsets, notification thresholds, etc.
 * SuperAdmin only
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();
    if (!getAuthUserId()) jsonError('Unauthorized', 401);
    if (!hasAnyRole(['SuperAdmin'])) jsonError('Forbidden', 403);

    switch ($method) {
        case 'GET':
            $stmt = $pdo->query("SELECT key_name, key_value FROM business_settings");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $data = [];
            foreach ($rows as $r) {
                $data[$r['key_name']] = $r['key_value'];
            }
            jsonResponse(['data' => $data]);

        case 'PUT':
        case 'POST':
            $config = $input['config'] ?? $input;
            if (empty($config)) jsonError('No config provided', 400);
            $allowed = ['ETA_OFFSETS_JSON', 'ARRIVAL_NOTIFY_DAYS', 'CONTAINER_20HQ_CBM', 'CONTAINER_40HQ_CBM', 'CONTAINER_45HQ_CBM', 'SHIPPING_CODE_DUPLICATE_ACTION'];
            if (isset($config['SHIPPING_CODE_DUPLICATE_ACTION']) && !in_array($config['SHIPPING_CODE_DUPLICATE_ACTION'], ['warn', 'block'], true)) {
                jsonError('SHIPPING_CODE_DUPLICATE_ACTION must be warn or block', 400);
            }
            foreach ($config as $k => $v) {
                if (!in_array($k, $allowed, true)) continue;
                $pdo->prepare("INSERT INTO business_settings (key_name, key_value) VALUES (?,?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)")
                    ->execute([$k, is_string($v) ? $v : json_encode($v)]);
            }
            $stmt = $pdo->query("SELECT key_name, key_value FROM business_settings");
            $data = [];
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $data[$r['key_name']] = $r['key_value'];
            jsonResponse(['data' => $data]);

        default:
            jsonError('Method not allowed', 405);
    }
};
