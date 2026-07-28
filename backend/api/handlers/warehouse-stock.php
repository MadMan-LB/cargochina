<?php

/**
 * Warehouse Stock API - current stock visibility
 * Roles: WarehouseStaff, ChinaAdmin, LebanonAdmin, ContainersStaff, SuperAdmin
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 2) . '/services/OrderExcelService.php';

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

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();
    if (!getAuthUserId()) jsonError('Unauthorized', 401);
    if (!hasAnyRole(['WarehouseStaff', 'ChinaAdmin', 'LebanonAdmin', 'ContainersStaff', 'SuperAdmin'])) jsonError('Forbidden', 403);

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
    $itemType = clmsNormalizeItemTypeFilter($_GET['item_type'] ?? null);

    $receiptHasVoidedAt = warehouseStockHasColumn($pdo, 'warehouse_receipts', 'voided_at');
    $activeReceiptWhere = $receiptHasVoidedAt ? ' WHERE w.voided_at IS NULL' : '';
    $activeReceiptItemWhere = $receiptHasVoidedAt ? ' WHERE rw.voided_at IS NULL' : '';
    $classificationSelect = warehouseStockHasColumn($pdo, 'item_classifications', 'item_type_code') ? ', ic.item_type_code, ic.confidence AS item_type_confidence, ic.is_confirmed AS item_type_confirmed' : '';
    $classificationJoin = warehouseStockHasColumn($pdo, 'item_classifications', 'item_type_code') ? " LEFT JOIN item_classifications ic ON ic.entity_type='order_item' AND ic.entity_id=oi.id" : '';
    $actualDimensionSelect = '';
    $actualDimensionOuter = [];
    foreach (['actual_height','actual_width','actual_length'] as $dimensionColumn) {
        $hasDimension = warehouseStockHasColumn($pdo, 'warehouse_receipt_items', $dimensionColumn);
        if ($hasDimension) {
            $actualDimensionSelect .= ", MAX(wri.$dimensionColumn) AS $dimensionColumn";
        }
        $outputAlias = 'item_' . $dimensionColumn;
        $actualDimensionOuter[] = $hasDimension
            ? "ria.$dimensionColumn AS $outputAlias"
            : "NULL AS $outputAlias";
    }
    $actualDimensionOuterSql = implode(', ', $actualDimensionOuter);

    $imagePathsSelect = warehouseStockHasColumn($pdo, 'order_items', 'image_paths') ? ', oi.image_paths' : ', NULL AS image_paths';
    $imagePathsSelect .= warehouseStockHasColumn($pdo, 'products', 'image_paths')
        ? ', p.image_paths AS product_image_paths'
        : ', NULL AS product_image_paths';
    $sql = "SELECT o.id as order_id, o.customer_id, o.supplier_id, o.status, o.expected_ready_date,
        c.name as customer_name, s.name as supplier_name,
        oi.id as item_id, oi.product_id, oi.item_no, oi.shipping_code, oi.quantity, oi.unit, oi.declared_cbm, oi.declared_weight, oi.item_length, oi.item_width, oi.item_height, oi.description_cn, oi.description_en,
        p.description_cn as product_desc_cn, p.description_en as product_desc_en,
        wr.actual_cbm as order_actual_cbm, wr.actual_weight as order_actual_weight, wr.actual_cartons as order_actual_cartons,
        ria.item_actual_cbm, ria.item_actual_weight, ria.item_actual_cartons, ria.item_actual_quantity, $actualDimensionOuterSql$imagePathsSelect$classificationSelect
        FROM orders o
        JOIN customers c ON o.customer_id = c.id
        LEFT JOIN suppliers s ON o.supplier_id = s.id
        JOIN order_items oi ON oi.order_id = o.id
        LEFT JOIN products p ON oi.product_id = p.id$classificationJoin
        LEFT JOIN (
            SELECT w.order_id, SUM(w.actual_cbm) actual_cbm, SUM(w.actual_weight) actual_weight, SUM(w.actual_cartons) actual_cartons
            FROM warehouse_receipts w$activeReceiptWhere GROUP BY w.order_id
        ) wr ON wr.order_id = o.id
        LEFT JOIN (
            SELECT wri.order_item_id, SUM(wri.actual_cbm) item_actual_cbm, SUM(wri.actual_weight) item_actual_weight, SUM(wri.actual_cartons) item_actual_cartons, SUM(wri.actual_quantity) item_actual_quantity$actualDimensionSelect
            FROM warehouse_receipt_items wri JOIN warehouse_receipts rw ON rw.id=wri.receipt_id$activeReceiptItemWhere GROUP BY wri.order_item_id
        ) ria ON ria.order_item_id=oi.id
        WHERE o.status IN ('InTransitToWarehouse','ReceivedAtWarehouse','AwaitingCustomerConfirmation','Confirmed','ReadyForConsolidation')";
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
        $receivedSelected = in_array('WarehouseReceived', $statuses, true);
        $storedStatuses = array_values(array_filter(
            $statuses,
            static fn(string $status): bool => $status !== 'WarehouseReceived'
        ));
        $statusClauses = [];
        if ($receivedSelected) {
            $receiptPredicate = 'EXISTS (SELECT 1 FROM warehouse_receipts wsf WHERE wsf.order_id = o.id'
                . ($receiptHasVoidedAt ? ' AND wsf.voided_at IS NULL' : '') . ')';
            $statusClauses[] = $receiptPredicate;
        }
        if ($storedStatuses) {
            $placeholders = implode(',', array_fill(0, count($storedStatuses), '?'));
            $statusClauses[] = "o.status IN ($placeholders)";
            $params = array_merge($params, $storedStatuses);
        }
        if ($statusClauses) {
            $combinedStatus = '(' . implode(' OR ', $statusClauses) . ')';
            $sql .= $statusMode === 'exclude'
                ? " AND NOT $combinedStatus"
                : " AND $combinedStatus";
        }
    }
    if ($q) {
        $like = clmsSearchLike($q);
        $sql .= " AND (" . clmsUtf8SearchExpr('oi.description_cn') . " LIKE ? OR " . clmsUtf8SearchExpr('oi.description_en') . " LIKE ? OR " . clmsUtf8SearchExpr('c.name') . " LIKE ? OR " . clmsUtf8SearchExpr("COALESCE(s.name,'')") . " LIKE ?)";
        $params = array_merge($params, [$like, $like, $like, $like]);
    }
    if ($itemType !== null && warehouseStockHasColumn($pdo, 'item_classifications', 'item_type_code')) {
        $sql .= ' AND ic.item_type_code=?'; $params[] = $itemType;
    }
    $countSql = "SELECT COUNT(*) FROM ($sql) warehouse_stock_filtered";
    $countStmt = $params ? $pdo->prepare($countSql) : $pdo->query($countSql);
    if ($params) $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql .= " ORDER BY o.expected_ready_date IS NULL, o.expected_ready_date, o.id, oi.id";
    $limit = clmsQueryLimit($_GET['limit'] ?? null, 100, 200);
    $offset = clmsQueryOffset($_GET['offset'] ?? null);
    if ($id !== 'export') $sql .= ' LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset;
    $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
    if ($params) $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $receiptImages = clmsReceiptItemImagePaths(
        $pdo,
        array_map(static fn(array $row): int => (int) ($row['item_id'] ?? 0), $rows)
    );
    foreach ($rows as &$row) {
        $row['image_paths'] = clmsMergeImagePathLists(
            $row['image_paths'] ?? [],
            $row['product_image_paths'] ?? [],
            $receiptImages[(int) ($row['item_id'] ?? 0)] ?? []
        );
    }
    unset($row);
    if ($id === 'export') {
        $format = strtolower(trim((string) ($_GET['format'] ?? 'xlsx')));
        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="warehouse_stock_' . date('Y-m-d') . '.csv"');
            $out=fopen('php://output','w'); fputcsv($out,['Order','Customer','Supplier','Status','Item','Description EN','Description ZH','Item Type','Quantity','Actual Quantity','Actual Cartons','Actual CBM','Actual Weight','Height','Width','Length']);
            foreach($rows as $row) fputcsv($out,[$row['order_id'],$row['customer_name'],$row['supplier_name'],$row['status'],$row['item_id'],$row['description_en'],$row['description_cn'],$row['item_type_code']??'unclassified',$row['quantity'],$row['item_actual_quantity'],$row['item_actual_cartons'],$row['item_actual_cbm'],$row['item_actual_weight'],$row['item_actual_height'],$row['item_actual_width'],$row['item_actual_length']]);
            fclose($out); exit;
        }
        (new OrderExcelService())->exportWarehouseStockSummary($rows, 'warehouse_stock_' . date('Ymd_His') . '.xlsx');
    }
    $hasMore=count($rows)>$limit; if($hasMore)$rows=array_slice($rows,0,$limit);
    jsonResponse(['data' => $rows, 'meta'=>['limit'=>$limit,'offset'=>$offset,'total'=>$total,'has_more'=>$hasMore]]);
};
