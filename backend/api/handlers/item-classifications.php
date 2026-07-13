<?php

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 2) . '/services/ItemClassificationService.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();
    requirePermission('item-classifications.read', ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'ContainersStaff', 'FieldStaff', 'SuperAdmin']);
    if ($method === 'GET' && $id === 'types') {
        $rows = $pdo->query('SELECT code, label_en, label_zh FROM item_types WHERE is_active=1 ORDER BY sort_order, code')->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(['data' => $rows]);
    }
    if ($method === 'POST' && $id === 'suggest') {
        $service = new ItemClassificationService($pdo);
        jsonResponse(['data' => $service->suggest($input)]);
    }
    if ($method === 'PUT' && $id && $action === null) {
        requirePermission('item-classifications.write', ['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin']);
        $entityType = trim((string) ($input['entity_type'] ?? 'order_item'));
        $service = new ItemClassificationService($pdo);
        $service->set($entityType, (int) $id, (string) ($input['item_type_code'] ?? 'unclassified'), isset($input['confidence']) ? (float) $input['confidence'] : null, true, getAuthUserId(), 'manual');
        jsonResponse(['data' => $service->get($entityType, (int) $id)]);
    }
    jsonError('Method not allowed', 405);
};
