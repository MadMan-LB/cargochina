<?php

/**
 * Containers API - CRUD, status, export
 * Statuses: planning | to_go | on_route | arrived | available
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 2) . '/services/OrderCountryService.php';
require_once dirname(__DIR__, 2) . '/services/OrderExcelService.php';

function containerTableHasColumn(PDO $pdo, string $table, string $column): bool
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

function buildContainerLineMetrics(array $item): array
{
    $cartons = isset($item['order_cartons']) && $item['order_cartons'] !== null && $item['order_cartons'] !== ''
        ? (float) $item['order_cartons']
        : (float) ($item['cartons'] ?? 0);
    $qtyPerCarton = isset($item['order_qty_per_carton']) && $item['order_qty_per_carton'] !== null && $item['order_qty_per_carton'] !== ''
        ? (float) $item['order_qty_per_carton']
        : (float) ($item['qty_per_carton'] ?? 0);
    $quantity = (float) ($item['quantity'] ?? 0);
    if ($quantity <= 0 && $cartons > 0 && $qtyPerCarton > 0) {
        $quantity = $cartons * $qtyPerCarton;
    }

    $sellPrice = isset($item['sell_price']) && $item['sell_price'] !== null && $item['sell_price'] !== ''
        ? (float) $item['sell_price']
        : null;
    $unitPrice = isset($item['unit_price']) && $item['unit_price'] !== null && $item['unit_price'] !== ''
        ? (float) $item['unit_price']
        : null;
    $storedAmount = isset($item['total_amount']) && $item['total_amount'] !== null && $item['total_amount'] !== ''
        ? (float) $item['total_amount']
        : null;

    if ($sellPrice !== null && $quantity > 0) {
        $amount = $sellPrice * $quantity;
    } elseif ($storedAmount !== null) {
        $amount = $storedAmount;
    } elseif ($unitPrice !== null && $quantity > 0) {
        $amount = $unitPrice * $quantity;
    } else {
        $amount = 0.0;
    }

    return [
        'cartons' => $cartons,
        'quantity' => $quantity,
        'cbm' => (float) ($item['declared_cbm'] ?? 0),
        'weight' => (float) ($item['declared_weight'] ?? 0),
        'amount' => $amount,
    ];
}

function loadContainerOrderTotals(PDO $pdo, int $orderId): array
{
    $columns = [
        'id',
        'cartons',
        'qty_per_carton',
        'quantity',
        'declared_cbm',
        'declared_weight',
        'unit_price',
        'total_amount',
    ];
    if (containerTableHasColumn($pdo, 'order_items', 'order_cartons')) {
        $columns[] = 'order_cartons';
    }
    if (containerTableHasColumn($pdo, 'order_items', 'order_qty_per_carton')) {
        $columns[] = 'order_qty_per_carton';
    }
    if (containerTableHasColumn($pdo, 'order_items', 'sell_price')) {
        $columns[] = 'sell_price';
    }

    $stmt = $pdo->prepare("SELECT " . implode(', ', $columns) . " FROM order_items WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totals = [
        'items' => count($rows),
        'total_ctns' => 0.0,
        'total_qty' => 0.0,
        'total_cbm' => 0.0,
        'total_weight' => 0.0,
        'total_amount' => 0.0,
    ];

    foreach ($rows as $row) {
        $line = buildContainerLineMetrics($row);
        $totals['total_ctns'] += $line['cartons'];
        $totals['total_qty'] += $line['quantity'];
        $totals['total_cbm'] += $line['cbm'];
        $totals['total_weight'] += $line['weight'];
        $totals['total_amount'] += $line['amount'];
    }

    return [
        'items' => (int) $totals['items'],
        'total_ctns' => round($totals['total_ctns'], 4),
        'total_qty' => round($totals['total_qty'], 4),
        'total_cbm' => round($totals['total_cbm'], 4),
        'total_weight' => round($totals['total_weight'], 2),
        'total_amount' => round($totals['total_amount'], 2),
    ];
}

function fetchContainerUsage(PDO $pdo, int $containerId): array
{
    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(oi.declared_cbm), 0) AS used_cbm,
            COALESCE(SUM(oi.declared_weight), 0) AS used_weight,
            COUNT(DISTINCT ord.order_id) AS order_count
         FROM (
            SELECT DISTINCT sdo.order_id
            FROM shipment_draft_orders sdo
            JOIN shipment_drafts sd ON sdo.shipment_draft_id = sd.id
            WHERE sd.container_id = ?
         ) ord
         LEFT JOIN order_items oi ON oi.order_id = ord.order_id"
    );
    $stmt->execute([$containerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'used_cbm' => round((float) ($row['used_cbm'] ?? 0), 4),
        'used_weight' => round((float) ($row['used_weight'] ?? 0), 2),
        'order_count' => (int) ($row['order_count'] ?? 0),
    ];
}

function enrichContainerDestination(PDO $pdo, array $container): array
{
    static $countryCache = [];

    $countryId = OrderCountryService::resolveContainerDestinationCountryId($pdo, $container);
    $container['destination_country_id'] = $countryId ?: null;
    $container['destination_country_name'] = null;
    $container['destination_country_code'] = null;

    if ($countryId) {
        if (!array_key_exists($countryId, $countryCache)) {
            $stmt = $pdo->prepare("SELECT id, name, code FROM countries WHERE id = ?");
            $stmt->execute([$countryId]);
            $countryCache[$countryId] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!empty($countryCache[$countryId])) {
            $container['destination_country_name'] = $countryCache[$countryId]['name'];
            $container['destination_country_code'] = $countryCache[$countryId]['code'];
        }
    }

    return $container;
}

function outputContainerOrdersCsv(array $container, array $ordersWithItems): void
{
    $code = preg_replace('/[^a-zA-Z0-9_.-]/', '_', (string) ($container['code'] ?? 'container'));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="container_' . $code . '_orders.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Container', (string) ($container['code'] ?? '')]);
    fputcsv($out, ['']);
    fputcsv($out, ['Order ID', 'Customer', 'Supplier', 'Item No', 'Shipping Code', 'Description', 'Cartons', 'Qty/Carton', 'Total Qty', 'Unit Price', 'Total Amount', 'Declared CBM', 'Declared Weight', 'Photo Count']);
    foreach ($ordersWithItems as $data) {
        $order = $data['order'] ?? [];
        foreach (($data['items'] ?? []) as $item) {
            $imagePaths = $item['image_paths'] ?? [];
            if (is_string($imagePaths)) {
                $imagePaths = json_decode($imagePaths, true) ?: [];
            }
            $cartons = (float) ($item['cartons'] ?? 0);
            $qtyPerCarton = (float) ($item['qty_per_carton'] ?? 0);
            $totalQty = ($cartons > 0 && $qtyPerCarton > 0)
                ? $cartons * $qtyPerCarton
                : (float) ($item['quantity'] ?? 0);
            $unitPrice = isset($item['sell_price']) && $item['sell_price'] !== null && $item['sell_price'] !== ''
                ? (float) $item['sell_price']
                : (float) ($item['unit_price'] ?? 0);
            fputcsv($out, [
                (int) ($order['id'] ?? 0),
                OrderExcelService::formatCustomerDisplay($order, $data['items'] ?? []),
                (string) ($item['supplier_name'] ?? $order['supplier_name'] ?? ''),
                (string) ($item['item_no'] ?? ''),
                (string) ($item['shipping_code'] ?? ''),
                (string) ($item['description_en'] ?? $item['description_cn'] ?? ''),
                $cartons ?: '',
                $qtyPerCarton ?: '',
                $totalQty ?: '',
                $unitPrice ?: '',
                $totalQty > 0 && $unitPrice ? round($totalQty * $unitPrice, 4) : '',
                round((float) ($item['declared_cbm'] ?? 0), 6),
                round((float) ($item['declared_weight'] ?? 0), 4),
                count($imagePaths),
            ]);
        }
    }
    fclose($out);
    exit;
}

function fetchContainerExportExpenses(PDO $pdo, int $containerId, array $orderIds): array
{
    if ($containerId <= 0 && !$orderIds) {
        return [];
    }

    $clauses = [];
    $params = [];
    if ($containerId > 0) {
        $clauses[] = 'e.container_id = ?';
        $params[] = $containerId;
    }
    if ($orderIds) {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $clauses[] = "e.order_id IN ($placeholders)";
        foreach ($orderIds as $orderId) {
            $params[] = (int) $orderId;
        }
    }

    $sql = "SELECT e.*,
                ec.name as category_name,
                ec.category_type,
                o.customer_id as order_customer_id,
                c.name as customer_name,
                s.name as supplier_name
            FROM expenses e
            JOIN expense_categories ec ON e.category_id = ec.id
            LEFT JOIN orders o ON e.order_id = o.id
            LEFT JOIN customers c ON e.customer_id = c.id
            LEFT JOIN suppliers s ON e.supplier_id = s.id
            WHERE (" . implode(' OR ', $clauses) . ")
            ORDER BY e.expense_date ASC, e.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();

    switch ($method) {

        // -------------------------------------------------------------------------
        case 'GET':
            if ($id === 'search') {
                $q = trim($_GET['q'] ?? '');
                if (strlen($q) < 1) {
                    jsonResponse(['data' => []]);
                }
                $like = '%' . preg_replace('/\s+/', '%', $q) . '%';
                $coll = 'COLLATE utf8mb4_unicode_ci';
                $chkNotes = @$pdo->query("SHOW COLUMNS FROM containers LIKE 'notes'");
                $notesCond = ($chkNotes && $chkNotes->rowCount() > 0) ? " OR (notes $coll LIKE ?)" : '';
                $sql = "SELECT id, code, max_cbm, max_weight, status FROM containers WHERE ((code $coll LIKE ?) OR id = ?$notesCond) ORDER BY id DESC LIMIT 20";
                $stmt = $pdo->prepare($sql);
                $execParams = [$like, is_numeric($q) ? (int) $q : 0];
                if ($notesCond) $execParams[] = $like;
                $stmt->execute($execParams);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                jsonResponse(['data' => $rows]);
            }
            if ($id && $action === 'orders') {
                $stmt = $pdo->prepare("SELECT * FROM containers WHERE id = ?");
                $stmt->execute([$id]);
                $container = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$container) jsonError('Container not found', 404);
                $custCols = 'c.name as customer_name';
                $chkPrio = @$pdo->query("SHOW COLUMNS FROM customers LIKE 'priority_level'");
                if ($chkPrio && $chkPrio->rowCount() > 0) $custCols .= ', c.priority_level as customer_priority_level, c.priority_note as customer_priority_note';
                $stmt = $pdo->prepare(
                    "SELECT o.id, o.status, o.expected_ready_date, o.currency, o.high_alert_notes,
                            $custCols,
                            s.name as supplier_name,
                            MIN(sd.id) as draft_id
                     FROM shipment_draft_orders sdo
                     JOIN shipment_drafts sd ON sdo.shipment_draft_id = sd.id
                     JOIN orders o ON sdo.order_id = o.id
                     JOIN customers c ON o.customer_id = c.id
                     LEFT JOIN suppliers s ON o.supplier_id = s.id
                     WHERE sd.container_id = ?
                     GROUP BY o.id, o.status, o.expected_ready_date, o.currency, o.high_alert_notes, c.name, s.name" .
                    (($chkPrio && $chkPrio->rowCount() > 0) ? ", c.priority_level, c.priority_note" : "") .
                    " ORDER BY draft_id, o.id"
                );
                $stmt->execute([$id]);
                $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $totals = [
                    'order_count' => count($orders),
                    'item_count' => 0,
                    'cartons' => 0.0,
                    'quantity' => 0.0,
                    'cbm' => 0.0,
                    'weight' => 0.0,
                    'amount' => 0.0,
                ];
                foreach ($orders as &$ord) {
                    $t = loadContainerOrderTotals($pdo, (int) $ord['id']);
                    $ord += $t;
                    $totals['item_count'] += (int) $t['items'];
                    $totals['cartons'] += (float) $t['total_ctns'];
                    $totals['quantity'] += (float) $t['total_qty'];
                    $totals['cbm'] += (float) $t['total_cbm'];
                    $totals['weight'] += (float) $t['total_weight'];
                    $totals['amount'] += (float) $t['total_amount'];
                }
                unset($ord);
                $usage = fetchContainerUsage($pdo, (int) $id);
                $container['used_cbm']    = $usage['used_cbm'];
                $container['used_weight'] = $usage['used_weight'];
                $container['fill_pct_cbm'] = $container['max_cbm'] > 0
                    ? round($container['used_cbm'] / $container['max_cbm'] * 100, 1) : 0;
                $container = enrichContainerDestination($pdo, $container);
                $draftsStmt = $pdo->prepare(
                    "SELECT sd.id, sd.status, sd.container_number, sd.booking_number, sd.tracking_url,
                            (SELECT COUNT(*) FROM shipment_draft_orders WHERE shipment_draft_id = sd.id) as order_count
                     FROM shipment_drafts sd WHERE sd.container_id = ? ORDER BY sd.id"
                );
                $draftsStmt->execute([$id]);
                $drafts = $draftsStmt->fetchAll(PDO::FETCH_ASSOC);
                jsonResponse(['data' => ['container' => $container, 'orders' => $orders, 'drafts' => $drafts, 'totals' => [
                    'order_count' => (int) $totals['order_count'],
                    'item_count' => (int) $totals['item_count'],
                    'cartons' => round($totals['cartons'], 4),
                    'quantity' => round($totals['quantity'], 4),
                    'cbm' => round($totals['cbm'], 4),
                    'weight' => round($totals['weight'], 2),
                    'amount' => round($totals['amount'], 2),
                ]]]);
            }

            if ($id && $action === 'export') {
                $stmt = $pdo->prepare("SELECT id, code FROM containers WHERE id = ?");
                $stmt->execute([$id]);
                $container = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$container) jsonError('Container not found', 404);
                $stmt = $pdo->prepare("SELECT DISTINCT sdo.order_id FROM shipment_draft_orders sdo JOIN shipment_drafts sd ON sdo.shipment_draft_id = sd.id WHERE sd.container_id = ? ORDER BY sdo.order_id");
                $stmt->execute([$id]);
                $orderIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'order_id');
                if (empty($orderIds)) jsonError('No orders in this container', 404);
                $suppCols = 's.name as supplier_name, s.phone as supplier_phone, s.factory_location as supplier_factory';
                $chk = @$pdo->query("SHOW COLUMNS FROM suppliers LIKE 'address'");
                if ($chk && $chk->rowCount() > 0) $suppCols .= ', s.address as supplier_address';
                $chk = @$pdo->query("SHOW COLUMNS FROM suppliers LIKE 'fax'");
                if ($chk && $chk->rowCount() > 0) $suppCols .= ', s.fax as supplier_fax';
                $chk = @$pdo->query("SHOW COLUMNS FROM suppliers LIKE 'payment_links'");
                if ($chk && $chk->rowCount() > 0) $suppCols .= ', s.payment_links as supplier_payment_links';
                $chk = @$pdo->query("SHOW COLUMNS FROM suppliers LIKE 'payment_facility_days'");
                if ($chk && $chk->rowCount() > 0) $suppCols .= ', s.payment_facility_days as supplier_payment_facility_days';
                $custCols = 'c.name as customer_name';
                $chk = @$pdo->query("SHOW COLUMNS FROM customers LIKE 'phone'");
                if ($chk && $chk->rowCount() > 0) $custCols .= ', c.phone as customer_phone';
                require_once dirname(__DIR__, 2) . '/services/OrderExcelService.php';
                require_once __DIR__ . '/orders.php';
                $expenses = fetchContainerExportExpenses($pdo, (int) $id, array_map('intval', $orderIds));
                $ordersWithItems = [];
                foreach ($orderIds as $oid) {
                    $stmt = $pdo->prepare("SELECT o.*, $custCols, $suppCols FROM orders o JOIN customers c ON o.customer_id = c.id LEFT JOIN suppliers s ON o.supplier_id = s.id WHERE o.id = ?");
                    $stmt->execute([$oid]);
                    $order = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$order) continue;
                    $items = normalizeOrderItems(fetchOrderItems($pdo, (int) $oid));
                    $ordersWithItems[] = ['order' => $order, 'items' => $items];
                }
                $format = strtolower(trim((string) ($_GET['format'] ?? 'xlsx')));
                $code = preg_replace('/[^a-zA-Z0-9_-]/', '_', $container['code'] ?? 'container');
                if ($format === 'csv') {
                    outputContainerOrdersCsv($container, $ordersWithItems);
                }
                (new OrderExcelService())->exportOrders(
                    $ordersWithItems,
                    'container_' . $code . '_orders.xlsx',
                    [
                        'container' => $container,
                        'expenses' => $expenses,
                    ]
                );
            }

            if ($id === null) {
                // Enhanced list: include fill stats + optional search
                $search = trim($_GET['q'] ?? '');
                $statusParam = $_GET['status'] ?? null;
                $statusFilter = is_array($statusParam)
                    ? array_values(array_filter(array_map('trim', $statusParam), 'strlen'))
                    : (trim((string) $statusParam) !== '' ? [trim((string) $statusParam)] : []);
                $statusMode = strtolower(trim((string) ($_GET['status_mode'] ?? 'include')));
                $statusMode = $statusMode === 'exclude' ? 'exclude' : 'include';
                $sql = "SELECT c.*,
                    COALESCE(cu.used_cbm, 0) AS used_cbm,
                    COALESCE(cu.used_weight, 0) AS used_weight,
                    COALESCE(cu.order_count, 0) AS order_count
                FROM containers c
                LEFT JOIN (
                    SELECT ord.container_id,
                           COALESCE(SUM(oi.declared_cbm), 0) AS used_cbm,
                           COALESCE(SUM(oi.declared_weight), 0) AS used_weight,
                           COUNT(DISTINCT ord.order_id) AS order_count
                    FROM (
                        SELECT DISTINCT sd.container_id, sdo.order_id
                        FROM shipment_draft_orders sdo
                        JOIN shipment_drafts sd ON sdo.shipment_draft_id = sd.id
                        WHERE sd.container_id IS NOT NULL
                    ) ord
                    LEFT JOIN order_items oi ON oi.order_id = ord.order_id
                    GROUP BY ord.container_id
                ) cu ON cu.container_id = c.id
                WHERE 1=1";
                $params = [];
                if ($search !== '') {
                    $like = '%' . $search . '%';
                    $coll = 'COLLATE utf8mb4_unicode_ci';
                    $innerCond = "(cu2.name $coll LIKE ?) OR (cu2.code $coll LIKE ?)";
                    $innerParams = [$like, $like];
                    $chkCust = $pdo->query("SHOW COLUMNS FROM customers LIKE 'phone'");
                    if ($chkCust && $chkCust->rowCount() > 0) {
                        $innerCond .= " OR (cu2.phone $coll LIKE ?)";
                        $innerParams[] = $like;
                    }
                    $innerCond .= " OR (oi2.shipping_code $coll LIKE ?) OR (oi2.item_no $coll LIKE ?) OR (oi2.description_cn $coll LIKE ?) OR (oi2.description_en $coll LIKE ?)";
                    $innerParams = array_merge($innerParams, [$like, $like, $like, $like]);
                    if (is_numeric($search)) {
                        $innerCond .= " OR o2.id = ?";
                        $innerParams[] = (int) $search;
                    }
                    $sql .= " AND ((c.code $coll LIKE ?) OR EXISTS (
                        SELECT 1 FROM shipment_draft_orders sdo2
                        JOIN shipment_drafts sd2 ON sdo2.shipment_draft_id = sd2.id
                        JOIN orders o2 ON sdo2.order_id = o2.id
                        JOIN customers cu2 ON o2.customer_id = cu2.id
                        LEFT JOIN order_items oi2 ON oi2.order_id = o2.id
                        WHERE sd2.container_id = c.id AND (" . $innerCond . ")
                    ))";
                    $params[] = $like;
                    foreach ($innerParams as $p) $params[] = $p;
                }
                if (!empty($statusFilter)) {
                    $placeholders = implode(',', array_fill(0, count($statusFilter), '?'));
                    $sql .= $statusMode === 'exclude'
                        ? " AND c.status NOT IN ($placeholders)"
                        : " AND c.status IN ($placeholders)";
                    $params = array_merge($params, $statusFilter);
                }
                $sql .= " ORDER BY c.id DESC";
                $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
                if ($params) $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as &$r) {
                    $r['used_cbm']    = (float) $r['used_cbm'];
                    $r['used_weight'] = (float) $r['used_weight'];
                    $r['order_count'] = (int)   $r['order_count'];
                    $r['fill_pct_cbm'] = $r['max_cbm'] > 0
                        ? round($r['used_cbm'] / $r['max_cbm'] * 100, 1) : 0;
                    $r = enrichContainerDestination($pdo, $r);
                }
                unset($r);
                jsonResponse(['data' => $rows]);
            }

            $stmt = $pdo->prepare("SELECT * FROM containers WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) jsonError('Container not found', 404);
            jsonResponse(['data' => enrichContainerDestination($pdo, $row)]);
            break;

        // -------------------------------------------------------------------------
        case 'PUT':
            if (!$id) jsonError('Container ID required', 400);
            $stmt = $pdo->prepare("SELECT * FROM containers WHERE id = ?");
            $stmt->execute([$id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) jsonError('Container not found', 404);
            $allowed_statuses = ['planning', 'to_go', 'on_route', 'arrived', 'available'];
            $sets = [];
            $params = [];
            $usage = null;
            if (array_key_exists('code', $input)) {
                $code = trim((string) ($input['code'] ?? ''));
                if ($code === '') jsonError('Code cannot be empty', 400);
                $chk = $pdo->prepare("SELECT id FROM containers WHERE code = ? AND id != ?");
                $chk->execute([$code, $id]);
                if ($chk->fetch()) jsonError('Code already in use by another container', 400);
                $sets[] = 'code = ?';
                $params[] = $code;
            }
            if (array_key_exists('max_cbm', $input)) {
                $maxCbm = (float) $input['max_cbm'];
                if ($maxCbm <= 0) jsonError('Max CBM must be positive', 400);
                $usage = fetchContainerUsage($pdo, (int) $id);
                $usedCbm = (float) $usage['used_cbm'];
                if ($usedCbm > $maxCbm) jsonError('Max CBM cannot be less than used CBM (' . round($usedCbm, 2) . ')', 400);
                $sets[] = 'max_cbm = ?';
                $params[] = $maxCbm;
            }
            if (array_key_exists('max_weight', $input)) {
                $maxWeight = (float) $input['max_weight'];
                if ($maxWeight <= 0) jsonError('Max weight must be positive', 400);
                $usage = $usage ?? fetchContainerUsage($pdo, (int) $id);
                $usedWeight = (float) $usage['used_weight'];
                if ($usedWeight > $maxWeight) jsonError('Max weight cannot be less than used weight (' . round($usedWeight, 2) . ' kg)', 400);
                $sets[] = 'max_weight = ?';
                $params[] = $maxWeight;
            }
            if (isset($input['status'])) {
                if (!in_array($input['status'], $allowed_statuses)) {
                    jsonError('Invalid status. Allowed: ' . implode(', ', $allowed_statuses), 400);
                }
                $sets[] = 'status = ?';
                $params[] = $input['status'];
            }
            if (array_key_exists('notes', $input)) {
                $sets[] = 'notes = ?';
                $params[] = $input['notes'] ?: null;
            }
            $chkEta = @$pdo->query("SHOW COLUMNS FROM containers LIKE 'eta_date'");
            if ($chkEta && $chkEta->rowCount() > 0 && array_key_exists('eta_date', $input)) {
                $v = $input['eta_date'];
                $sets[] = 'eta_date = ?';
                $params[] = ($v && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null;
            }
            $chkShip = @$pdo->query("SHOW COLUMNS FROM containers LIKE 'expected_ship_date'");
            if ($chkShip && $chkShip->rowCount() > 0 && array_key_exists('expected_ship_date', $input)) {
                $v = $input['expected_ship_date'];
                $sets[] = 'expected_ship_date = ?';
                $params[] = ($v && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null;
            }
            $chkDep = @$pdo->query("SHOW COLUMNS FROM containers LIKE 'actual_departure_date'");
            if ($chkDep && $chkDep->rowCount() > 0 && array_key_exists('actual_departure_date', $input)) {
                $v = $input['actual_departure_date'];
                $sets[] = 'actual_departure_date = ?';
                $params[] = ($v && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null;
            }
            $chkArr = @$pdo->query("SHOW COLUMNS FROM containers LIKE 'actual_arrival_date'");
            if ($chkArr && $chkArr->rowCount() > 0 && array_key_exists('actual_arrival_date', $input)) {
                $v = $input['actual_arrival_date'];
                $sets[] = 'actual_arrival_date = ?';
                $params[] = ($v && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null;
            }
            $chkVessel = @$pdo->query("SHOW COLUMNS FROM containers LIKE 'vessel_name'");
            if ($chkVessel && $chkVessel->rowCount() > 0 && array_key_exists('vessel_name', $input)) {
                $sets[] = 'vessel_name = ?';
                $params[] = trim((string) ($input['vessel_name'] ?? '')) ?: null;
            }
            $chkDest = @$pdo->query("SHOW COLUMNS FROM containers LIKE 'destination_country'");
            if ($chkDest && $chkDest->rowCount() > 0 && array_key_exists('destination_country', $input)) {
                $sets[] = 'destination_country = ?';
                $params[] = trim($input['destination_country'] ?? '') ?: null;
            }
            $chkDest2 = @$pdo->query("SHOW COLUMNS FROM containers LIKE 'destination'");
            if ($chkDest2 && $chkDest2->rowCount() > 0 && array_key_exists('destination', $input)) {
                $sets[] = 'destination = ?';
                $params[] = trim($input['destination'] ?? '') ?: null;
            }
            if (empty($sets)) jsonError('Nothing to update', 400);
            $params[] = $id;
            $pdo->prepare("UPDATE containers SET " . implode(', ', $sets) . " WHERE id = ?")
                ->execute($params);
            $stmt = $pdo->prepare("SELECT * FROM containers WHERE id = ?");
            $stmt->execute([$id]);
            jsonResponse(['data' => $stmt->fetch(PDO::FETCH_ASSOC)]);
            break;

        // -------------------------------------------------------------------------
        case 'POST':
            // Shortcut: assign orders directly to a container, handling draft creation automatically
            if ($id && $action === 'assign-orders') {
                requireRole(['ChinaAdmin', 'LebanonAdmin', 'ContainersStaff', 'SuperAdmin']);
                $orderIds = array_map('intval', $input['order_ids'] ?? []);
                $force    = !empty($input['force']); // allow even if over capacity
                if (empty($orderIds)) jsonError('order_ids required', 400);

                $stmt = $pdo->prepare("SELECT * FROM containers WHERE id = ?");
                $stmt->execute([$id]);
                $container = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$container) jsonError('Container not found', 404);

                // Validate order eligibility
                $eligible = ['ReadyForConsolidation', 'Confirmed'];
                foreach ($orderIds as $oid) {
                    $st = $pdo->prepare("SELECT id, status, destination_country_id, confirmation_token FROM orders WHERE id = ?");
                    $st->execute([$oid]);
                    $order = $st->fetch(PDO::FETCH_ASSOC);
                    $s = $order['status'] ?? null;
                    if (!in_array($s, $eligible, true)) {
                        jsonError("Order #$oid is not eligible (status: $s). Must be ReadyForConsolidation or Confirmed.", 400);
                    }
                    if (trim((string) ($order['confirmation_token'] ?? '')) !== '') {
                        jsonError("Order #$oid is still waiting for customer feedback and cannot be assigned to a container yet.", 400);
                    }
                    if (!OrderCountryService::orderMatchesContainer($pdo, $order, $container)) {
                        jsonError("Order #$oid destination country does not match container destination.", 400);
                    }
                }

                // Find or create a non-finalized draft for this container
                $draftRow = $pdo->prepare("SELECT id FROM shipment_drafts WHERE container_id = ? AND status != 'finalized' ORDER BY id DESC LIMIT 1");
                $draftRow->execute([$id]);
                $draftId = $draftRow->fetchColumn();
                if (!$draftId) {
                    $pdo->prepare("INSERT INTO shipment_drafts (status, container_id) VALUES ('draft', ?)")->execute([$id]);
                    $draftId = (int) $pdo->lastInsertId();
                }

                // Compute CURRENT usage of this container
                $usage = fetchContainerUsage($pdo, (int) $id);
                $currentCbm    = (float) ($usage['used_cbm'] ?? 0);
                $currentWeight = (float) ($usage['used_weight'] ?? 0);

                // Compute what the NEW orders would add
                $ph = implode(',', array_fill(0, count($orderIds), '?'));
                $newTot = $pdo->prepare("SELECT COALESCE(SUM(declared_cbm),0), COALESCE(SUM(declared_weight),0) FROM order_items WHERE order_id IN ($ph)");
                $newTot->execute($orderIds);
                [$addCbm, $addWeight] = $newTot->fetch(PDO::FETCH_NUM);
                $addCbm    = (float) $addCbm;
                $addWeight = (float) $addWeight;

                $totalCbm    = $currentCbm    + $addCbm;
                $totalWeight = $currentWeight + $addWeight;
                $maxCbm    = (float) $container['max_cbm'];
                $maxWeight = (float) $container['max_weight'];

                $overCbm    = $totalCbm    > $maxCbm;
                $overWeight = $totalWeight > $maxWeight;

                if (($overCbm || $overWeight) && !$force) {
                    $msgs = [];
                    if ($overCbm)    $msgs[] = "CBM: {$totalCbm} / {$maxCbm}";
                    if ($overWeight) $msgs[] = "Weight: {$totalWeight} / {$maxWeight} kg";
                    jsonResponse([
                        'over_capacity' => true,
                        'message' => 'Adding these orders would exceed container capacity (' . implode(', ', $msgs) . '). Send with force=true to proceed anyway.',
                        'details' => compact('totalCbm', 'totalWeight', 'maxCbm', 'maxWeight'),
                    ], 409);
                }

                // Insert into draft
                $ins = $pdo->prepare("INSERT IGNORE INTO shipment_draft_orders (shipment_draft_id, order_id) VALUES (?,?)");
                foreach ($orderIds as $oid) {
                    $ins->execute([$draftId, $oid]);
                }
                if (!empty($orderIds)) {
                    $pdo->prepare("UPDATE orders SET status='ConsolidatedIntoShipmentDraft' WHERE id IN ($ph)")->execute($orderIds);
                }
                // Ensure draft is linked to this container
                $pdo->prepare("UPDATE shipment_drafts SET container_id = ? WHERE id = ?")->execute([$id, $draftId]);
                // Update order status to AssignedToContainer
                if (!empty($orderIds)) {
                    $pdo->prepare("UPDATE orders SET status='AssignedToContainer' WHERE id IN ($ph)")->execute($orderIds);
                }

                $newUsage = fetchContainerUsage($pdo, (int) $id);
                jsonResponse([
                    'data' => [
                        'draft_id'      => $draftId,
                        'orders_added'  => count($orderIds),
                        'over_capacity' => $overCbm || $overWeight,
                        'used_cbm'      => (float) ($newUsage['used_cbm'] ?? 0),
                        'used_weight'   => (float) ($newUsage['used_weight'] ?? 0),
                        'max_cbm'       => $maxCbm,
                        'max_weight'    => $maxWeight,
                    ],
                ]);
            }

            $code = trim($input['code'] ?? '');
            $maxCbm = (float) ($input['max_cbm'] ?? 0);
            $maxWeight = (float) ($input['max_weight'] ?? 0);
            if (!$code || $maxCbm <= 0 || $maxWeight <= 0) {
                jsonError('code, max_cbm, max_weight required and positive', 400);
            }
            $status = in_array($input['status'] ?? '', ['planning', 'to_go', 'on_route', 'arrived', 'available'])
                ? $input['status'] : 'planning';
            $expectedShip = isset($input['expected_ship_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['expected_ship_date']) ? $input['expected_ship_date'] : null;
            $chkShip = @$pdo->query("SHOW COLUMNS FROM containers LIKE 'expected_ship_date'");
            $cols = ['code', 'max_cbm', 'max_weight', 'status'];
            $vals = [$code, $maxCbm, $maxWeight, $status];
            if ($chkShip && $chkShip->rowCount() > 0) {
                $cols[] = 'expected_ship_date';
                $vals[] = $expectedShip;
            }
            $ph = implode(',', array_fill(0, count($vals), '?'));
            $pdo->prepare("INSERT INTO containers (" . implode(',', $cols) . ") VALUES ($ph)")
                ->execute($vals);
            $newId = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare("SELECT * FROM containers WHERE id = ?");
            $stmt->execute([$newId]);
            jsonResponse(['data' => $stmt->fetch(PDO::FETCH_ASSOC)], 201);
            break;
    }

    jsonError('Method not allowed', 405);
};
