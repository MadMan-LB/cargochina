<?php

/**
 * Warehouse Stock API - current stock visibility
 * Roles: WarehouseStaff, ChinaAdmin, LebanonAdmin, ContainersStaff, SuperAdmin
 */

require_once __DIR__ . '/../helpers.php';

function warehouseStockHasColumn(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        $cache[$key] = (bool) $stmt->rowCount();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function warehouseStockOutputCsv(array $rows, ?string $filename = null): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . ($filename ?: ('warehouse_stock_' . date('Y-m-d') . '.csv')) . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Order ID', 'Customer', 'Supplier', 'Status', 'Item', 'Shipping Code', 'Item No', 'Quantity', 'Declared CBM', 'Actual CBM', 'Actual Weight', 'Actual Height', 'Actual Width', 'Actual Length']);
    foreach ($rows as $row) {
        fputcsv($out, [
            (int) ($row['order_id'] ?? 0),
            (string) ($row['customer_name'] ?? ''),
            (string) ($row['supplier_name'] ?? ''),
            function_exists('clmsStatusLabel') ? clmsStatusLabel((string) ($row['status'] ?? '')) : (string) ($row['status'] ?? ''),
            (string) (($row['description_en'] ?? '') ?: ($row['description_cn'] ?? '') ?: ($row['product_desc_en'] ?? '') ?: ($row['product_desc_cn'] ?? '')),
            (string) ($row['shipping_code'] ?? ''),
            (string) ($row['item_no'] ?? ''),
            $row['quantity'] ?? null,
            $row['declared_cbm'] ?? null,
            $row['item_actual_cbm'] ?? $row['order_actual_cbm'] ?? null,
            $row['item_actual_weight'] ?? $row['order_actual_weight'] ?? null,
            $row['item_actual_height'] ?? $row['height'] ?? $row['item_height'] ?? null,
            $row['item_actual_width'] ?? $row['width'] ?? $row['item_width'] ?? null,
            $row['item_actual_length'] ?? $row['length'] ?? $row['item_length'] ?? null,
        ]);
    }
    fclose($out);
    exit;
}

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();
    if (!getAuthUserId()) jsonError('Unauthorized', 401);
    if (!hasAnyRole(['WarehouseStaff', 'ChinaAdmin', 'LebanonAdmin', 'ContainersStaff', 'SuperAdmin'])) jsonError('Forbidden', 403);

    if ($method !== 'GET') jsonError('Method not allowed', 405);
    if ($id !== null && $id !== 'export') jsonError('Not found', 404);

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

    $receiptHasVoidedAt = warehouseStockHasColumn($pdo, 'warehouse_receipts', 'voided_at');
    $latestReceiptInnerWhere = $receiptHasVoidedAt
        ? " WHERE voided_at IS NULL"
        : "";
    $latestReceiptOuterWhere = $receiptHasVoidedAt
        ? " WHERE w.voided_at IS NULL"
        : "";

    $orderItemDimensionCols = '';
    foreach (['item_length', 'item_width', 'item_height'] as $column) {
        $orderItemDimensionCols .= warehouseStockHasColumn($pdo, 'order_items', $column)
            ? ", oi.$column"
            : ", NULL as $column";
    }
    foreach (['height', 'width', 'length'] as $column) {
        $orderItemDimensionCols .= warehouseStockHasColumn($pdo, 'order_items', $column)
            ? ", oi.$column"
            : ", NULL as $column";
    }
    $orderItemMetaCols = '';
    foreach (['shipping_code', 'item_no'] as $column) {
        $orderItemMetaCols .= warehouseStockHasColumn($pdo, 'order_items', $column)
            ? ", oi.$column"
            : ", NULL as $column";
    }
    $hasReceiptItems = warehouseStockHasColumn($pdo, 'warehouse_receipt_items', 'order_item_id');
    $receiptItemCols = '';
    foreach (['actual_cbm', 'actual_weight', 'actual_height', 'actual_width', 'actual_length'] as $column) {
        $receiptItemCols .= ($hasReceiptItems && warehouseStockHasColumn($pdo, 'warehouse_receipt_items', $column))
            ? ", wri.$column as item_$column"
            : ", NULL as item_$column";
    }
    $receiptItemJoin = $hasReceiptItems
        ? " LEFT JOIN warehouse_receipt_items wri ON wri.receipt_id = wr.receipt_id AND wri.order_item_id = oi.id"
        : "";

    $sql = "SELECT o.id as order_id, o.customer_id, o.supplier_id, o.status, o.expected_ready_date,
        c.name as customer_name, s.name as supplier_name,
        oi.id as item_id, oi.product_id, oi.quantity, oi.unit, oi.declared_cbm, oi.declared_weight, oi.description_cn, oi.description_en$orderItemMetaCols$orderItemDimensionCols$receiptItemCols,
        p.description_cn as product_desc_cn, p.description_en as product_desc_en,
        wr.actual_cbm as order_actual_cbm, wr.actual_weight as order_actual_weight, wr.actual_cartons as order_actual_cartons
        FROM orders o
        JOIN customers c ON o.customer_id = c.id
        LEFT JOIN suppliers s ON o.supplier_id = s.id
        JOIN order_items oi ON oi.order_id = o.id
        LEFT JOIN products p ON oi.product_id = p.id
        LEFT JOIN (
            SELECT w.id as receipt_id, w.order_id, w.actual_cbm, w.actual_weight, w.actual_cartons
            FROM warehouse_receipts w
            INNER JOIN (
                SELECT order_id, MAX(id) as mid
                FROM warehouse_receipts" . $latestReceiptInnerWhere . "
                GROUP BY order_id
            ) x ON w.order_id = x.order_id AND w.id = x.mid" . $latestReceiptOuterWhere . "
        ) wr ON wr.order_id = o.id
        $receiptItemJoin
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
    if ($id === 'export') {
        $format = strtolower(trim((string) ($_GET['format'] ?? 'xlsx')));
        if ($format === 'csv') {
            warehouseStockOutputCsv($rows);
        }
        require_once dirname(__DIR__, 2) . '/services/OrderExcelService.php';
        (new OrderExcelService())->exportWarehouseStockSummary(
            $rows,
            'warehouse_stock_' . date('Y-m-d') . '.xlsx'
        );
    }
    jsonResponse(['data' => $rows]);
};
