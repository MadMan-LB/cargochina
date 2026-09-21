<?php

/**
 * Warehouse Stock API - current stock visibility
 * Roles: WarehouseStaff, ChinaAdmin, LebanonAdmin, ContainersStaff, SuperAdmin
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 2) . '/services/OrderExcelService.php';
require_once dirname(__DIR__, 2) . '/services/CargoMetricsService.php';

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

function warehouseStockStatusGroups(): array
{
    return [
        'InTransit' => ['InTransitToWarehouse'],
        'InWarehouse' => CargoMetricsService::WAREHOUSE_STATUSES,
    ];
}

function warehouseStockNormalizeStateFilters($value): array
{
    $values = is_array($value) ? $value : (trim((string) $value) !== '' ? [$value] : []);
    $aliases = [
        'intransit' => 'InTransit',
        'intransittowarehouse' => 'InTransit',
        'inwarehouse' => 'InWarehouse',
        'warehousereceived' => 'InWarehouse',
        'receivedatwarehouse' => 'InWarehouse',
        'awaitingcustomerconfirmation' => 'InWarehouse',
        'confirmed' => 'InWarehouse',
        'readyforconsolidation' => 'InWarehouse',
        'consolidated' => 'InWarehouse',
        'consolidatedintoshipmentdraft' => 'InWarehouse',
        'assignedtocontainer' => 'InWarehouse',
        'customerdeclined' => 'InWarehouse',
        'customerdeclinedafterautoconfirm' => 'InWarehouse',
    ];
    $states = [];
    foreach ($values as $state) {
        $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', trim((string) $state)));
        if (isset($aliases[$key])) {
            $states[$aliases[$key]] = true;
        }
    }
    return array_keys($states);
}

function warehouseStockSearchExpressions(PDO $pdo): array
{
    $expressions = [
        'o.id',
        'oi.description_cn',
        'oi.description_en',
        'p.description_cn',
        'p.description_en',
        'oi.item_no',
        'oi.shipping_code',
        'c.name',
        "COALESCE(s.name,'')",
    ];
    foreach (['item_number', 'code', 'brand', 'materials', 'express_number'] as $searchColumn) {
        if (warehouseStockHasColumn($pdo, 'order_items', $searchColumn)) {
            $expressions[] = "oi.$searchColumn";
        }
    }
    return $expressions;
}

return function (string $method, ?string $id, ?string $action, array $input) {
    require_once __DIR__ . '/../authorization.php';
    clmsAuthorizeApiRequest('warehouse-stock', $method, $id, $action);
    $pdo = getDb();
    if ($method === 'GET') { require_once dirname(__DIR__, 2) . '/services/QueryFilterService.php'; QueryFilterService::validate($_GET, 'warehouse-stock'); }
    if($method==='GET'&&($action==='export'||$id==='export'))clmsBeginExportSnapshot($pdo);
    if (!getAuthUserId()) jsonError('Unauthorized', 401);
    requirePageAccess('warehouse_stock');
    if ($method !== 'GET') requireRole(['WarehouseStaff', 'ChinaAdmin', 'LebanonAdmin', 'ContainersStaff', 'SuperAdmin']);

    if ($method !== 'GET') jsonError('Method not allowed', 405);

    $customerId = $_GET['customer_id'] ?? null;
    $supplierId = $_GET['supplier_id'] ?? null;
    $containerId = $_GET['container_id'] ?? null;
    foreach (['customer_id'=>$customerId,'supplier_id'=>$supplierId,'container_id'=>$containerId] as $field=>$value) {
        if ($value!==null && $value!=='' && (!is_scalar($value) || !ctype_digit((string)$value) || (int)$value<1)) jsonError("Invalid $field",400);
    }
    if (isset($_GET['q']) && !is_string($_GET['q'])) jsonError('Invalid search',400);
    foreach ((array)($_GET['status'] ?? []) as $state) {
        if (!is_string($state) || !warehouseStockNormalizeStateFilters($state)) jsonError('Invalid warehouse state',400);
    }
    $statuses = warehouseStockNormalizeStateFilters($_GET['status'] ?? null);
    $q = trim($_GET['q'] ?? '');
    $itemType = clmsNormalizeItemTypeFilter($_GET['item_type'] ?? null);

    $receiptTotalsSql = CargoMetricsService::receiptTotalsSql($pdo);
    $orderTotalsSql = "SELECT o.id order_id, r.cbm, r.weight, r.cartons,
        COALESCE(r.receipt_count,0) receipt_count, COALESCE(r.unallocated_receipts,0) unallocated_receipts
        FROM orders o LEFT JOIN ($receiptTotalsSql) r ON r.order_id=o.id";
    $itemTotalsSql = CargoMetricsService::itemTotalsSql($pdo);
    $classificationSelect = warehouseStockHasColumn($pdo, 'item_classifications', 'item_type_code') ? ', ic.item_type_code, ic.confidence AS item_type_confidence, ic.is_confirmed AS item_type_confirmed' : '';
    $classificationJoin = warehouseStockHasColumn($pdo, 'item_classifications', 'item_type_code') ? " LEFT JOIN item_classifications ic ON ic.entity_type='order_item' AND ic.entity_id=oi.id" : '';
    $actualDimensionOuter = [];
    foreach (['actual_height','actual_width','actual_length'] as $dimensionColumn) {
        $outputAlias = 'item_' . $dimensionColumn;
        $actualDimensionOuter[] = "CASE WHEN wr.unallocated_receipts=0 THEN ria.$dimensionColumn ELSE NULL END AS $outputAlias";
    }
    $actualDimensionOuterSql = implode(', ', $actualDimensionOuter);

    $imagePathsSelect = warehouseStockHasColumn($pdo, 'order_items', 'image_paths') ? ', oi.image_paths' : ', NULL AS image_paths';
    $imagePathsSelect .= warehouseStockHasColumn($pdo, 'products', 'image_paths')
        ? ', p.image_paths AS product_image_paths'
        : ', NULL AS product_image_paths';
    $imagePathsSelect .= warehouseStockHasColumn($pdo, 'order_items', 'shared_carton_enabled')
        ? ', oi.shared_carton_enabled'
        : ', 0 AS shared_carton_enabled';
    $imagePathsSelect .= warehouseStockHasColumn($pdo, 'order_items', 'shared_carton_contents')
        ? ', oi.shared_carton_contents'
        : ', NULL AS shared_carton_contents';
    $warehouseStatusGroups = warehouseStockStatusGroups();
    $allStockStatuses = array_merge($warehouseStatusGroups['InTransit'], $warehouseStatusGroups['InWarehouse']);
    $baseStatusPlaceholders = implode(',', array_fill(0, count($allStockStatuses), '?'));
    $unknownAllocationSql = "(wr.unallocated_receipts>0 OR (wr.receipt_count=0 AND o.status<>'InTransitToWarehouse'))";
    $stateSql = "CASE WHEN COALESCE(ria.receipt_count,0)>0 OR $unknownAllocationSql THEN 'InWarehouse' ELSE 'InTransit' END";
    $orderedSql = "CASE WHEN oi.quantity>0 THEN oi.quantity ELSE COALESCE(oi.order_cartons,oi.cartons,0)*COALESCE(oi.order_qty_per_carton,oi.qty_per_carton,0) END";
    $receivedQuantitySql = "CASE WHEN $unknownAllocationSql OR COALESCE(ria.unknown_quantity,0)>0 THEN NULL ELSE COALESCE(ria.quantity,0) END";
    $sql = "SELECT o.id as order_id, o.customer_id, o.supplier_id, o.status,
        $stateSql AS warehouse_state,
        ($orderedSql) AS ordered_quantity, GREATEST(0,($orderedSql)-($receivedQuantitySql)) remaining_quantity,
        ($unknownAllocationSql OR COALESCE(ria.unknown_quantity,0)>0 OR COALESCE(ria.quantity,0)>($orderedSql)) reconciliation_required,
        COALESCE(ria.receipt_count,0) item_receipt_count,
        o.expected_ready_date,
        c.name as customer_name, s.name as supplier_name,
        oi.id as item_id, oi.product_id, oi.item_no, oi.item_number, oi.shipping_code, oi.quantity, oi.unit, oi.cartons, oi.qty_per_carton, oi.declared_cbm, oi.declared_weight, oi.item_length, oi.item_width, oi.item_height, oi.description_cn, oi.description_en,
        p.description_cn as product_desc_cn, p.description_en as product_desc_en,
        CASE WHEN wr.receipt_count>0 THEN wr.cbm ELSE 0 END as order_actual_cbm,
        CASE WHEN wr.receipt_count>0 THEN wr.weight ELSE 0 END as order_actual_weight,
        CASE WHEN wr.receipt_count>0 THEN wr.cartons ELSE 0 END as order_actual_cartons,
        CASE WHEN NOT $unknownAllocationSql THEN COALESCE(ria.cbm,0) ELSE NULL END item_actual_cbm,
        CASE WHEN NOT $unknownAllocationSql THEN COALESCE(ria.weight,0) ELSE NULL END item_actual_weight,
        CASE WHEN NOT $unknownAllocationSql THEN COALESCE(ria.cartons,0) ELSE NULL END item_actual_cartons,
        ($receivedQuantitySql) item_actual_quantity, $actualDimensionOuterSql$imagePathsSelect$classificationSelect
        FROM orders o
        JOIN customers c ON o.customer_id = c.id
        LEFT JOIN suppliers s ON o.supplier_id = s.id
        JOIN order_items oi ON oi.order_id = o.id
        LEFT JOIN products p ON oi.product_id = p.id$classificationJoin
        LEFT JOIN ($orderTotalsSql) wr ON wr.order_id = o.id
        LEFT JOIN ($itemTotalsSql) ria ON ria.order_item_id=oi.id
        WHERE o.status IN ($baseStatusPlaceholders)";
    $params = $allStockStatuses;
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
    if ($statuses && count($statuses) < count($warehouseStatusGroups)) {
        $sql .= " AND ($stateSql)=?";
        $params[] = $statuses[0];
    }
    if (isset($_GET['order_ids'])) {
        $ids = explode(',', (string) $_GET['order_ids']);
        if (!$ids || count($ids)>200 || array_filter($ids, static fn($v)=>!ctype_digit($v) || (int)$v<1)) jsonError('Invalid order selection',400);
        $sql .= ' AND o.id IN (' . implode(',',array_fill(0,count($ids),'?')) . ')';
        $params = array_merge($params,$ids);
    }
    if ($q) {
        $like = clmsSearchLike($q);
        $searchExpressions = warehouseStockSearchExpressions($pdo);
        $searchClauses = array_map(
            static fn(string $expression): string => clmsUtf8SearchExpr("COALESCE($expression, '')") . ' LIKE ?',
            $searchExpressions
        );
        if (warehouseStockHasColumn($pdo, 'order_items', 'shared_carton_contents')) {
            foreach (['item_no', 'item_number'] as $identifier) {
                $searchClauses[] = clmsSharedCartonIdentifierSearch('oi.shared_carton_contents', $identifier);
            }
        }
        $sql .= ' AND (' . implode(' OR ', $searchClauses) . ')';
        $params = array_merge($params, array_fill(0, count($searchClauses), $like));
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
    $orderReceiptImages = clmsOrderReceiptImagePaths(
        $pdo,
        array_map(static fn(array $row): int => (int) ($row['order_id'] ?? 0), $rows)
    );
    $firstRowByOrder = [];
    foreach ($rows as $index => $row) {
        $rowOrderId = (int) ($row['order_id'] ?? 0);
        if ($rowOrderId > 0 && !isset($firstRowByOrder[$rowOrderId])) {
            $firstRowByOrder[$rowOrderId] = $index;
        }
    }
    foreach ($rows as $index => &$row) {
        $row['unit'] = ReceivingQuantityService::quantityUnit($row);
        $rowOrderId = (int) ($row['order_id'] ?? 0);
        $sharedCartonImages = !empty($row['shared_carton_enabled'])
            ? clmsSharedCartonImagePaths($pdo, $row['shared_carton_contents'] ?? null)
            : [];
        $row['image_paths'] = clmsMergeImagePathLists(
            $row['image_paths'] ?? [],
            $row['product_image_paths'] ?? [],
            $sharedCartonImages,
            $receiptImages[(int) ($row['item_id'] ?? 0)] ?? [],
            ($firstRowByOrder[$rowOrderId] ?? -1) === $index
                ? ($orderReceiptImages[$rowOrderId] ?? [])
                : []
        );
        $contents = json_decode((string) ($row['shared_carton_contents'] ?? ''), true) ?: [];
        $contents = is_array($contents) ? array_values(array_filter($contents, 'is_array')) : [];
        $row['item_identifiers'] = !empty($row['shared_carton_enabled']) ? array_map(static fn(array $content): array => [
            'item_no' => $content['item_no'] ?? null,
            'item_number' => $content['item_number'] ?? null,
            'description_en' => $content['description_en'] ?? null,
            'description_cn' => $content['description_cn'] ?? null,
        ], $contents) : [];
        unset($row['shared_carton_contents']);
    }
    unset($row);
    if ($id === 'export') {
        $format = clmsExportFormat('xlsx');
        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="warehouse_stock_' . date('Y-m-d') . '.csv"');
            $out=fopen('php://output','w'); clmsWriteCsv($out,['Order','Customer','Supplier','Status','Item','Description EN','Description ZH','Item Type','Ordered Quantity','Actual Quantity','Actual Cartons','Actual CBM','Actual Weight','Height','Width','Length','I.I.N','Item Number','Remaining Quantity','Warehouse State','Reconciliation Required']);
            $safe=static fn($value)=>is_string($value) && preg_match('/^[\s\x00-\x1f]*[=+@-]/u',$value) ? "'".$value : $value;
            foreach($rows as $row) clmsWriteCsv($out,array_map($safe,[$row['order_id'],$row['customer_name'],$row['supplier_name'],$row['status'],$row['item_id'],$row['description_en'],$row['description_cn'],$row['item_type_code']??'unclassified',$row['ordered_quantity'],$row['item_actual_quantity'],$row['item_actual_cartons'],$row['item_actual_cbm'],$row['item_actual_weight'],$row['item_actual_height'],$row['item_actual_width'],$row['item_actual_length'],$row['item_no']??'',$row['item_number']??'',$row['remaining_quantity'],$row['warehouse_state'],!empty($row['reconciliation_required'])?'Yes':'No']));
            foreach ($rows as $row) foreach ($row['item_identifiers'] ?? [] as $content) {
                $reference = array_fill(0, 21, '');
                $reference[0] = $row['order_id'];
                $reference[1] = $row['customer_name'];
                $reference[2] = $row['supplier_name'];
                $reference[3] = $row['status'];
                $reference[4] = $row['item_id'];
                $reference[5] = clmsT('Contained item') . ': ' . ($content['description_en'] ?? $content['description_cn'] ?? '');
                $reference[16] = $content['item_no'] ?? '';
                $reference[17] = $content['item_number'] ?? '';
                clmsWriteCsv($out, array_map($safe,$reference));
            }
            fclose($out); exit;
        }
        (new OrderExcelService($pdo))->exportWarehouseStockSummary($rows, 'warehouse_stock_' . date('Ymd_His') . '.xlsx');
    }
    $hasMore=count($rows)>$limit; if($hasMore)$rows=array_slice($rows,0,$limit);
    jsonResponse(['data' => $rows, 'meta'=>['limit'=>$limit,'offset'=>$offset,'total'=>$total,'has_more'=>$hasMore]]);
};
