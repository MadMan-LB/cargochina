<?php

/**
 * Receiving API - queue (pending orders), search, receipts (history), receipt detail
 * GET /receiving/search?q= — Search orders by id, customer/supplier name, phone, shipping code (Approved/InTransit)
 * GET /receiving/queue?status=&customer_id=&supplier_id=&order_id=&date_from=&date_to=
 * GET /receiving/receipts?order_id=&customer_id=&date_from=&date_to=
 * GET /receiving/receipts/{id}
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 2) . '/services/OrderExcelService.php';
require_once dirname(__DIR__, 2) . '/services/ReceivingExcelImportService.php';
require_once dirname(__DIR__, 2) . '/services/OrderReceivingService.php';

function receivingOperationalRoles(): array
{
    return ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'ContainersStaff', 'FieldStaff', 'SuperAdmin'];
}

function receivingTableHasColumn(PDO $pdo, string $table, string $column): bool
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

function receivingUtf8LikeExpr(string $expr): string
{
    return "CONVERT($expr USING utf8mb4) COLLATE utf8mb4_unicode_ci";
}

function receivingFetchQueueRowsForRequest(PDO $pdo): array
{
    $statuses = $_GET['status'] ?? null;
    if (!$statuses) {
        $statuses = ['Approved', 'InTransitToWarehouse'];
    } else {
        $statuses = is_array($statuses) ? $statuses : explode(',', $statuses);
    }
    $customerId = $_GET['customer_id'] ?? null;
    $supplierId = $_GET['supplier_id'] ?? null;
    $orderId = $_GET['order_id'] ?? null;
    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo = $_GET['date_to'] ?? null;
    $shippingCode = trim($_GET['shipping_code'] ?? '');

    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $custCols = 'c.name as customer_name';
    $chkPrio = @$pdo->query("SHOW COLUMNS FROM customers LIKE 'priority_level'");
    if ($chkPrio && $chkPrio->rowCount() > 0) {
        $custCols .= ', c.priority_level as customer_priority_level, c.priority_note as customer_priority_note';
    }
    $sql = "SELECT o.id, o.customer_id, o.supplier_id, o.expected_ready_date, o.status, o.created_at, o.high_alert_notes,
        $custCols, s.name as supplier_name, s.phone as supplier_phone
        FROM orders o
        JOIN customers c ON o.customer_id = c.id
        LEFT JOIN suppliers s ON o.supplier_id = s.id
        WHERE o.status IN ($placeholders)";
    $params = $statuses;
    if ($customerId) {
        $sql .= " AND o.customer_id = ?";
        $params[] = $customerId;
    }
    if ($supplierId) {
        $sql .= " AND o.supplier_id = ?";
        $params[] = $supplierId;
    }
    if ($orderId) {
        $sql .= " AND o.id = ?";
        $params[] = $orderId;
    }
    if ($dateFrom) {
        $sql .= " AND o.expected_ready_date >= ?";
        $params[] = $dateFrom;
    }
    if ($dateTo) {
        $sql .= " AND o.expected_ready_date <= ?";
        $params[] = $dateTo;
    }
    if ($shippingCode !== '') {
        $queueItemClauses = [receivingUtf8LikeExpr('oi.shipping_code') . " LIKE ?"];
        $queueItemParams = ['%' . $shippingCode . '%'];
        foreach (['what_brand', 'copy_normal_goods', 'code', 'express_number', 'size'] as $column) {
            if (receivingTableHasColumn($pdo, 'order_items', $column)) {
                $queueItemClauses[] = receivingUtf8LikeExpr("oi.$column") . " LIKE ?";
                $queueItemParams[] = '%' . $shippingCode . '%';
            }
        }
        $sql .= " AND EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND (" . implode(' OR ', $queueItemClauses) . "))";
        array_push($params, ...$queueItemParams);
    }
    $sql .= " ORDER BY o.expected_ready_date IS NULL ASC, o.expected_ready_date ASC, o.id ASC LIMIT 200";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return [];
    }
    $chkProductAlert = @$pdo->query("SHOW COLUMNS FROM products LIKE 'high_alert_note'");
    $chkRequiredDesign = @$pdo->query("SHOW COLUMNS FROM products LIKE 'required_design'");
    $chkItemHs = @$pdo->query("SHOW COLUMNS FROM order_items LIKE 'hs_code'");
    $itemAlertCol = ($chkProductAlert && $chkProductAlert->rowCount() > 0) ? ", p.high_alert_note as product_high_alert_note" : "";
    if ($chkRequiredDesign && $chkRequiredDesign->rowCount() > 0) {
        $itemAlertCol .= ", p.required_design as product_required_design";
    }
    $itemHsCol = ($chkItemHs && $chkItemHs->rowCount() > 0)
        ? ", COALESCE(oi.hs_code, p.hs_code) as hs_code"
        : ", p.hs_code as hs_code";
    $itemMetaCols = '';
    foreach (['what_brand', 'brand', 'materials', 'copy_normal_goods', 'code', 'express_number', 'size', 'height', 'width', 'length'] as $column) {
        if (receivingTableHasColumn($pdo, 'order_items', $column)) {
            $itemMetaCols .= ", oi.$column";
        }
    }
    $orderIds = array_values(array_unique(array_map(static fn($row) => (int) ($row['id'] ?? 0), $rows)));
    $orderIds = array_values(array_filter($orderIds, static fn($id) => $id > 0));
    $itemsByOrder = [];
    if ($orderIds) {
        $itemPlaceholders = implode(',', array_fill(0, count($orderIds), '?'));
        $items = $pdo->prepare("SELECT oi.order_id, oi.id, oi.shipping_code$itemMetaCols, oi.cartons, oi.qty_per_carton, oi.quantity, oi.unit_price, oi.total_amount, oi.declared_cbm, oi.declared_weight, oi.item_length, oi.item_width, oi.item_height, oi.description_cn, oi.description_en$itemHsCol$itemAlertCol FROM order_items oi LEFT JOIN products p ON oi.product_id = p.id WHERE oi.order_id IN ($itemPlaceholders) ORDER BY oi.order_id ASC, oi.id ASC");
        $items->execute($orderIds);
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $oid = (int) ($item['order_id'] ?? 0);
            unset($item['order_id']);
            $itemsByOrder[$oid][] = $item;
        }
    }
    foreach ($rows as &$row) {
        $row['items'] = $itemsByOrder[(int) ($row['id'] ?? 0)] ?? [];
        $row['declared_cbm'] = array_sum(array_column($row['items'], 'declared_cbm'));
        $row['declared_weight'] = array_sum(array_column($row['items'], 'declared_weight'));
    }
    unset($row);

    return $rows;
}

function receivingOutputQueueCsv(array $rows, ?string $filename = null): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . ($filename ?: ('receiving_queue_' . date('Y-m-d') . '.csv')) . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $out = fopen('php://output', 'w');
    fputcsv($out, array_map('clmsT', ['Order ID', 'Customer', 'Supplier', 'Supplier Phone', 'Expected Ready', 'Status', 'Shipping Codes', 'Total Cartons', 'Declared CBM', 'Declared Weight (kg)', 'Items Summary']));
    foreach ($rows as $row) {
        $items = is_array($row['items'] ?? null) ? $row['items'] : [];
        $shippingCodes = [];
        $totalCartons = 0;
        $itemsSummary = [];
        foreach ($items as $item) {
            $shippingCode = trim((string) ($item['shipping_code'] ?? ''));
            if ($shippingCode !== '') {
                $shippingCodes[$shippingCode] = true;
            }
            $totalCartons += (int) ($item['cartons'] ?? 0);
            $summaryParts = [
                $shippingCode !== '' ? $shippingCode : '-',
                (string) ((int) ($item['cartons'] ?? 0)) . 'ctn',
                'HS:' . (trim((string) ($item['hs_code'] ?? '')) !== '' ? (string) $item['hs_code'] : '-'),
            ];
            foreach ([
                'Brand' => ($item['what_brand'] ?? '') ?: ($item['brand'] ?? ''),
                'Materials' => $item['materials'] ?? '',
                'Code' => $item['code'] ?? '',
                'Express' => $item['express_number'] ?? '',
                'Size' => $item['size'] ?? '',
            ] as $label => $value) {
                $value = trim((string) $value);
                if ($value !== '') {
                    $summaryParts[] = $label . ':' . $value;
                }
            }
            $height = $item['height'] ?? $item['item_height'] ?? null;
            $width = $item['width'] ?? $item['item_width'] ?? null;
            $length = $item['length'] ?? $item['item_length'] ?? null;
            $dims = array_filter([
                $height !== null && $height !== '' ? 'H:' . $height : '',
                $width !== null && $width !== '' ? 'W:' . $width : '',
                $length !== null && $length !== '' ? 'L:' . $length : '',
            ]);
            if ($dims) {
                $summaryParts[] = 'Dims ' . implode('/', $dims);
            }
            $itemsSummary[] = trim(implode(' ', $summaryParts));
        }
        fputcsv($out, [
            (int) ($row['id'] ?? 0),
            OrderExcelService::formatCustomerDisplay($row, $items),
            (string) ($row['supplier_name'] ?? ''),
            (string) ($row['supplier_phone'] ?? ''),
            (string) ($row['expected_ready_date'] ?? ''),
            clmsStatusLabel((string) ($row['status'] ?? '')),
            implode('; ', array_keys($shippingCodes)),
            $totalCartons,
            round((float) ($row['declared_cbm'] ?? 0), 6),
            round((float) ($row['declared_weight'] ?? 0), 2),
            implode('; ', array_filter($itemsSummary)),
        ]);
    }
    fclose($out);
    exit;
}

function receivingEnsureExcelImportTable(PDO $pdo): void
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'receiving_excel_imports'");
        if ($stmt && $stmt->fetchColumn()) {
            return;
        }
    } catch (Throwable $e) {
    }
    jsonError('Receiving Excel import table is missing. Run database migrations first.', 500);
}

function receivingStoreExcelImportPreview(PDO $pdo, array $file, array $preview, int $userId): array
{
    receivingEnsureExcelImportTable($pdo);
    $token = bin2hex(random_bytes(24));
    $status = !empty($preview['is_valid']) ? 'preview_ready' : 'invalid';
    $stmt = $pdo->prepare(
        "INSERT INTO receiving_excel_imports
         (preview_token, original_filename, file_hash, status, row_count, valid_count, error_count, preview_json, created_by)
         VALUES (?,?,?,?,?,?,?,?,?)"
    );
    $stmt->execute([
        $token,
        (string) ($file['name'] ?? 'receiving_import.xlsx'),
        (string) ($preview['file_hash'] ?? ''),
        $status,
        (int) ($preview['row_count'] ?? 0),
        (int) ($preview['valid_count'] ?? 0),
        (int) ($preview['error_count'] ?? 0),
        json_encode($preview, JSON_UNESCAPED_UNICODE),
        $userId,
    ]);
    $importId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('receiving_excel_import', ?, 'preview', ?, ?)")
        ->execute([
            $importId,
            json_encode([
                'status' => $status,
                'row_count' => (int) ($preview['row_count'] ?? 0),
                'valid_count' => (int) ($preview['valid_count'] ?? 0),
                'error_count' => (int) ($preview['error_count'] ?? 0),
                'filename' => (string) ($file['name'] ?? ''),
            ], JSON_UNESCAPED_UNICODE),
            $userId,
        ]);
    logClms('receiving_excel_import_preview', [
        'import_id' => $importId,
        'user_id' => $userId,
        'status' => $status,
        'rows' => (int) ($preview['row_count'] ?? 0),
        'errors' => (int) ($preview['error_count'] ?? 0),
    ]);

    return [$importId, $token, $status];
}

function receivingLoadExcelImportPreview(PDO $pdo, string $token, int $userId): array
{
    receivingEnsureExcelImportTable($pdo);
    $stmt = $pdo->prepare("SELECT * FROM receiving_excel_imports WHERE preview_token = ? AND created_by = ? LIMIT 1");
    $stmt->execute([$token, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        jsonError('Import preview not found or expired', 404);
    }
    if (($row['status'] ?? '') !== 'preview_ready') {
        jsonError('Import preview has errors and cannot be committed', 400);
    }
    $preview = json_decode((string) ($row['preview_json'] ?? ''), true);
    if (!is_array($preview)) {
        jsonError('Import preview data is unavailable', 400);
    }
    return [$row, $preview];
}

return function (string $method, ?string $id, ?string $action, array $input) {
    $operationalRoles = receivingOperationalRoles();
    requirePermission('page:receiving', $operationalRoles);
    $pdo = getDb();
    $userId = getAuthUserId() ?? 1;

    if ($id === 'import' && $action === 'preview') {
        requirePermission('receiving.import', $operationalRoles);
        if ($method !== 'POST') {
            jsonError('Method not allowed', 405);
        }
        try {
            $file = $_FILES['file'] ?? [];
            $preview = (new ReceivingExcelImportService())->previewFromUploadedFile($pdo, $file, [
                'customer_id' => $_POST['customer_id'] ?? '',
                'customer_name' => $_POST['customer_name'] ?? '',
                'currency' => $_POST['currency'] ?? '',
                'expected_ready_date' => $_POST['expected_ready_date'] ?? '',
            ]);
            [$importId, $token, $status] = receivingStoreExcelImportPreview($pdo, $file, $preview, $userId);
            unset($preview['payloads']);
            unset($preview['raw_rows']);
            jsonResponse(['data' => array_merge($preview, [
                'import_id' => $importId,
                'preview_token' => $token,
                'status' => $status,
            ])]);
        } catch (InvalidArgumentException $e) {
            jsonError($e->getMessage(), 400);
        } catch (Throwable $e) {
            throw $e;
        }
    }

    if ($id === 'import' && $action === 'commit') {
        requirePermission('receiving.import', $operationalRoles);
        if ($method !== 'POST') {
            jsonError('Method not allowed', 405);
        }
        $token = trim((string) ($input['preview_token'] ?? ''));
        if ($token === '') {
            jsonError('preview_token is required', 400);
        }
        [$importRow, $preview] = receivingLoadExcelImportPreview($pdo, $token, $userId);
        $importId = (int) $importRow['id'];
        $service = new ReceivingExcelImportService();
        $isDirectIntake = ($preview['mode'] ?? '') === 'direct_intake';
        $revalidated = $isDirectIntake
            ? $service->validateDirectIntakeRows($pdo, $preview['raw_rows'] ?? [], $preview['template_metadata'] ?? [])
            : $service->validateRows($pdo, $preview['raw_rows'] ?? []);
        if (empty($revalidated['is_valid'])) {
            $pdo->prepare("UPDATE receiving_excel_imports SET status = 'invalid', row_count = ?, valid_count = ?, error_count = ?, preview_json = ? WHERE id = ?")
                ->execute([
                    (int) ($revalidated['row_count'] ?? 0),
                    (int) ($revalidated['valid_count'] ?? 0),
                    (int) ($revalidated['error_count'] ?? 0),
                    json_encode($revalidated, JSON_UNESCAPED_UNICODE),
                    $importId,
                ]);
            jsonError('Import rows changed or became invalid. Review the preview again.', 400, ['rows' => 'Revalidation failed']);
        }

        $payloads = $revalidated['payloads'] ?? [];
        if (!$isDirectIntake && !$payloads) {
            jsonError('No valid receiving rows to import', 400);
        }
        if ($isDirectIntake && empty($revalidated['direct_groups'])) {
            jsonError('No valid direct receiving rows to import', 400);
        }

        $startedAt = microtime(true);
        $results = [];
        try {
            $pdo->beginTransaction();
            if ($isDirectIntake) {
                $resultPayload = $service->commitDirectIntake($pdo, $revalidated, $userId, $importId);
                $results = $resultPayload['receipts'] ?? [];
            } else {
                $receivingService = new OrderReceivingService();
                foreach ($payloads as $orderId => $payload) {
                    $results[(int) $orderId] = $receivingService->receive($pdo, (int) $orderId, $payload, $userId, false, [
                        'source' => 'excel_import',
                        'import_id' => $importId,
                    ]);
                }
                $resultPayload = [
                    'mode' => 'existing_order_receipt',
                    'orders_imported' => count($results),
                    'receipts' => $results,
                    'row_count' => (int) ($revalidated['row_count'] ?? 0),
                ];
            }
            $resultPayload['total_seconds'] = round(microtime(true) - $startedAt, 3);
            $pdo->prepare("UPDATE receiving_excel_imports SET status = 'committed', result_json = ?, committed_by = ?, committed_at = NOW() WHERE id = ?")
                ->execute([json_encode($resultPayload, JSON_UNESCAPED_UNICODE), $userId, $importId]);
            $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('receiving_excel_import', ?, 'commit', ?, ?)")
                ->execute([$importId, json_encode($resultPayload, JSON_UNESCAPED_UNICODE), $userId]);
            logClms('receiving_excel_import_commit', [
                'import_id' => $importId,
                'user_id' => $userId,
                'orders_imported' => count($results),
                'rows' => (int) ($revalidated['row_count'] ?? 0),
            ]);
            $pdo->commit();
            jsonResponse(['data' => $resultPayload]);
        } catch (OrderReceivingValidationException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->prepare("UPDATE receiving_excel_imports SET status = 'failed', result_json = ? WHERE id = ?")
                ->execute([json_encode(['message' => $e->getMessage(), 'errors' => $e->getFieldErrors()], JSON_UNESCAPED_UNICODE), $importId]);
            jsonError($e->getMessage(), $e->getStatusCode(), $e->getFieldErrors());
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->prepare("UPDATE receiving_excel_imports SET status = 'failed', result_json = ? WHERE id = ?")
                ->execute([json_encode(['message' => $e->getMessage()], JSON_UNESCAPED_UNICODE), $importId]);
            throw $e;
        }
    }

    if ($method !== 'GET') {
        jsonError('Method not allowed', 405);
    }

    if ($id === 'search') {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 1) {
            jsonResponse(['data' => []]);
        }
        $statuses = ['Approved', 'InTransitToWarehouse'];
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $like = '%' . preg_replace('/\s+/', '%', $q) . '%';
        $hasCustomerPhone = receivingTableHasColumn($pdo, 'customers', 'phone');
        $hasCustomerCode = receivingTableHasColumn($pdo, 'customers', 'code');
        $hasSupplierPhone = receivingTableHasColumn($pdo, 'suppliers', 'phone');
        $hasSupplierCode = receivingTableHasColumn($pdo, 'suppliers', 'code');
        $custCols = 'c.name as customer_name';
        $custCols .= $hasCustomerPhone ? ', c.phone as customer_phone' : ', NULL as customer_phone';
        $custCols .= $hasCustomerCode ? ', c.code as customer_code' : ', NULL as customer_code';
        $supplierCols = 's.name as supplier_name';
        $supplierCols .= $hasSupplierPhone ? ', s.phone as supplier_phone' : ', NULL as supplier_phone';
        $chkPrio = @$pdo->query("SHOW COLUMNS FROM customers LIKE 'priority_level'");
        if ($chkPrio && $chkPrio->rowCount() > 0) $custCols .= ', c.priority_level as customer_priority_level, c.priority_note as customer_priority_note';

        $searchClauses = ['o.id = ?'];
        $searchParams = [ctype_digit($q) ? (int) $q : 0];
        foreach ([receivingUtf8LikeExpr('c.name')] as $expr) {
            $searchClauses[] = "$expr LIKE ?";
            $searchParams[] = $like;
        }
        if ($hasCustomerCode) {
            $searchClauses[] = receivingUtf8LikeExpr('c.code') . " LIKE ?";
            $searchParams[] = $like;
        }
        if ($hasCustomerPhone) {
            $searchClauses[] = receivingUtf8LikeExpr('c.phone') . " LIKE ?";
            $searchParams[] = $like;
        }
        $searchClauses[] = receivingUtf8LikeExpr('s.name') . " LIKE ?";
        $searchParams[] = $like;
        if ($hasSupplierCode) {
            $searchClauses[] = receivingUtf8LikeExpr('s.code') . " LIKE ?";
            $searchParams[] = $like;
        }
        if ($hasSupplierPhone) {
            $searchClauses[] = receivingUtf8LikeExpr('s.phone') . " LIKE ?";
            $searchParams[] = $like;
        }

        $itemSearchClauses = [];
        $itemSearchParams = [];
        foreach (['shipping_code', 'item_no', 'description_cn', 'description_en', 'what_brand', 'copy_normal_goods', 'code', 'express_number', 'size'] as $column) {
            if (receivingTableHasColumn($pdo, 'order_items', $column)) {
                $itemSearchClauses[] = receivingUtf8LikeExpr("oi.$column") . " LIKE ?";
                $itemSearchParams[] = $like;
            }
        }
        if ($itemSearchClauses) {
            $searchClauses[] = "EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND (" . implode(' OR ', $itemSearchClauses) . "))";
            array_push($searchParams, ...$itemSearchParams);
        }
        $sql = "SELECT o.id, o.customer_id, o.supplier_id, o.expected_ready_date, o.status, o.high_alert_notes,
            $custCols,
            $supplierCols
            FROM orders o
            JOIN customers c ON o.customer_id = c.id
            LEFT JOIN suppliers s ON o.supplier_id = s.id
            WHERE o.status IN ($placeholders)
            AND (" . implode(' OR ', $searchClauses) . ")";
        $sql .= "
            ORDER BY o.expected_ready_date IS NULL ASC, o.expected_ready_date ASC, o.id ASC
            LIMIT 30";
        $params = array_merge($statuses, $searchParams);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $chkProductAlert = @$pdo->query("SHOW COLUMNS FROM products LIKE 'high_alert_note'");
        $chkRequiredDesign = @$pdo->query("SHOW COLUMNS FROM products LIKE 'required_design'");
        $itemAlertCol = ($chkProductAlert && $chkProductAlert->rowCount() > 0) ? ", p.high_alert_note as product_high_alert_note" : "";
        if ($chkRequiredDesign && $chkRequiredDesign->rowCount() > 0) $itemAlertCol .= ", p.required_design as product_required_design";
        $itemsByOrder = [];
        $orderIds = array_values(array_unique(array_map(static fn($row) => (int) ($row['id'] ?? 0), $rows)));
        $orderIds = array_values(array_filter($orderIds, static fn($id) => $id > 0));
        if ($orderIds) {
            $itemPlaceholders = implode(',', array_fill(0, count($orderIds), '?'));
            $items = $pdo->prepare("SELECT oi.order_id, oi.id, oi.shipping_code, oi.cartons, oi.declared_cbm, oi.declared_weight$itemAlertCol FROM order_items oi LEFT JOIN products p ON oi.product_id = p.id WHERE oi.order_id IN ($itemPlaceholders) ORDER BY oi.order_id ASC, oi.id ASC");
            $items->execute($orderIds);
            foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $oid = (int) ($item['order_id'] ?? 0);
                unset($item['order_id']);
                $itemsByOrder[$oid][] = $item;
            }
        }
        foreach ($rows as &$r) {
            $r['items'] = $itemsByOrder[(int) ($r['id'] ?? 0)] ?? [];
            $r['declared_cbm'] = array_sum(array_column($r['items'], 'declared_cbm'));
            $r['declared_weight'] = array_sum(array_column($r['items'], 'declared_weight'));
        }
        jsonResponse(['data' => $rows]);
    }

    if ($id === 'export' && $action === 'queue') {
        $rows = receivingFetchQueueRowsForRequest($pdo);
        $format = strtolower(trim((string) ($_GET['format'] ?? 'xlsx')));
        if ($format === 'csv') {
            receivingOutputQueueCsv($rows);
        }
        require_once dirname(__DIR__, 2) . '/services/OrderExcelService.php';
        (new OrderExcelService())->exportReceivingQueueSummary(
            $rows,
            'receiving_queue_' . date('Y-m-d') . '.xlsx'
        );
    }

    if ($id === 'queue') {
        jsonResponse(['data' => receivingFetchQueueRowsForRequest($pdo)]);
    }

    if ($id === 'receipts') {
        if (is_numeric($action)) {
            $receiptId = (int) $action;
            $custCols = 'c.name as customer_name';
            $chkPrio = @$pdo->query("SHOW COLUMNS FROM customers LIKE 'priority_level'");
            if ($chkPrio && $chkPrio->rowCount() > 0) $custCols .= ', c.priority_level as customer_priority_level, c.priority_note as customer_priority_note';
            $detailSql = "SELECT wr.*, o.id as order_id, o.customer_id, o.supplier_id, o.expected_ready_date, o.status as order_status, o.high_alert_notes,
                $custCols, s.name as supplier_name, u.full_name as received_by_name
                FROM warehouse_receipts wr
                JOIN orders o ON wr.order_id = o.id
                JOIN customers c ON o.customer_id = c.id
                LEFT JOIN suppliers s ON o.supplier_id = s.id
                LEFT JOIN users u ON wr.received_by = u.id
                WHERE wr.id = ?";
            $stmt = $pdo->prepare($detailSql);
            $stmt->execute([$receiptId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) jsonError('Receipt not found', 404);
            $rip = $pdo->prepare("SELECT * FROM warehouse_receipt_photos WHERE receipt_id = ?");
            $rip->execute([$receiptId]);
            $row['photos'] = $rip->fetchAll(PDO::FETCH_ASSOC);
            $receiptItemCols = "oi.declared_cbm, oi.declared_weight, oi.description_cn, oi.description_en, oi.item_no, oi.shipping_code, oi.cartons, oi.qty_per_carton, oi.quantity, oi.unit_price as declared_unit_price, oi.total_amount as declared_total_amount";
            foreach (['what_brand', 'brand', 'materials', 'copy_normal_goods', 'code', 'express_number', 'size', 'height', 'width', 'length'] as $column) {
                $chkMeta = @$pdo->query("SHOW COLUMNS FROM order_items LIKE " . $pdo->quote($column));
                if ($chkMeta && $chkMeta->rowCount() > 0) {
                    $receiptItemCols .= ", oi.$column";
                }
            }
            $rii = $pdo->prepare("SELECT wri.*, $receiptItemCols FROM warehouse_receipt_items wri JOIN order_items oi ON wri.order_item_id = oi.id WHERE wri.receipt_id = ?");
            $rii->execute([$receiptId]);
            $row['items'] = $rii->fetchAll(PDO::FETCH_ASSOC);
            $rip2 = $pdo->prepare("SELECT wrp.*, wri.id as receipt_item_id FROM warehouse_receipt_item_photos wrp JOIN warehouse_receipt_items wri ON wrp.receipt_item_id = wri.id WHERE wri.receipt_id = ?");
            $rip2->execute([$receiptId]);
            $itemPhotos = $rip2->fetchAll(PDO::FETCH_ASSOC);
            $itemSplits = [];
            try {
                $splitStmt = $pdo->prepare(
                    "SELECT wris.* FROM warehouse_receipt_item_splits wris
                     JOIN warehouse_receipt_items wri ON wris.receipt_item_id = wri.id
                     WHERE wri.receipt_id = ?
                     ORDER BY wris.receipt_item_id, wris.line_no, wris.id"
                );
                $splitStmt->execute([$receiptId]);
                $itemSplits = $splitStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $itemSplits = [];
            }
            foreach ($row['items'] as &$it) {
                $it['photos'] = array_values(array_filter($itemPhotos, fn($p) => (int)$p['receipt_item_id'] === (int)$it['id']));
                $splits = array_values(array_filter($itemSplits, fn($s) => (int)$s['receipt_item_id'] === (int)$it['id']));
                if (!$splits && ((float) ($it['actual_cartons'] ?? 0) > 0 || (float) ($it['actual_quantity'] ?? 0) > 0)) {
                    $splits = [[
                        'receipt_item_id' => (int) $it['id'],
                        'line_no' => 1,
                        'cartons' => $it['actual_cartons'] ?? null,
                        'pieces_per_carton' => $it['actual_pieces_per_carton'] ?? null,
                        'quantity' => $it['actual_quantity'] ?? null,
                        'unit_price' => $it['unit_price'] ?? null,
                        'total_amount' => $it['total_amount'] ?? null,
                    ]];
                }
                $it['packaging_splits'] = $splits;
            }
            jsonResponse(['data' => $row]);
        }

        $orderId = $_GET['order_id'] ?? null;
        $customerId = $_GET['customer_id'] ?? null;
        $dateFrom = $_GET['date_from'] ?? null;
        $dateTo = $_GET['date_to'] ?? null;
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        $custCols = 'c.name as customer_name';
        $chkPrio = @$pdo->query("SHOW COLUMNS FROM customers LIKE 'priority_level'");
        if ($chkPrio && $chkPrio->rowCount() > 0) $custCols .= ', c.priority_level as customer_priority_level, c.priority_note as customer_priority_note';
        $sql = "SELECT wr.id, wr.order_id, wr.actual_cartons, wr.actual_cbm, wr.actual_weight, wr.received_at, wr.receipt_condition,
            o.expected_ready_date, o.status as order_status, $custCols, s.name as supplier_name
            FROM warehouse_receipts wr
            JOIN orders o ON wr.order_id = o.id
            JOIN customers c ON o.customer_id = c.id
            LEFT JOIN suppliers s ON o.supplier_id = s.id
            WHERE 1=1";
        $params = [];
        if ($orderId) {
            $sql .= " AND wr.order_id = ?";
            $params[] = $orderId;
        }
        if ($customerId) {
            $sql .= " AND o.customer_id = ?";
            $params[] = $customerId;
        }
        if ($dateFrom) {
            $sql .= " AND wr.received_at >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo) {
            $sql .= " AND wr.received_at <= ?";
            $params[] = $dateTo . ' 23:59:59';
        }
        $sql .= " ORDER BY wr.received_at DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

        $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
        if ($params) $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(['data' => $rows]);
    }

    jsonError('Not found', 404);
};
