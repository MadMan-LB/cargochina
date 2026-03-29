<?php

/**
 * Orders API - CRUD, submit, approve, attachments
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 2) . '/services/OrderStateService.php';
require_once dirname(__DIR__, 2) . '/services/NotificationService.php';
require_once dirname(__DIR__, 2) . '/services/OrderCountryService.php';
require_once dirname(__DIR__, 2) . '/services/OrderItemNumberingService.php';
require_once dirname(__DIR__, 2) . '/services/OrderReceiptWorkflowService.php';
require_once dirname(__DIR__, 2) . '/services/OrderExcelService.php';

function normalizeOrderItems(array $items): array
{
    foreach ($items as &$it) {
        $it['image_paths'] = $it['image_paths'] ? json_decode($it['image_paths'], true) : [];
    }
    return $items;
}

function orderTableHasColumn(PDO $pdo, string $table, string $column): bool
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

function normalizeOptionalExpectedReadyDate($value): ?string
{
    $raw = trim((string) ($value ?? ''));
    if ($raw === '') {
        return null;
    }

    $ts = strtotime($raw);
    if ($ts === false) {
        jsonError('Invalid expected_ready_date', 400);
    }

    return date('Y-m-d', $ts);
}

function resolveOrderExpectedReadyDate(array $input, array $order = []): ?string
{
    if (!array_key_exists('expected_ready_date', $input)) {
        $existing = trim((string) ($order['expected_ready_date'] ?? ''));
        return $existing !== '' ? $existing : null;
    }

    return normalizeOptionalExpectedReadyDate($input['expected_ready_date'] ?? null);
}

function normalizeOrderDestinationCountryId(PDO $pdo, int $customerId, ?int $requestedCountryId): ?int
{
    return OrderCountryService::resolveDestinationCountryId($pdo, $customerId, $requestedCountryId);
}

function normalizeOrderItemsForPersistence(PDO $pdo, int $customerId, ?int $destinationCountryId, ?int $defaultSupplierId, array $items, ?string $currentStatus = 'Draft'): array
{
    $shippingCode = OrderCountryService::resolveShippingCode($pdo, $customerId, $destinationCountryId);
    return OrderItemNumberingService::prepareItemsForPersistence($items, $currentStatus, $shippingCode, $defaultSupplierId);
}

function buildOrderSearchSql(PDO $pdo, string $query, array &$params, string $orderAlias = 'o', string $customerAlias = 'c', string $supplierAlias = 's'): string
{
    $terms = preg_split('/\s+/', trim($query)) ?: [];
    $terms = array_values(array_filter(array_map(
        static fn($term) => ltrim((string) $term, '#'),
        $terms
    ), static fn($term) => $term !== ''));

    if (!$terms) {
        return '1=1';
    }

    $hasCustomerCode = orderTableHasColumn($pdo, 'customers', 'code');
    $hasCustomerPhone = orderTableHasColumn($pdo, 'customers', 'phone');
    $hasSupplierCode = orderTableHasColumn($pdo, 'suppliers', 'code');
    $hasSupplierStoreId = orderTableHasColumn($pdo, 'suppliers', 'store_id');
    $hasSupplierPhone = orderTableHasColumn($pdo, 'suppliers', 'phone');
    $hasOrderItemSupplier = orderTableHasColumn($pdo, 'order_items', 'supplier_id');
    $hasShippingCode = orderTableHasColumn($pdo, 'order_items', 'shipping_code');
    $hasItemNo = orderTableHasColumn($pdo, 'order_items', 'item_no');
    $hasDescriptionCn = orderTableHasColumn($pdo, 'order_items', 'description_cn');
    $hasDescriptionEn = orderTableHasColumn($pdo, 'order_items', 'description_en');
    $hasItemHsCode = orderTableHasColumn($pdo, 'order_items', 'hs_code');

    $coll = 'COLLATE utf8mb4_unicode_ci';
    $clauses = [];
    foreach ($terms as $term) {
        $like = '%' . $term . '%';
        $termClauses = [
            "CAST($orderAlias.id AS CHAR) $coll LIKE ?",
            "$customerAlias.name $coll LIKE ?",
            "COALESCE($supplierAlias.name, '') $coll LIKE ?",
        ];
        array_push($params, $like, $like, $like);

        if ($hasCustomerCode) {
            $termClauses[] = "COALESCE($customerAlias.code, '') $coll LIKE ?";
            $params[] = $like;
        }
        if ($hasCustomerPhone) {
            $termClauses[] = "COALESCE($customerAlias.phone, '') $coll LIKE ?";
            $params[] = $like;
        }
        if ($hasSupplierCode) {
            $termClauses[] = "COALESCE($supplierAlias.code, '') $coll LIKE ?";
            $params[] = $like;
        } elseif ($hasSupplierStoreId) {
            $termClauses[] = "COALESCE($supplierAlias.store_id, '') $coll LIKE ?";
            $params[] = $like;
        }
        if ($hasSupplierPhone) {
            $termClauses[] = "COALESCE($supplierAlias.phone, '') $coll LIKE ?";
            $params[] = $like;
        }

        $itemClauses = [];
        if ($hasShippingCode) {
            $itemClauses[] = "COALESCE(oi.shipping_code, '') $coll LIKE ?";
            $params[] = $like;
        }
        if ($hasItemNo) {
            $itemClauses[] = "COALESCE(oi.item_no, '') $coll LIKE ?";
            $params[] = $like;
        }
        if ($hasDescriptionCn) {
            $itemClauses[] = "COALESCE(oi.description_cn, '') $coll LIKE ?";
            $params[] = $like;
        }
        if ($hasDescriptionEn) {
            $itemClauses[] = "COALESCE(oi.description_en, '') $coll LIKE ?";
            $params[] = $like;
        }
        if ($hasItemHsCode) {
            $itemClauses[] = "COALESCE(oi.hs_code, op.hs_code, '') $coll LIKE ?";
            $params[] = $like;
        }
        if ($hasOrderItemSupplier) {
            $itemClauses[] = "COALESCE(sis.name, '') $coll LIKE ?";
            $params[] = $like;
            if ($hasSupplierCode) {
                $itemClauses[] = "COALESCE(sis.code, '') $coll LIKE ?";
                $params[] = $like;
            } elseif ($hasSupplierStoreId) {
                $itemClauses[] = "COALESCE(sis.store_id, '') $coll LIKE ?";
                $params[] = $like;
            }
            if ($hasSupplierPhone) {
                $itemClauses[] = "COALESCE(sis.phone, '') $coll LIKE ?";
                $params[] = $like;
            }
        }
        if ($itemClauses) {
            $itemSql = "EXISTS (SELECT 1 FROM order_items oi LEFT JOIN products op ON oi.product_id = op.id";
            if ($hasOrderItemSupplier) {
                $itemSql .= " LEFT JOIN suppliers sis ON oi.supplier_id = sis.id";
            }
            $itemSql .= " WHERE oi.order_id = $orderAlias.id AND (" . implode(' OR ', $itemClauses) . '))';
            $termClauses[] = $itemSql;
        }

        $clauses[] = '(' . implode(' OR ', $termClauses) . ')';
    }

    return implode(' AND ', $clauses);
}

function fetchOrderItems(PDO $pdo, int $orderId): array
{
    $chk = @$pdo->query("SHOW COLUMNS FROM order_items LIKE 'supplier_id'");
    $hasSupplier = $chk && $chk->rowCount() > 0;
    $hasProductSupplier = orderTableHasColumn($pdo, 'products', 'supplier_id');
    $chkProductAlert = @$pdo->query("SHOW COLUMNS FROM products LIKE 'high_alert_note'");
    $chkRequiredDesign = @$pdo->query("SHOW COLUMNS FROM products LIKE 'required_design'");
    $chkDimensionsScope = @$pdo->query("SHOW COLUMNS FROM products LIKE 'dimensions_scope'");
    $productAlertCol = ($chkProductAlert && $chkProductAlert->rowCount() > 0)
        ? ", p.high_alert_note as product_high_alert_note"
        : "";
    if ($chkRequiredDesign && $chkRequiredDesign->rowCount() > 0) {
        $productAlertCol .= ", p.required_design as product_required_design";
    }
    if ($chkDimensionsScope && $chkDimensionsScope->rowCount() > 0) {
        $productAlertCol .= ", p.dimensions_scope as product_dimensions_scope";
    }
    if (orderTableHasColumn($pdo, 'products', 'buy_price')) {
        $productAlertCol .= ", p.buy_price as product_buy_price";
        if (orderTableHasColumn($pdo, 'order_items', 'buy_price')) {
            $productAlertCol .= ", COALESCE(oi.buy_price, p.buy_price) as effective_buy_price";
        }
    }
    if (orderTableHasColumn($pdo, 'products', 'hs_code')) {
        $productAlertCol .= ", p.hs_code as product_hs_code";
        if (orderTableHasColumn($pdo, 'order_items', 'hs_code')) {
            $productAlertCol .= ", COALESCE(oi.hs_code, p.hs_code) as effective_hs_code";
        }
    }
    $supplierCols = '';
    if ($hasSupplier) {
        $supplierCols = ", s.name as supplier_name";
        if (orderTableHasColumn($pdo, 'suppliers', 'phone')) {
            $supplierCols .= ", s.phone as supplier_phone";
        }
        if (orderTableHasColumn($pdo, 'suppliers', 'payment_links')) {
            $supplierCols .= ", s.payment_links as supplier_payment_links";
        }
    }
    $supplierJoinTarget = $hasProductSupplier
        ? 'COALESCE(oi.supplier_id, p.supplier_id)'
        : 'oi.supplier_id';
    $sql = $hasSupplier
        ? "SELECT oi.*$supplierCols$productAlertCol FROM order_items oi LEFT JOIN products p ON oi.product_id = p.id LEFT JOIN suppliers s ON $supplierJoinTarget = s.id WHERE oi.order_id = ?"
        : "SELECT oi.*$productAlertCol FROM order_items oi LEFT JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchOrdersListRowsForRequest(PDO $pdo): array
{
    $statusParam = $_GET['status'] ?? null;
    $statuses = is_array($statusParam) ? array_filter($statusParam) : ($statusParam ? [$statusParam] : []);
    $statusMode = strtolower(trim((string) ($_GET['status_mode'] ?? 'include')));
    $statusMode = $statusMode === 'exclude' ? 'exclude' : 'include';
    $customerFeedback = trim((string) ($_GET['customer_feedback'] ?? ''));
    $customerId = $_GET['customer_id'] ?? null;
    $supplierId = $_GET['supplier_id'] ?? null;
    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo = $_GET['date_to'] ?? null;
    $orderId = $_GET['order_id'] ?? null;
    $shippingCode = trim($_GET['shipping_code'] ?? '');
    $q = trim($_GET['q'] ?? '');
    $orderType = trim((string) ($_GET['order_type'] ?? ''));
    $custCols = 'c.name as customer_name';
    $chkPrio = @$pdo->query("SHOW COLUMNS FROM customers LIKE 'priority_level'");
    if ($chkPrio && $chkPrio->rowCount() > 0) {
        $custCols .= ', c.priority_level as customer_priority_level, c.priority_note as customer_priority_note';
    }
    $destCols = orderTableHasColumn($pdo, 'orders', 'destination_country_id')
        ? ', co.id as destination_country_id, co.name as destination_country_name, co.code as destination_country_code'
        : '';
    $destJoin = orderTableHasColumn($pdo, 'orders', 'destination_country_id')
        ? ' LEFT JOIN countries co ON o.destination_country_id = co.id'
        : '';
    $sql = "SELECT o.*, $custCols, s.name as supplier_name,
        (SELECT c.code FROM containers c JOIN shipment_drafts sd ON sd.container_id = c.id JOIN shipment_draft_orders sdo ON sdo.shipment_draft_id = sd.id WHERE sdo.order_id = o.id LIMIT 1) as container_code,
        (SELECT c.eta_date FROM containers c JOIN shipment_drafts sd ON sd.container_id = c.id JOIN shipment_draft_orders sdo ON sdo.shipment_draft_id = sd.id WHERE sdo.order_id = o.id LIMIT 1) as container_eta
        $destCols
        FROM orders o
        JOIN customers c ON o.customer_id = c.id LEFT JOIN suppliers s ON o.supplier_id = s.id$destJoin WHERE 1=1";
    $params = [];
    if (!empty($statuses)) {
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $sql .= $statusMode === 'exclude'
            ? " AND o.status NOT IN ($placeholders)"
            : " AND o.status IN ($placeholders)";
        $params = array_merge($params, $statuses);
    }
    if ($customerId) {
        $sql .= " AND o.customer_id = ?";
        $params[] = $customerId;
    }
    if ($supplierId) {
        $chkItemSupp = @$pdo->query("SHOW COLUMNS FROM order_items LIKE 'supplier_id'");
        if ($chkItemSupp && $chkItemSupp->rowCount() > 0) {
            $sql .= " AND (o.supplier_id = ? OR EXISTS (SELECT 1 FROM order_items oi LEFT JOIN products p ON oi.product_id = p.id WHERE oi.order_id = o.id AND COALESCE(oi.supplier_id, p.supplier_id) = ?))";
            $params[] = $supplierId;
            $params[] = $supplierId;
        } else {
            $sql .= " AND o.supplier_id = ?";
            $params[] = $supplierId;
        }
    }
    if ($orderType !== '') {
        $sql .= " AND o.order_type = ?";
        $params[] = $orderType;
    }
    if ($dateFrom) {
        $sql .= " AND o.expected_ready_date >= ?";
        $params[] = $dateFrom;
    }
    if ($dateTo) {
        $sql .= " AND o.expected_ready_date <= ?";
        $params[] = $dateTo;
    }
    if ($orderId) {
        $sql .= " AND o.id = ?";
        $params[] = (int) $orderId;
    }
    if ($shippingCode !== '') {
        $sql .= " AND EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND (oi.shipping_code COLLATE utf8mb4_unicode_ci) LIKE ?)";
        $params[] = '%' . $shippingCode . '%';
    }
    if ($q !== '') {
        $sql .= " AND " . buildOrderSearchSql($pdo, $q, $params, 'o', 'c', 's');
    }
    if ($customerFeedback !== '') {
        if ($customerFeedback === 'pending') {
            $sql .= " AND COALESCE(o.confirmation_token, '') <> ''";
        } elseif ($customerFeedback === 'declined_after_auto_confirm') {
            $sql .= " AND o.status = 'CustomerDeclinedAfterAutoConfirm'";
        }
    }
    $sql .= " ORDER BY o.expected_ready_date IS NULL ASC, o.expected_ready_date ASC, o.created_at DESC";
    $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
    if ($params) {
        $stmt->execute($params);
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['items'] = normalizeOrderItems(fetchOrderItems($pdo, (int) $row['id']));
    }
    unset($row);

    return $rows;
}

function outputOrdersListCsv(array $rows, ?string $filename = null): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . ($filename ?: ('orders_' . date('Y-m-d') . '.csv')) . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Order Type', 'Customer', 'Supplier', 'Expected Ready', 'Status', 'Total CBM', 'Total Weight']);
    foreach ($rows as $row) {
        $cbm = 0.0;
        $weight = 0.0;
        $supplierNames = [];
        foreach (($row['items'] ?? []) as $item) {
            $cbm += (float) ($item['declared_cbm'] ?? 0);
            $weight += (float) ($item['declared_weight'] ?? 0);
            $supplierName = trim((string) ($item['supplier_name'] ?? ''));
            if ($supplierName !== '') {
                $supplierNames[$supplierName] = true;
            }
        }
        $supplierDisplay = trim((string) ($row['supplier_name'] ?? ''));
        if ($supplierNames) {
            $names = array_keys($supplierNames);
            $supplierDisplay = count($names) === 1 ? $names[0] : 'Multiple (' . implode(', ', $names) . ')';
        }
        fputcsv($out, [
            (int) ($row['id'] ?? 0),
            (string) ($row['order_type'] ?? 'standard'),
            OrderExcelService::formatCustomerDisplay($row, $row['items'] ?? []),
            $supplierDisplay,
            (string) ($row['expected_ready_date'] ?? ''),
            (string) ($row['status'] ?? ''),
            round($cbm, 4),
            round($weight, 2),
        ]);
    }
    fclose($out);
    exit;
}

function outputOrderCsv(array $order, array $items, ?string $filename = null): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . ($filename ?: ('order_' . (int) ($order['id'] ?? 0) . '.csv')) . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Order', '#' . (int) ($order['id'] ?? 0)]);
    fputcsv($out, ['Customer', OrderExcelService::formatCustomerDisplay($order, $items)]);
    fputcsv($out, ['Supplier', (string) ($order['supplier_name'] ?? '')]);
    fputcsv($out, ['Expected Ready', (string) ($order['expected_ready_date'] ?? '')]);
    fputcsv($out, ['Status', (string) ($order['status'] ?? '')]);
    fputcsv($out, ['Currency', (string) ($order['currency'] ?? '')]);
    fputcsv($out, ['']);
    fputcsv($out, ['Photo Count', 'Item No', 'Supplier', 'Description', 'Total CTNS', 'QTY/CTN', 'TOTAL QTY', 'UNIT PRICE', 'TOTAL AMOUNT', 'CBM', 'TOTAL CBM', 'GWKG', 'TOTAL GW']);

    foreach ($items as $item) {
        $imagePaths = $item['image_paths'] ?? [];
        if (is_string($imagePaths)) {
            $imagePaths = json_decode($imagePaths, true) ?: [];
        }
        $itemNo = (string) ($item['item_no'] ?? '');
        if ($itemNo === '') {
            $itemNo = (string) ($item['shipping_code'] ?? '');
        }
        $desc = (string) ($item['description_en'] ?? $item['description_cn'] ?? '');
        $cartons = (float) ($item['cartons'] ?? 0);
        $qtyPerCtn = (float) ($item['qty_per_carton'] ?? 0);
        $unitPrice = isset($item['sell_price']) && $item['sell_price'] !== null && $item['sell_price'] !== ''
            ? (float) $item['sell_price']
            : (float) ($item['unit_price'] ?? 0);
        $scope = strtolower(trim((string) ($item['product_dimensions_scope'] ?? $item['dimensions_scope'] ?? 'piece')));
        $totalQty = ($cartons > 0 && $qtyPerCtn > 0) ? $cartons * $qtyPerCtn : (float) ($item['quantity'] ?? 0);
        $denom = ($scope === 'carton' && $cartons > 0) ? $cartons : ($totalQty > 0 ? $totalQty : 0);
        $cbmPer = ($item['declared_cbm'] ?? null) && $denom > 0
            ? round((float) $item['declared_cbm'] / $denom, 6)
            : 0;
        $gwPer = ($item['declared_weight'] ?? null) && $denom > 0
            ? round((float) $item['declared_weight'] / $denom, 4)
            : 0;
        $multiplier = $scope === 'carton' ? $cartons : $totalQty;

        fputcsv($out, [
            count($imagePaths),
            $itemNo,
            (string) ($item['supplier_name'] ?? ''),
            $desc,
            $cartons ?: '',
            $qtyPerCtn ?: '',
            $totalQty ?: '',
            $unitPrice ?: '',
            $totalQty > 0 && $unitPrice ? round($totalQty * $unitPrice, 4) : '',
            $cbmPer ?: '',
            $multiplier > 0 && $cbmPer ? round($cbmPer * $multiplier, 6) : '',
            $gwPer ?: '',
            $multiplier > 0 && $gwPer ? round($gwPer * $multiplier, 4) : '',
        ]);
    }
    fclose($out);
    exit;
}

/** Check for duplicate shipping codes (same customer, other orders). Returns warning message or null. */
function checkDuplicateShippingCodes(PDO $pdo, int $customerId, int $excludeOrderId, array $items): ?string
{
    $codes = [];
    foreach ($items as $it) {
        $sc = trim($it['shipping_code'] ?? '');
        if ($sc !== '') $codes[] = $sc;
    }
    if (empty($codes)) return null;
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $stmt = $pdo->prepare("SELECT oi.shipping_code, o.id as order_id FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE o.customer_id = ? AND o.id != ? AND oi.shipping_code IN ($placeholders) AND TRIM(oi.shipping_code) != ''");
    $params = array_merge([$customerId, $excludeOrderId], $codes);
    $stmt->execute($params);
    $dups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($dups)) return null;
    $list = array_unique(array_column($dups, 'shipping_code'));
    return 'Duplicate shipping code(s) for this customer: ' . implode(', ', $list);
}

function enforceDuplicateShippingCodePolicy(PDO $pdo, int $customerId, int $excludeOrderId, array $items): ?string
{
    $warning = checkDuplicateShippingCodes($pdo, $customerId, $excludeOrderId, $items);
    if ($warning === null) {
        return null;
    }

    $action = getBusinessSetting($pdo, 'SHIPPING_CODE_DUPLICATE_ACTION', 'warn');
    if ($action === 'block') {
        jsonError($warning, 409);
    }

    return $warning;
}

function detectCrossSupplierPriceDifferences(PDO $pdo, int $orderId, string $currency, ?int $defaultSupplierId, array $items): array
{
    $openStatuses = ['Draft', 'Submitted', 'Approved', 'InTransitToWarehouse'];
    $placeholders = implode(',', array_fill(0, count($openStatuses), '?'));
    $stmt = $pdo->prepare(
        "SELECT o.id as other_order_id,
                COALESCE(oi.supplier_id, o.supplier_id) as other_supplier_id,
                COALESCE(s.name, 'Unknown supplier') as other_supplier_name,
                oi.unit_price as other_unit_price
         FROM order_items oi
         JOIN orders o ON oi.order_id = o.id
         LEFT JOIN suppliers s ON COALESCE(oi.supplier_id, o.supplier_id) = s.id
         WHERE oi.product_id = ?
           AND oi.order_id != ?
           AND o.currency = ?
           AND o.status IN ($placeholders)
           AND COALESCE(oi.supplier_id, o.supplier_id) IS NOT NULL
           AND COALESCE(oi.supplier_id, o.supplier_id) != ?
         ORDER BY o.id DESC"
    );
    $supplierNameStmt = $pdo->prepare("SELECT name FROM suppliers WHERE id = ?");
    $matches = [];
    $seen = [];

    foreach ($items as $it) {
        $productId = !empty($it['product_id']) ? (int) $it['product_id'] : 0;
        $currentSupplierId = !empty($it['supplier_id']) ? (int) $it['supplier_id'] : ($defaultSupplierId ?: 0);
        $currentPrice = isset($it['unit_price']) && $it['unit_price'] !== '' ? (float) $it['unit_price'] : null;
        if ($productId <= 0 || $currentSupplierId <= 0 || $currentPrice === null || $currentPrice <= 0) {
            continue;
        }

        $params = array_merge([$productId, $orderId, $currency], $openStatuses, [$currentSupplierId]);
        $stmt->execute($params);
        $currentSupplierName = null;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $otherPrice = (float) ($row['other_unit_price'] ?? 0);
            if (abs($otherPrice - $currentPrice) < 0.0001) {
                continue;
            }
            if ($currentSupplierName === null) {
                $supplierNameStmt->execute([$currentSupplierId]);
                $currentSupplierName = $supplierNameStmt->fetchColumn() ?: ('Supplier #' . $currentSupplierId);
            }
            $signatureKey = implode('|', [
                $productId,
                $currentSupplierId,
                round($currentPrice, 4),
                (int) $row['other_supplier_id'],
                round($otherPrice, 4),
            ]);
            if (isset($seen[$signatureKey])) {
                continue;
            }
            $seen[$signatureKey] = true;
            $matches[] = [
                'product_id' => $productId,
                'description' => trim((string) ($it['description_en'] ?? $it['description_cn'] ?? ('Product #' . $productId))),
                'current_supplier_id' => $currentSupplierId,
                'current_supplier_name' => $currentSupplierName,
                'current_price' => round($currentPrice, 4),
                'other_supplier_id' => (int) $row['other_supplier_id'],
                'other_supplier_name' => $row['other_supplier_name'],
                'other_price' => round($otherPrice, 4),
                'other_order_id' => (int) $row['other_order_id'],
            ];
        }
    }

    return $matches;
}

function notifyCrossSupplierPriceDifferences(PDO $pdo, int $orderId, array $matches): void
{
    if (empty($matches)) {
        return;
    }

    try {
        $signature = hash('sha256', json_encode($matches, JSON_UNESCAPED_UNICODE));
        $payload = json_encode(['signature' => $signature, 'matches' => $matches], JSON_UNESCAPED_UNICODE);
        $stmt = $pdo->prepare("SELECT 1 FROM audit_log WHERE entity_type = 'order' AND entity_id = ? AND action = 'cross_supplier_price_difference_notified' AND new_value = ? LIMIT 1");
        $stmt->execute([$orderId, $payload]);
        if ($stmt->fetchColumn()) {
            return;
        }

        (new NotificationService($pdo))->notifyCrossSupplierPriceDifference($orderId, $matches);
        $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('order', ?, 'cross_supplier_price_difference_notified', ?, ?)")
            ->execute([$orderId, $payload, getAuthUserId() ?: null]);
    } catch (Throwable $e) {
        logClms('cross_supplier_price_difference_notify_failed', [
            'order_id' => $orderId,
            'error' => $e->getMessage(),
        ]);
    }
}

/** Sync order item data back to product when user corrects info in the order form */
function syncProductFromOrderItem(PDO $pdo, array $it): void
{
    $productId = !empty($it['product_id']) ? (int) $it['product_id'] : null;
    if (!$productId) return;
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) return;

    $assignIfEmpty = static function ($current): bool {
        if ($current === null) return true;
        if (is_string($current) && trim($current) === '') return true;
        if (is_numeric($current) && (float) $current <= 0) return true;
        return false;
    };

    $qty = (float) ($it['quantity'] ?? 0);
    if ($qty <= 0) $qty = 1;
    $cbmTotal = (float) ($it['declared_cbm'] ?? 0);
    $weightTotal = (float) ($it['declared_weight'] ?? 0);
    $sets = [];
    $vals = [];
    if (isset($it['description_cn']) && $assignIfEmpty($product['description_cn'] ?? null)) {
        $sets[] = 'description_cn=?';
        $vals[] = $it['description_cn'] ?: null;
    }
    if (isset($it['description_en']) && $assignIfEmpty($product['description_en'] ?? null)) {
        $sets[] = 'description_en=?';
        $vals[] = $it['description_en'] ?: null;
    }
    if (isset($it['unit_price']) && $assignIfEmpty($product['unit_price'] ?? null)) {
        $sets[] = 'unit_price=?';
        $vals[] = $it['unit_price'] !== null && $it['unit_price'] !== '' ? (float) $it['unit_price'] : null;
    }
    if ($weightTotal > 0 && $assignIfEmpty($product['weight'] ?? null)) {
        $sets[] = 'weight=?';
        $vals[] = $weightTotal / $qty;
    }
    if ($cbmTotal > 0 && $assignIfEmpty($product['cbm'] ?? null)) {
        $sets[] = 'cbm=?';
        $vals[] = $cbmTotal / $qty;
    }
    if (isset($it['item_length']) && $it['item_length'] !== null && $it['item_length'] !== '' && $assignIfEmpty($product['length_cm'] ?? null)) {
        $sets[] = 'length_cm=?';
        $vals[] = (float) $it['item_length'];
    }
    if (isset($it['item_width']) && $it['item_width'] !== null && $it['item_width'] !== '' && $assignIfEmpty($product['width_cm'] ?? null)) {
        $sets[] = 'width_cm=?';
        $vals[] = (float) $it['item_width'];
    }
    if (isset($it['item_height']) && $it['item_height'] !== null && $it['item_height'] !== '' && $assignIfEmpty($product['height_cm'] ?? null)) {
        $sets[] = 'height_cm=?';
        $vals[] = (float) $it['item_height'];
    }
    if (isset($it['qty_per_carton']) && $it['qty_per_carton'] !== null && $it['qty_per_carton'] !== '') {
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM products LIKE 'pieces_per_carton'");
            if ($chk && $chk->rowCount() > 0 && $assignIfEmpty($product['pieces_per_carton'] ?? null)) {
                $sets[] = 'pieces_per_carton=?';
                $vals[] = (int) $it['qty_per_carton'];
            }
        } catch (Throwable $e) {
        }
    }
    if (orderTableHasColumn($pdo, 'products', 'hs_code')
        && orderTableHasColumn($pdo, 'order_items', 'hs_code')
        && $assignIfEmpty($product['hs_code'] ?? null)
        && !empty($it['hs_code'])) {
        $sets[] = 'hs_code=?';
        $vals[] = trim((string) $it['hs_code']) ?: null;
    }
    if (orderTableHasColumn($pdo, 'products', 'required_design')
        && orderTableHasColumn($pdo, 'order_items', 'custom_design_required')
        && !empty($it['custom_design_required'])
        && empty($product['required_design'])) {
        $sets[] = 'required_design=?';
        $vals[] = 1;
    }
    if (orderTableHasColumn($pdo, 'products', 'image_paths')
        && $assignIfEmpty($product['image_paths'] ?? null)
        && !empty($it['image_paths'])
        && is_array($it['image_paths'])) {
        $sets[] = 'image_paths=?';
        $vals[] = json_encode($it['image_paths']);
    }
    if (empty($sets)) return;
    $vals[] = $productId;
    $pdo->prepare("UPDATE products SET " . implode(', ', $sets) . " WHERE id=?")->execute($vals);
}

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();
    $userId = getAuthUserId() ?? 1; // Dev fallback

    switch ($method) {
        case 'GET':
            if ($id === 'search') {
                $q = trim($_GET['q'] ?? '');
                $customerId = !empty($_GET['customer_id']) ? (int) $_GET['customer_id'] : null;
                $supplierId = !empty($_GET['supplier_id']) ? (int) $_GET['supplier_id'] : null;
                $orderType = trim((string) ($_GET['order_type'] ?? ''));
                if (strlen($q) < 1 && !$customerId && !$supplierId) {
                    jsonResponse(['data' => []]);
                }
                $custCols = 'c.name as customer_name';
                if (orderTableHasColumn($pdo, 'customers', 'priority_level')) {
                    $custCols .= ', c.priority_level as customer_priority_level';
                }
                if (orderTableHasColumn($pdo, 'customers', 'phone')) {
                    $custCols .= ', c.phone as customer_phone';
                }
                $searchCols = "$custCols, s.name as supplier_name";
                if (orderTableHasColumn($pdo, 'order_items', 'shipping_code')) {
                    $searchCols .= ", (
                        SELECT GROUP_CONCAT(DISTINCT oi.shipping_code ORDER BY oi.shipping_code SEPARATOR ', ')
                        FROM order_items oi
                        WHERE oi.order_id = o.id
                          AND COALESCE(oi.shipping_code, '') != ''
                    ) as shipping_codes";
                }
                $itemPreviewCandidates = [];
                if (orderTableHasColumn($pdo, 'order_items', 'description_en')) {
                    $itemPreviewCandidates[] = "NULLIF(oi.description_en, '')";
                }
                if (orderTableHasColumn($pdo, 'order_items', 'description_cn')) {
                    $itemPreviewCandidates[] = "NULLIF(oi.description_cn, '')";
                }
                if (orderTableHasColumn($pdo, 'order_items', 'item_no')) {
                    $itemPreviewCandidates[] = "NULLIF(oi.item_no, '')";
                }
                if ($itemPreviewCandidates) {
                    $searchCols .= ", (
                        SELECT GROUP_CONCAT(
                            DISTINCT COALESCE(" . implode(', ', $itemPreviewCandidates) . ")
                            ORDER BY oi.id SEPARATOR ', '
                        )
                        FROM order_items oi
                        WHERE oi.order_id = o.id
                    ) as item_preview";
                }
                if (orderTableHasColumn($pdo, 'order_items', 'supplier_id')) {
                    $searchCols .= ", (
                        SELECT GROUP_CONCAT(DISTINCT sis.name ORDER BY sis.name SEPARATOR ', ')
                        FROM order_items oi
                        LEFT JOIN suppliers sis ON oi.supplier_id = sis.id
                        WHERE oi.order_id = o.id
                          AND COALESCE(sis.name, '') != ''
                    ) as item_supplier_names";
                }
                $params = [];
                $where = strlen($q) >= 1 ? buildOrderSearchSql($pdo, $q, $params, 'o', 'c', 's') : '1=1';
                if ($customerId) {
                    $where .= ' AND o.customer_id = ?';
                    $params[] = $customerId;
                }
                if ($supplierId) {
                    $chkItemSupp = @$pdo->query("SHOW COLUMNS FROM order_items LIKE 'supplier_id'");
                    if ($chkItemSupp && $chkItemSupp->rowCount() > 0) {
                        $where .= ' AND (o.supplier_id = ? OR EXISTS (SELECT 1 FROM order_items oi LEFT JOIN products p ON oi.product_id = p.id WHERE oi.order_id = o.id AND COALESCE(oi.supplier_id, p.supplier_id) = ?))';
                        $params[] = $supplierId;
                        $params[] = $supplierId;
                    } else {
                        $where .= ' AND o.supplier_id = ?';
                        $params[] = $supplierId;
                    }
                }
                if ($orderType !== '') {
                    $where .= ' AND o.order_type = ?';
                    $params[] = $orderType;
                }
                $sql = "SELECT o.id, o.status, o.expected_ready_date, o.order_type, $searchCols
                    FROM orders o
                    JOIN customers c ON o.customer_id = c.id
                    LEFT JOIN suppliers s ON o.supplier_id = s.id
                    WHERE $where
                    ORDER BY o.id DESC LIMIT 20";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                jsonResponse(['data' => $rows]);
            }
            if ($id === 'export' && $action === 'list') {
                $rows = fetchOrdersListRowsForRequest($pdo);
                $format = strtolower(trim((string) ($_GET['format'] ?? 'xlsx')));
                if ($format === 'csv') {
                    outputOrdersListCsv($rows);
                }
                require_once dirname(__DIR__, 2) . '/services/OrderExcelService.php';
                (new OrderExcelService())->exportOrdersListSummary(
                    $rows,
                    'orders_' . date('Y-m-d') . '.xlsx'
                );
            }
            if ($id && $action === 'export') {
                $suppCols = 's.name as supplier_name, s.phone as supplier_phone, s.factory_location as supplier_factory';
                $chk = @$pdo->query("SHOW COLUMNS FROM suppliers LIKE 'address'");
                if ($chk && $chk->rowCount() > 0) $suppCols .= ', s.address as supplier_address';
                $chk = @$pdo->query("SHOW COLUMNS FROM suppliers LIKE 'fax'");
                if ($chk && $chk->rowCount() > 0) $suppCols .= ', s.fax as supplier_fax';
                $stmt = $pdo->prepare("SELECT o.*, c.name as customer_name, $suppCols FROM orders o JOIN customers c ON o.customer_id = c.id LEFT JOIN suppliers s ON o.supplier_id = s.id WHERE o.id = ?");
                $stmt->execute([$id]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$order) jsonError('Order not found', 404);
                $items = normalizeOrderItems(fetchOrderItems($pdo, (int) $id));
                $format = strtolower(trim((string) ($_GET['format'] ?? 'xlsx')));
                if ($format === 'csv') {
                    outputOrderCsv($order, $items, 'order_' . (int) $id . '.csv');
                }
                require_once dirname(__DIR__, 2) . '/services/OrderExcelService.php';
                (new OrderExcelService())->exportOrder($order, $items, 'order_' . (int) $id . '_goods_details.xlsx');
            }
            if ($id === null) {
                jsonResponse(['data' => fetchOrdersListRowsForRequest($pdo)]);
            }
            $custCols = 'c.name as customer_name';
            $chkPrio = @$pdo->query("SHOW COLUMNS FROM customers LIKE 'priority_level'");
            if ($chkPrio && $chkPrio->rowCount() > 0) $custCols .= ', c.priority_level as customer_priority_level, c.priority_note as customer_priority_note';
            $destCols = orderTableHasColumn($pdo, 'orders', 'destination_country_id')
                ? ', co.name as destination_country_name, co.code as destination_country_code'
                : '';
            $destJoin = orderTableHasColumn($pdo, 'orders', 'destination_country_id')
                ? ' LEFT JOIN countries co ON o.destination_country_id = co.id'
                : '';
            $stmt = $pdo->prepare("SELECT o.*, $custCols, s.name as supplier_name$destCols FROM orders o JOIN customers c ON o.customer_id = c.id LEFT JOIN suppliers s ON o.supplier_id = s.id$destJoin WHERE o.id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) jsonError('Order not found', 404);
            $row['items'] = normalizeOrderItems(fetchOrderItems($pdo, (int) $id));
            $att = $pdo->prepare("SELECT * FROM order_attachments WHERE order_id = ?");
            $att->execute([$id]);
            $row['attachments'] = $att->fetchAll(PDO::FETCH_ASSOC);
            $wr = $pdo->prepare("SELECT * FROM warehouse_receipts WHERE order_id = ? ORDER BY received_at DESC LIMIT 1");
            $wr->execute([$id]);
            $receipt = $wr->fetch(PDO::FETCH_ASSOC);
            if ($receipt) {
                $row['receipt'] = $receipt;
                $rip = $pdo->prepare("SELECT * FROM warehouse_receipt_photos WHERE receipt_id = ?");
                $rip->execute([$receipt['id']]);
                $row['receipt']['photos'] = $rip->fetchAll(PDO::FETCH_ASSOC);
                $rii = $pdo->prepare("SELECT wri.*, oi.description_cn, oi.description_en FROM warehouse_receipt_items wri JOIN order_items oi ON wri.order_item_id = oi.id WHERE wri.receipt_id = ?");
                $rii->execute([$receipt['id']]);
                $row['receipt']['items'] = $rii->fetchAll(PDO::FETCH_ASSOC);
                $config = require dirname(__DIR__, 2) . '/config/config.php';
                $row['customer_photo_visibility'] = $config['customer_photo_visibility'] ?? 'internal-only';
            }
            $stmt = $pdo->prepare("SELECT c.id, c.code, c.status, c.eta_date, c.expected_ship_date, c.actual_departure_date, c.actual_arrival_date, c.vessel_name, c.destination_country, c.destination, c.notes
                FROM containers c
                JOIN shipment_drafts sd ON sd.container_id = c.id
                JOIN shipment_draft_orders sdo ON sdo.shipment_draft_id = sd.id
                WHERE sdo.order_id = ? LIMIT 1");
            $stmt->execute([$id]);
            $container = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($container) {
                $row['container'] = $container;
            }
            jsonResponse(['data' => $row]);
            break;

        case 'PUT':
            if (!$id) jsonError('ID required', 400);
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
            $stmt->execute([$id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) jsonError('Order not found', 404);
            $customerId = (int) ($input['customer_id'] ?? $order['customer_id']);
            $supplierId = isset($input['supplier_id']) ? ($input['supplier_id'] ? (int) $input['supplier_id'] : null) : ($order['supplier_id'] ?? null);
            $expectedDate = resolveOrderExpectedReadyDate($input, $order);
            $highAlertNotes = isset($input['high_alert_notes']) ? (trim($input['high_alert_notes']) ?: null) : ($order['high_alert_notes'] ?? null);
            $requestedDestinationCountryId = array_key_exists('destination_country_id', $input) ? (!empty($input['destination_country_id']) ? (int) $input['destination_country_id'] : null) : ($order['destination_country_id'] ?? null);
            $destinationCountryId = orderTableHasColumn($pdo, 'orders', 'destination_country_id')
                ? normalizeOrderDestinationCountryId($pdo, $customerId, $requestedDestinationCountryId)
                : $requestedDestinationCountryId;
            $items = normalizeOrderItemsForPersistence($pdo, $customerId, $destinationCountryId, $supplierId ? (int) $supplierId : null, $input['items'] ?? [], (string) ($order['status'] ?? 'Draft'));
            $dupWarn = enforceDuplicateShippingCodePolicy($pdo, $customerId, (int) $id, $items);
            $pdo->beginTransaction();
            try {
                $updSets = "customer_id=?, supplier_id=?, expected_ready_date=?, high_alert_notes=?";
                $updParams = [$customerId, $supplierId, $expectedDate, $highAlertNotes];
                if (orderTableHasColumn($pdo, 'orders', 'destination_country_id')) {
                    $updSets .= ", destination_country_id=?";
                    $updParams[] = $destinationCountryId;
                }
                $updParams[] = $id;
                $pdo->prepare("UPDATE orders SET $updSets WHERE id=?")->execute($updParams);
                $pdo->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$id]);
                $hasItemSupplier = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'supplier_id'")->rowCount() > 0;
                $hasSellPrice = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'sell_price'")->rowCount() > 0;
                $hasOrderCartons = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'order_cartons'")->rowCount() > 0;
                $hasOrderQtyPerCarton = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'order_qty_per_carton'")->rowCount() > 0;
                $hasHsCode = orderTableHasColumn($pdo, 'order_items', 'hs_code');
                $hasCustomDesignRequired = orderTableHasColumn($pdo, 'order_items', 'custom_design_required');
                $hasCustomDesignNote = orderTableHasColumn($pdo, 'order_items', 'custom_design_note');
                $insCols = "order_id, product_id, item_no, shipping_code, cartons, qty_per_carton, quantity, unit, declared_cbm, declared_weight, item_length, item_width, item_height, unit_price, total_amount, notes, image_paths, description_cn, description_en";
                $insVals = "?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?";
                if ($hasItemSupplier) {
                    $insCols .= ", supplier_id";
                    $insVals .= ",?";
                }
                if ($hasSellPrice) {
                    $insCols .= ", sell_price";
                    $insVals .= ",?";
                }
                if ($hasOrderCartons) {
                    $insCols .= ", order_cartons";
                    $insVals .= ",?";
                }
                if ($hasOrderQtyPerCarton) {
                    $insCols .= ", order_qty_per_carton";
                    $insVals .= ",?";
                }
                if ($hasHsCode) {
                    $insCols .= ", hs_code";
                    $insVals .= ",?";
                }
                if ($hasCustomDesignRequired) {
                    $insCols .= ", custom_design_required";
                    $insVals .= ",?";
                }
                if ($hasCustomDesignNote) {
                    $insCols .= ", custom_design_note";
                    $insVals .= ",?";
                }
                $insItem = $pdo->prepare("INSERT INTO order_items ($insCols) VALUES ($insVals)");
                foreach ($items as $it) {
                    $qty = (float) ($it['quantity'] ?? 0);
                    $cartons = isset($it['cartons']) ? (int) $it['cartons'] : null;
                    $qtyPerCtn = isset($it['qty_per_carton']) ? (float) $it['qty_per_carton'] : null;
                    if ($cartons !== null && $qtyPerCtn !== null && $qtyPerCtn > 0) {
                        $qty = $cartons * $qtyPerCtn;
                    }
                    $l = isset($it['item_length']) ? (float) $it['item_length'] : null;
                    $w = isset($it['item_width']) ? (float) $it['item_width'] : null;
                    $h = isset($it['item_height']) ? (float) $it['item_height'] : null;
                    $unitPrice = isset($it['unit_price']) ? (float) $it['unit_price'] : null;
                    $sellPrice = isset($it['sell_price']) ? (float) $it['sell_price'] : null;
                    $totalAmount = isset($it['total_amount']) ? (float) $it['total_amount'] : ($unitPrice !== null && $qty > 0 ? $unitPrice * $qty : null);
                    $imagePaths = isset($it['image_paths']) && is_array($it['image_paths']) ? json_encode($it['image_paths']) : null;
                    $itemSupplierId = !empty($it['supplier_id']) ? (int) $it['supplier_id'] : $supplierId;
                    $params = [
                        $id,
                        !empty($it['product_id']) ? (int) $it['product_id'] : null,
                        $it['item_no'] ?? null,
                        $it['shipping_code'] ?? null,
                        $cartons,
                        $qtyPerCtn,
                        $qty,
                        $it['unit'] ?? 'pieces',
                        (float) ($it['declared_cbm'] ?? 0),
                        (float) ($it['declared_weight'] ?? 0),
                        $l,
                        $w,
                        $h,
                        $unitPrice,
                        $totalAmount,
                        $it['notes'] ?? null,
                        $imagePaths,
                        $it['description_cn'] ?? null,
                        $it['description_en'] ?? null
                    ];
                    if ($hasItemSupplier) {
                        $params[] = $itemSupplierId ?: null;
                    }
                    if ($hasSellPrice) {
                        $params[] = $sellPrice;
                    }
                    if ($hasOrderCartons) {
                        $params[] = $cartons;
                    }
                    if ($hasOrderQtyPerCarton) {
                        $params[] = $qtyPerCtn;
                    }
                    if ($hasHsCode) {
                        $params[] = $it['hs_code'] ?? null;
                    }
                    if ($hasCustomDesignRequired) {
                        $params[] = !empty($it['custom_design_required']) ? 1 : 0;
                    }
                    if ($hasCustomDesignNote) {
                        $params[] = $it['custom_design_note'] ?? null;
                    }
                    $insItem->execute($params);
                    syncProductFromOrderItem($pdo, $it);
                }
                $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('order',?,?,?,?)")
                    ->execute([$id, 'update', json_encode($input), $userId]);
                $pdo->commit();
                notifyCrossSupplierPriceDifferences($pdo, (int) $id, detectCrossSupplierPriceDifferences($pdo, (int) $id, (string) ($order['currency'] ?? 'USD'), $supplierId ?: null, $items));
                $oc = 'o.*, c.name as customer_name';
                $chkP = @$pdo->query("SHOW COLUMNS FROM customers LIKE 'priority_level'");
                if ($chkP && $chkP->rowCount() > 0) $oc .= ', c.priority_level as customer_priority_level, c.priority_note as customer_priority_note';
                $oc .= ', s.name as supplier_name';
                $stmt = $pdo->prepare("SELECT $oc FROM orders o JOIN customers c ON o.customer_id = c.id LEFT JOIN suppliers s ON o.supplier_id = s.id WHERE o.id = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $row['items'] = normalizeOrderItems(fetchOrderItems($pdo, (int) $id));
                jsonResponse(array_filter(['data' => $row, 'warning' => $dupWarn]));
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        case 'POST':
            if ($id === null) {
                $customerId = (int) ($input['customer_id'] ?? 0);
                $supplierId = (int) ($input['supplier_id'] ?? 0);
                $expectedDate = normalizeOptionalExpectedReadyDate($input['expected_ready_date'] ?? null);
                $currency = trim($input['currency'] ?? 'USD');
                if (!$customerId) {
                    jsonError('Missing required: customer_id', 400);
                }
                if (!in_array($currency, ['USD', 'RMB'], true)) {
                    jsonError('Currency must be USD or RMB', 400);
                }
                $highAlertNotes = isset($input['high_alert_notes']) && trim($input['high_alert_notes']) ? trim($input['high_alert_notes']) : null;
                $requestedDestinationCountryId = !empty($input['destination_country_id']) ? (int) $input['destination_country_id'] : null;
                $destinationCountryId = orderTableHasColumn($pdo, 'orders', 'destination_country_id')
                    ? normalizeOrderDestinationCountryId($pdo, $customerId, $requestedDestinationCountryId)
                    : $requestedDestinationCountryId;
                $items = normalizeOrderItemsForPersistence($pdo, $customerId, $destinationCountryId, $supplierId ?: null, $input['items'] ?? [], 'Draft');
                $dupWarn = enforceDuplicateShippingCodePolicy($pdo, $customerId, 0, $items);
                foreach ($items as $it) {
                    $qty = (float) ($it['quantity'] ?? 0);
                    $cartons = isset($it['cartons']) ? (int) $it['cartons'] : null;
                    $qtyPerCtn = isset($it['qty_per_carton']) ? (float) $it['qty_per_carton'] : null;
                    if ($cartons !== null && $qtyPerCtn !== null && $qtyPerCtn > 0) {
                        $qty = $cartons * $qtyPerCtn;
                    }
                    $unit = $it['unit'] ?? 'pieces';
                    $cbm = (float) ($it['declared_cbm'] ?? 0);
                    $weight = (float) ($it['declared_weight'] ?? 0);
                    if ($qty <= 0 || !in_array($unit, ['cartons', 'pieces']) || $cbm < 0 || $weight < 0) {
                        jsonError('Invalid item: quantity>0 (or cartons*qty_per_carton), unit in [cartons,pieces], cbm/weight>=0', 400);
                    }
                }
                $pdo->beginTransaction();
                try {
                    $hasDestCountry = orderTableHasColumn($pdo, 'orders', 'destination_country_id');
                    $insCols = "customer_id, supplier_id, expected_ready_date, currency, status, high_alert_notes, created_by";
                    $insVals = "?,?,?,?,'Draft',?,?";
                    $insParams = [$customerId, $supplierId ?: null, $expectedDate, $currency, $highAlertNotes, $userId];
                    if ($hasDestCountry) {
                        $insCols .= ", destination_country_id";
                        $insVals .= ",?";
                        $insParams[] = $destinationCountryId;
                    }
                    $pdo->prepare("INSERT INTO orders ($insCols) VALUES ($insVals)")->execute($insParams);
                    $orderId = (int) $pdo->lastInsertId();
                    $hasItemSupplier = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'supplier_id'")->rowCount() > 0;
                    $hasSellPrice = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'sell_price'")->rowCount() > 0;
                    $hasOrderCartons = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'order_cartons'")->rowCount() > 0;
                    $hasOrderQtyPerCarton = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'order_qty_per_carton'")->rowCount() > 0;
                    $hasHsCode = orderTableHasColumn($pdo, 'order_items', 'hs_code');
                    $hasCustomDesignRequired = orderTableHasColumn($pdo, 'order_items', 'custom_design_required');
                    $hasCustomDesignNote = orderTableHasColumn($pdo, 'order_items', 'custom_design_note');
                    $insCols = "order_id, product_id, item_no, shipping_code, cartons, qty_per_carton, quantity, unit, declared_cbm, declared_weight, item_length, item_width, item_height, unit_price, total_amount, notes, image_paths, description_cn, description_en";
                    $insVals = "?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?";
                    if ($hasItemSupplier) {
                        $insCols .= ", supplier_id";
                        $insVals .= ",?";
                    }
                    if ($hasSellPrice) {
                        $insCols .= ", sell_price";
                        $insVals .= ",?";
                    }
                    if ($hasOrderCartons) {
                        $insCols .= ", order_cartons";
                        $insVals .= ",?";
                    }
                    if ($hasOrderQtyPerCarton) {
                        $insCols .= ", order_qty_per_carton";
                        $insVals .= ",?";
                    }
                    if ($hasHsCode) {
                        $insCols .= ", hs_code";
                        $insVals .= ",?";
                    }
                    if ($hasCustomDesignRequired) {
                        $insCols .= ", custom_design_required";
                        $insVals .= ",?";
                    }
                    if ($hasCustomDesignNote) {
                        $insCols .= ", custom_design_note";
                        $insVals .= ",?";
                    }
                    $insItem = $pdo->prepare("INSERT INTO order_items ($insCols) VALUES ($insVals)");
                    foreach ($items as $it) {
                        $qty = (float) ($it['quantity'] ?? 0);
                        $cartons = isset($it['cartons']) ? (int) $it['cartons'] : null;
                        $qtyPerCtn = isset($it['qty_per_carton']) ? (float) $it['qty_per_carton'] : null;
                        if ($cartons !== null && $qtyPerCtn !== null && $qtyPerCtn > 0) {
                            $qty = $cartons * $qtyPerCtn;
                        }
                        $unitPrice = isset($it['unit_price']) ? (float) $it['unit_price'] : null;
                        $sellPrice = isset($it['sell_price']) ? (float) $it['sell_price'] : null;
                        $totalAmount = isset($it['total_amount']) ? (float) $it['total_amount'] : ($unitPrice !== null && $qty > 0 ? $unitPrice * $qty : null);
                        $imagePaths = isset($it['image_paths']) && is_array($it['image_paths']) ? json_encode($it['image_paths']) : null;
                        $itemSupplierId = !empty($it['supplier_id']) ? (int) $it['supplier_id'] : $supplierId;
                        $params = [
                            $orderId,
                            !empty($it['product_id']) ? (int) $it['product_id'] : null,
                            $it['item_no'] ?? null,
                            $it['shipping_code'] ?? null,
                            $cartons,
                            $qtyPerCtn,
                            $qty,
                            $it['unit'] ?? 'pieces',
                            (float) ($it['declared_cbm'] ?? 0),
                            (float) ($it['declared_weight'] ?? 0),
                            isset($it['item_length']) ? (float) $it['item_length'] : null,
                            isset($it['item_width']) ? (float) $it['item_width'] : null,
                            isset($it['item_height']) ? (float) $it['item_height'] : null,
                            $unitPrice,
                            $totalAmount,
                            $it['notes'] ?? null,
                            $imagePaths,
                            $it['description_cn'] ?? null,
                            $it['description_en'] ?? null
                        ];
                        if ($hasItemSupplier) {
                            $params[] = $itemSupplierId ?: null;
                        }
                        if ($hasSellPrice) {
                            $params[] = $sellPrice;
                        }
                        if ($hasOrderCartons) {
                            $params[] = $cartons;
                        }
                        if ($hasOrderQtyPerCarton) {
                            $params[] = $qtyPerCtn;
                        }
                        if ($hasHsCode) {
                            $params[] = $it['hs_code'] ?? null;
                        }
                        if ($hasCustomDesignRequired) {
                            $params[] = !empty($it['custom_design_required']) ? 1 : 0;
                        }
                        if ($hasCustomDesignNote) {
                            $params[] = $it['custom_design_note'] ?? null;
                        }
                        $insItem->execute($params);
                        syncProductFromOrderItem($pdo, $it);
                    }
                    $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('order',?,?,?,?)")
                        ->execute([$orderId, 'create', json_encode(['status' => 'Draft']), $userId]);
                    (new NotificationService($pdo))->notifyOrderCreated($orderId, $userId);
                    $pdo->commit();
                    notifyCrossSupplierPriceDifferences($pdo, $orderId, detectCrossSupplierPriceDifferences($pdo, $orderId, $currency, $supplierId ?: null, $items));
                    $oc = 'o.*, c.name as customer_name';
                    $chkP = @$pdo->query("SHOW COLUMNS FROM customers LIKE 'priority_level'");
                    if ($chkP && $chkP->rowCount() > 0) $oc .= ', c.priority_level as customer_priority_level, c.priority_note as customer_priority_note';
                    $oc .= ', s.name as supplier_name';
                    $stmt = $pdo->prepare("SELECT $oc FROM orders o JOIN customers c ON o.customer_id = c.id LEFT JOIN suppliers s ON o.supplier_id = s.id WHERE o.id = ?");
                    $stmt->execute([$orderId]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $row['items'] = normalizeOrderItems(fetchOrderItems($pdo, $orderId));
                    jsonResponse(array_filter(['data' => $row, 'warning' => $dupWarn]), 201);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
            }
            if ($id && $action === 'receive') {
                $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
                $stmt->execute([$id]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$order) jsonError('Order not found', 404);
                $allowed = ['Approved', 'InTransitToWarehouse'];
                if (!in_array($order['status'], $allowed, true)) {
                    jsonError('Order must be Approved or InTransitToWarehouse to receive', 400);
                }
                $actualCartons = (int) ($input['actual_cartons'] ?? 0);
                $actualCbm = (float) ($input['actual_cbm'] ?? 0);
                $actualWeight = (float) ($input['actual_weight'] ?? 0);
                $condition = $input['condition'] ?? 'good';
                if (!in_array($condition, ['good', 'damaged', 'partial'])) $condition = 'good';
                $photoPaths = normalizeStoredUploadPathList($input['photo_paths'] ?? []);
                $itemsInput = $input['items'] ?? [];
                $config = require dirname(__DIR__, 2) . '/config/config.php';
                $thresholdPct = $config['variance_threshold_percent'] ?? 10;
                $thresholdAbs = $config['variance_threshold_abs_cbm'] ?? 0.1;
                $photoEvidencePerItem = (int) ($config['photo_evidence_per_item'] ?? 0);
                $itemLevelEnabled = (int) ($config['item_level_receiving_enabled'] ?? 0);

                $orderItems = $pdo->prepare("SELECT id, declared_cbm, declared_weight FROM order_items WHERE order_id = ?");
                $orderItems->execute([$id]);
                $orderItemsRows = $orderItems->fetchAll(PDO::FETCH_ASSOC);
                $declaredCbm = array_sum(array_column($orderItemsRows, 'declared_cbm'));
                $declaredWeight = array_sum(array_column($orderItemsRows, 'declared_weight'));

                $hasVariance = false;
                $itemVariances = [];
                if (!empty($itemsInput)) {
                    $sumCbm = 0;
                    $sumWeight = 0;
                    $sumCartons = 0;
                    $errors = [];
                    foreach ($itemsInput as $idx => $it) {
                        $oiId = (int) ($it['order_item_id'] ?? 0);
                        $oi = null;
                        foreach ($orderItemsRows as $o) {
                            if ((int) $o['id'] === $oiId) {
                                $oi = $o;
                                break;
                            }
                        }
                        if (!$oi) {
                            $errors["items.$idx.order_item_id"] = 'Invalid order_item_id';
                            continue;
                        }
                        $aCbm = isset($it['actual_cbm']) ? (float) $it['actual_cbm'] : null;
                        $aWeight = isset($it['actual_weight']) ? (float) $it['actual_weight'] : null;
                        $aCartons = isset($it['actual_cartons']) ? (int) $it['actual_cartons'] : null;
                        $itCond = $it['condition'] ?? 'good';
                        if (!in_array($itCond, ['good', 'damaged', 'partial'])) $itCond = 'good';
                        $itPhotos = normalizeStoredUploadPathList($it['photo_paths'] ?? []);
                        $decCbm = (float) $oi['declared_cbm'];
                        $decWeight = (float) $oi['declared_weight'];
                        $varPct = $decCbm > 0 ? abs(($aCbm ?? 0) - $decCbm) / $decCbm * 100 : 0;
                        $varAbs = abs(($aCbm ?? 0) - $decCbm);
                        $itemVar = $varPct >= $thresholdPct || $varAbs >= $thresholdAbs || $itCond !== 'good';
                        $itemVariances[$oiId] = $itemVar;
                        if ($itemVar) $hasVariance = true;
                        if ($aCbm !== null) $sumCbm += $aCbm;
                        if ($aWeight !== null) $sumWeight += $aWeight;
                        if ($aCartons !== null) $sumCartons += $aCartons;
                        if ($photoEvidencePerItem && $itemVar && empty($itPhotos)) {
                            $errors["items.$idx.photo_paths"] = 'Photo evidence required for item with variance';
                        }
                    }
                    if (!empty($errors)) jsonError('Validation failed', 400, $errors);
                    $tolerance = 0.01;
                    if (abs($sumCbm - $actualCbm) > $tolerance || abs($sumWeight - $actualWeight) > $tolerance) {
                        jsonError('Item-level totals must match order-level actuals (CBM/weight)', 400);
                    }
                } else {
                    $variancePct = $declaredCbm > 0 ? abs($actualCbm - $declaredCbm) / $declaredCbm * 100 : 0;
                    $varianceAbs = abs($actualCbm - $declaredCbm);
                    $hasVariance = $variancePct >= $thresholdPct || $varianceAbs >= $thresholdAbs || $condition !== 'good';
                }
                if ($hasVariance && empty($photoPaths)) {
                    jsonError('Evidence photos required when variance or damage is present', 400);
                }
                if ($itemLevelEnabled && empty($itemsInput)) {
                    jsonError('Item-level receiving is required; provide items array', 400);
                }

                $pdo->beginTransaction();
                try {
                    $pdo->prepare("INSERT INTO warehouse_receipts (order_id, actual_cartons, actual_cbm, actual_weight, receipt_condition, notes, received_by) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$id, $actualCartons, $actualCbm, $actualWeight, $condition, $input['notes'] ?? null, $userId]);
                    $receiptId = (int) $pdo->lastInsertId();
                    $insPhoto = $pdo->prepare("INSERT INTO warehouse_receipt_photos (receipt_id, file_path) VALUES (?,?)");
                    foreach ($photoPaths as $path) {
                        $insPhoto->execute([$receiptId, $path]);
                    }
                    if (!empty($itemsInput)) {
                        $insItem = $pdo->prepare("INSERT INTO warehouse_receipt_items (receipt_id, order_item_id, actual_cartons, actual_cbm, actual_weight, receipt_condition, variance_detected, notes) VALUES (?,?,?,?,?,?,?,?)");
                        $insItemPhoto = $pdo->prepare("INSERT INTO warehouse_receipt_item_photos (receipt_item_id, file_path) VALUES (?,?)");
                        foreach ($itemsInput as $it) {
                            $oiId = (int) ($it['order_item_id'] ?? 0);
                            $aCbm = isset($it['actual_cbm']) ? (float) $it['actual_cbm'] : null;
                            $aWeight = isset($it['actual_weight']) ? (float) $it['actual_weight'] : null;
                            $aCartons = isset($it['actual_cartons']) ? (int) $it['actual_cartons'] : null;
                            $itCond = in_array($it['condition'] ?? 'good', ['good', 'damaged', 'partial']) ? ($it['condition'] ?? 'good') : 'good';
                            $varDet = $itemVariances[$oiId] ?? 0;
                            $insItem->execute([$receiptId, $oiId, $aCartons, $aCbm, $aWeight, $itCond, $varDet ? 1 : 0, $it['notes'] ?? null]);
                            $riId = (int) $pdo->lastInsertId();
                            foreach (normalizeStoredUploadPathList($it['photo_paths'] ?? []) as $p) {
                                $insItemPhoto->execute([$riId, $p]);
                            }
                        }
                    }
                    $newStatus = $hasVariance ? 'Confirmed' : 'ReadyForConsolidation';
                    $confirmToken = null;
                    if ($hasVariance) {
                        $confirmToken = bin2hex(random_bytes(24));
                        $pdo->prepare("UPDATE orders SET status=?, confirmation_token=? WHERE id=?")->execute([$newStatus, $confirmToken, $id]);
                    } else {
                        $pdo->prepare("UPDATE orders SET status=? WHERE id=?")->execute([$newStatus, $id]);
                    }
                    $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('order',?,?,?,?)")
                        ->execute([$id, 'receive', json_encode(['actual_cbm' => $actualCbm, 'actual_weight' => $actualWeight, 'status' => $newStatus, 'receipt_id' => $receiptId]), $userId]);
                    logClms('order_received', ['order_id' => (int) $id, 'receipt_id' => $receiptId, 'user_id' => $userId, 'item_level' => !empty($itemsInput), 'variance_detected' => $hasVariance]);
                    (new NotificationService($pdo))->notifyOrderReceived((int) $id, $userId, $hasVariance, $confirmToken);
                    $pdo->commit();
                    jsonResponse(['data' => ['status' => $newStatus, 'receipt_id' => $receiptId, 'variance_detected' => $hasVariance]]);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
            }
            if ($id && $action === 'confirm') {
                $token = $input['token'] ?? null;
                $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
                $stmt->execute([$id]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$order) jsonError('Order not found', 404);
                if (trim((string) ($order['confirmation_token'] ?? '')) === '') {
                    jsonError('Order no longer has a pending customer follow-up response', 400);
                }
                // Allow staff session-based confirm OR token-based public confirm
                if ($token && !hash_equals((string)($order['confirmation_token'] ?? ''), $token)) {
                    jsonError('Invalid or expired customer follow-up token', 403);
                }
                OrderReceiptWorkflowService::acceptAutoConfirmedOrder($pdo, (int) $id, $userId, 'confirm');
                jsonResponse(['data' => ['status' => 'ReadyForConsolidation']]);
            }
            if ($id && $action === 'reset-after-decline') {
                requireRole(['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin']);
                OrderReceiptWorkflowService::resetDeclinedOrder($pdo, (int) $id, $userId, trim((string) ($input['reason'] ?? '')) ?: null);
                jsonResponse(['data' => ['status' => 'Submitted']]);
            }
            if ($id && in_array($action, ['submit', 'approve'], true)) {
                $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
                $stmt->execute([$id]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$order) jsonError('Order not found', 404);
                if ($action === 'submit') {
                    OrderStateService::validateTransition($order['status'], 'Submitted');
                    $si = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE order_id = ?");
                    $si->execute([$id]);
                    if ((int) $si->fetchColumn() === 0) {
                        jsonError('Order must have at least one item to submit', 400);
                    }
                    $config = require dirname(__DIR__, 2) . '/config/config.php';
                    $minPhotos = (int) ($config['min_photos_per_item'] ?? 0);
                    if ($minPhotos > 0) {
                        $itemsWithPhotos = $pdo->prepare("SELECT id, image_paths FROM order_items WHERE order_id = ?");
                        $itemsWithPhotos->execute([$id]);
                        while ($row = $itemsWithPhotos->fetch(PDO::FETCH_ASSOC)) {
                            $paths = $row['image_paths'] ? (json_decode($row['image_paths'], true) ?? []) : [];
                            if (!is_array($paths) || count($paths) < $minPhotos) {
                                jsonError("Each item must have at least $minPhotos photo(s). Item #{$row['id']} has insufficient photos.", 400);
                            }
                        }
                    }
                    $pdo->prepare("UPDATE orders SET status='Submitted' WHERE id=?")->execute([$id]);
                    $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, user_id) VALUES ('order',?,'submit',?)")->execute([$id, $userId]);
                    (new NotificationService($pdo))->notifyOrderSubmitted((int) $id);
                    jsonResponse(['data' => ['status' => 'Submitted']]);
                }
                if ($action === 'approve') {
                    OrderStateService::validateTransition($order['status'], 'Approved');
                    $pdo->prepare("UPDATE orders SET status='Approved' WHERE id=?")->execute([$id]);
                    $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, user_id) VALUES ('order',?,'approve',?)")->execute([$id, $userId]);
                    (new NotificationService($pdo))->notifyOrderApproved((int) $id);
                    jsonResponse(['data' => ['status' => 'Approved']]);
                }
            }
            jsonError('Invalid action', 400);
            break;
    }

    jsonError('Method not allowed', 405);
};
