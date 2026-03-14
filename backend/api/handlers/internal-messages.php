<?php

/**
 * Internal Messages API - chat between staff and customer context
 * Roles: ChinaAdmin, ChinaEmployee, LebanonAdmin, SuperAdmin, WarehouseStaff
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();
    $userId = getAuthUserId();
    if (!$userId) jsonError('Unauthorized', 401);
    if (!hasAnyRole(['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'SuperAdmin', 'WarehouseStaff'])) jsonError('Forbidden', 403);

    switch ($method) {
        case 'GET':
            $customerId = $_GET['customer_id'] ?? null;
            $orderId = $_GET['order_id'] ?? null;
            if (!$customerId) jsonError('customer_id required', 400);
            $sql = "SELECT m.*, u.full_name as sender_name FROM internal_messages m LEFT JOIN users u ON m.sender_id = u.id WHERE m.customer_id = ?";
            $params = [$customerId];
            if ($orderId) {
                $sql .= " AND (m.order_id = ? OR m.order_id IS NULL)";
                $params[] = $orderId;
            }
            $sql .= " ORDER BY m.created_at ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $pdo->prepare("UPDATE internal_messages SET read_at = NOW() WHERE customer_id = ? AND read_at IS NULL AND sender_id != ?")->execute([$customerId, $userId]);
            jsonResponse(['data' => $rows]);

        case 'POST':
            $customerId = (int) ($input['customer_id'] ?? 0);
            if (!$customerId) jsonError('customer_id required', 400);
            $body = trim($input['body'] ?? '');
            if (strlen($body) < 1) jsonError('Message body required', 400);
            $orderId = !empty($input['order_id']) ? (int) $input['order_id'] : null;
            $containerId = !empty($input['container_id']) ? (int) $input['container_id'] : null;
            $pdo->prepare("INSERT INTO internal_messages (customer_id, order_id, container_id, sender_id, body) VALUES (?,?,?,?,?)")
                ->execute([$customerId, $orderId, $containerId, $userId, $body]);
            $newId = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('internal_message', ?, 'create', ?, ?)")
                ->execute([$newId, json_encode(['customer_id' => $customerId, 'order_id' => $orderId, 'container_id' => $containerId]), $userId]);
            $stmt = $pdo->prepare("SELECT m.*, u.full_name as sender_name FROM internal_messages m LEFT JOIN users u ON m.sender_id = u.id WHERE m.id = ?");
            $stmt->execute([$newId]);
            jsonResponse(['data' => $stmt->fetch(PDO::FETCH_ASSOC)], 201);

        default:
            jsonError('Method not allowed', 405);
    }
};
