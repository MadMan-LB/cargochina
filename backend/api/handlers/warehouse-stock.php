<?php

/**
 * Warehouse Stock API - current stock visibility
 * Roles: WarehouseStaff, ChinaAdmin, LebanonAdmin, SuperAdmin
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();
    if (!getAuthUserId()) jsonError('Unauthorized', 401);
    if (!hasAnyRole(['WarehouseStaff', 'ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'])) jsonError('Forbidden', 403);

    if ($method !== 'GET') jsonError('Method not allowed', 405);

    $customerId = $_GET['customer_id'] ?? null;
    $supplierId = $_GET['supplier_id'] ?? null;
    $containerId = $_GET['container_id'] ?? null;
    $statusParam = $_GET['status'] ?? null;
    $statuses = is_array($statusParam)
        ? array_values(array_filter(array_map('trim', $statusParam), 'strlen'))
        : (trim((string) $statusParam) !== '' ? [trim((string) $statusParam)] : []);
    $statusMode = strtolower(trim((string) ($_GET['status_mode'] ?? 'include')));
    $statusMode = $statusMode === 'exclude' ? 'exclude' : 'include';
    $q = trim($_GET['q'] ?? '');

    $sql = "SELECT o.id as order_id, o.customer_id, o.supplier_id, o.status, o.expected_ready_date,
        c.name as customer_name, s.name as supplier_name,
        oi.id as item_id, oi.product_id, oi.quantity, oi.unit, oi.declared_cbm, oi.declared_weight, oi.description_cn, oi.description_en,
        p.description_cn as product_desc_cn, p.description_en as product_desc_en,
        wr.actual_cbm as order_actual_cbm, wr.actual_weight as order_actual_weight, wr.actual_cartons as order_actual_cartons
        FROM orders o
        JOIN customers c ON o.customer_id = c.id
        LEFT JOIN suppliers s ON o.supplier_id = s.id
        JOIN order_items oi ON oi.order_id = o.id
        LEFT JOIN products p ON oi.product_id = p.id
        LEFT JOIN (SELECT w.order_id, w.actual_cbm, w.actual_weight, w.actual_cartons FROM warehouse_receipts w INNER JOIN (SELECT order_id, MAX(id) as mid FROM warehouse_receipts GROUP BY order_id) x ON w.order_id = x.order_id AND w.id = x.mid) wr ON wr.order_id = o.id
        WHERE o.status IN ('ReceivedAtWarehouse','AwaitingCustomerConfirmation','Confirmed','ReadyForConsolidation')";
    $params = [];
    if ($customerId) {
        $sql .= " AND o.customer_id = ?";
        $params[] = $customerId;
    }
    if ($supplierId) {
        $sql .= " AND o.supplier_id = ?";
        $params[] = $supplierId;
    }
    if ($containerId) {
        $sql .= " AND EXISTS (SELECT 1 FROM shipment_draft_orders sdo JOIN shipment_drafts sd ON sdo.shipment_draft_id = sd.id WHERE sdo.order_id = o.id AND sd.container_id = ?)";
        $params[] = $containerId;
    }
    if (!empty($statuses)) {
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $sql .= $statusMode === 'exclude'
            ? " AND o.status NOT IN ($placeholders)"
            : " AND o.status IN ($placeholders)";
        $params = array_merge($params, $statuses);
    }
    if ($q) {
        $like = '%' . $q . '%';
        $sql .= " AND (oi.description_cn LIKE ? OR oi.description_en LIKE ? OR c.name LIKE ? OR s.name LIKE ?)";
        $params = array_merge($params, [$like, $like, $like, $like]);
    }
    $sql .= " ORDER BY o.expected_ready_date IS NULL, o.expected_ready_date, o.id, oi.id";
    $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
    if ($params) $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(['data' => $rows]);
};
