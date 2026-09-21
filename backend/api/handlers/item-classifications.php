<?php

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 2) . '/services/ItemClassificationService.php';
require_once dirname(__DIR__, 2) . '/services/OrderWriteService.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    require_once __DIR__ . '/../authorization.php';
    clmsAuthorizeApiRequest('item-classifications', $method, $id, $action);
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
        $entityType = $input['entity_type'] ?? 'order_item';
        if (!in_array($entityType,['order_item','product'],true)) jsonError('Unsupported classification entity',422);
        if (!is_string($input['item_type_code'] ?? null)) jsonError('Item type is required',422);
        $service = new ItemClassificationService($pdo);
        if ($service->normalize($input['item_type_code'])==='unclassified' && strtolower(trim($input['item_type_code']))!=='unclassified') jsonError('Invalid item type',422);
        $confidence=OrderWriteService::number($input['confidence']??null,'Classification confidence',false,4,1);
        requirePermission($entityType==='product'?'products.write':'orders.write');
        $pdo->beginTransaction();
        register_shutdown_function(static function()use($pdo){if($pdo->inTransaction())$pdo->rollBack();});
        if ($entityType==='order_item') {
            $s=$pdo->prepare('SELECT order_id FROM order_items WHERE id=?');$s->execute([$id]);$orderId=$s->fetchColumn();
            if (!$orderId) jsonError('Order item not found',404);
            OrderWriteService::lockMutableProcurement($pdo,(int)$orderId);
        }
        $table=$entityType==='product'?'products':'order_items';
        $s=$pdo->prepare("SELECT id FROM $table WHERE id=? FOR UPDATE");$s->execute([$id]);if(!$s->fetchColumn())jsonError('Classification entity not found',404);
        $service = new ItemClassificationService($pdo);
        $before=$service->get($entityType,(int)$id);
        $service->set($entityType, (int) $id, $input['item_type_code'], $confidence, true, getAuthUserId(), 'manual');
        $saved=$service->get($entityType,(int)$id);
        $pdo->prepare("INSERT INTO audit_log(entity_type,entity_id,action,old_value,new_value,user_id) VALUES ('item_classification',?,'update',?,?,?)")->execute([$id,json_encode($before),json_encode($saved),getAuthUserId()]);
        $pdo->commit();
        jsonResponse(['data' => $saved]);
    }
    jsonError('Method not allowed', 405);
};
