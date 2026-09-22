<?php

/**
 * Containers API - CRUD, status, export
 * Statuses: planning | to_go | on_route | arrived | available
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 2) . '/services/OrderCountryService.php';
require_once dirname(__DIR__, 2) . '/services/OrderExcelService.php';
require_once dirname(__DIR__, 2) . '/services/CargoMetricsService.php';
require_once dirname(__DIR__, 2) . '/services/ContainerCapacityService.php';
require_once dirname(__DIR__, 2) . '/services/ShipmentAssignmentService.php';
require_once dirname(__DIR__, 2) . '/services/CargoStateService.php';
require_once dirname(__DIR__, 2) . '/services/ContainerWriteService.php';
require_once dirname(__DIR__, 2) . '/services/OrderWriteService.php';
require_once dirname(__DIR__, 2) . '/services/ShipmentWriteService.php';

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

/** Lists and workflow pickers share searchable fields and legacy charset handling. */
function containerSearchPredicate(PDO $pdo, string $search, array &$params): string
{
    $like = clmsSearchLike($search);
    $outer = [clmsUtf8SearchExpr('c.code') . ' LIKE ?'];
    $params[] = $like;
    if (containerTableHasColumn($pdo, 'containers', 'notes')) {
        $outer[] = clmsUtf8SearchExpr('c.notes') . ' LIKE ?';
        $params[] = $like;
    }
    if (ctype_digit($search)) { $outer[] = 'c.id = ?'; $params[] = (int) $search; }
    $inner = [];
    foreach (['cu2.name', 'cu2.code', 'oi2.item_number', 'oi2.shipping_code', 'oi2.item_no', 'oi2.description_cn', 'oi2.description_en'] as $column) {
        $inner[] = clmsUtf8SearchExpr($column) . ' LIKE ?'; $params[] = $like;
    }
    if (containerTableHasColumn($pdo, 'customers', 'phone')) {
        $inner[] = clmsUtf8SearchExpr('cu2.phone') . ' LIKE ?'; $params[] = $like;
    }
    if (containerTableHasColumn($pdo, 'order_items', 'shared_carton_contents')) {
        foreach (['item_no', 'item_number'] as $identifier) {
            $inner[] = clmsSharedCartonIdentifierSearch('oi2.shared_carton_contents', $identifier, $pdo, $like, $params);
        }
    }
    if (ctype_digit($search)) { $inner[] = 'o2.id = ?'; $params[] = (int) $search; }
    $outer[] = 'EXISTS (SELECT 1 FROM shipment_draft_orders sdo2
        JOIN shipment_drafts sd2 ON sdo2.shipment_draft_id = sd2.id
        JOIN orders o2 ON o2.id = sdo2.order_id
        JOIN customers cu2 ON cu2.id = o2.customer_id
        LEFT JOIN order_items oi2 ON oi2.order_id = o2.id
        WHERE sd2.container_id = c.id AND (' . implode(' OR ', $inner) . '))';
    return '(' . implode(' OR ', $outer) . ')';
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
        'order_id',
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
    $rows = CargoMetricsService::shippingItems($pdo, $rows);

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

    $cargo = CargoMetricsService::totals($pdo, [$orderId])[$orderId] ?? [];
    $totals['total_cbm'] = $cargo['cbm'] ?? $totals['total_cbm'];
    $totals['total_weight'] = $cargo['weight'] ?? $totals['total_weight'];
    $totals['total_ctns'] = $cargo['cartons'] ?? $totals['total_ctns'];
    $totals['total_qty'] = array_key_exists('quantity',$cargo) ? $cargo['quantity'] : $totals['total_qty'];
    return [
        'items' => (int) $totals['items'],
        'total_ctns' => round($totals['total_ctns'], 4),
        'total_qty' => $totals['total_qty']===null ? null : round($totals['total_qty'], 4),
        'total_cbm' => round($totals['total_cbm'], 6),
        'total_weight' => round($totals['total_weight'], 4),
        'total_amount' => $totals['total_qty']===null ? null : round($totals['total_amount'], 2),
    ];
}

function fetchContainerUsage(PDO $pdo, int $containerId): array
{
    $cargoSql = CargoMetricsService::orderTotalsSql($pdo);
    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(cargo.cbm), 0) AS used_cbm,
            COALESCE(SUM(cargo.weight), 0) AS used_weight,
            COALESCE(SUM(cargo.measured_cargo_complete=0),0)=0 AS capacity_known,
            COUNT(DISTINCT ord.order_id) AS order_count
         FROM (
            SELECT DISTINCT sdo.order_id
            FROM shipment_draft_orders sdo
            JOIN shipment_drafts sd ON sdo.shipment_draft_id = sd.id
            WHERE sd.container_id = ?
         ) ord
         LEFT JOIN ($cargoSql) cargo ON cargo.order_id = ord.order_id"
    );
    $stmt->execute([$containerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'used_cbm' => !empty($row['capacity_known']) ? round((float) ($row['used_cbm'] ?? 0), 6) : null,
        'used_weight' => !empty($row['capacity_known']) ? round((float) ($row['used_weight'] ?? 0), 4) : null,
        'capacity_known' => !empty($row['capacity_known']),
        'order_count' => (int) ($row['order_count'] ?? 0),
    ];
}

function enrichContainerDestination(PDO $pdo, array $container): array
{
    static $countryCache = [];
    $container['revision']=ContainerWriteService::revision($container);
    $container['assignment_locked']=!ShipmentAssignmentService::containerIsOpen($pdo,$container);
    $container['allowed_status_transitions']=CargoStateService::nextContainerStates($pdo,$container);
    if(array_key_exists('used_cbm',$container)) {
        $known=($container['capacity_known']??true)!==false;
        $container['remaining_cbm']=$known ? round((float)$container['max_cbm']-(float)$container['used_cbm'],6) : null;
        $container['remaining_weight']=$known ? round((float)$container['max_weight']-(float)$container['used_weight'],4) : null;
    }

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

function containerCopyNormalGoodsDisplay($value): string
{
    $raw = trim((string) ($value ?? ''));
    return match (strtolower($raw)) {
        'copy' => clmsT('Copy Goods'),
        'normal' => clmsT('Normal Goods'),
        default => $raw,
    };
}

function outputContainerOrdersCsv(array $container, array $ordersWithItems): void
{
    $code = preg_replace('/[^a-zA-Z0-9_.-]/', '_', (string) ($container['code'] ?? 'container'));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="container_' . $code . '_orders.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $out = fopen('php://output', 'w');
    clmsWriteCsv($out, array_map('clmsSpreadsheetCell',[clmsT('Container'), (string) ($container['code'] ?? '')]));
    if (isset($container['cargo_totals'])) {
        clmsWriteCsv($out, [clmsT('Cargo CBM'), $container['cargo_totals']['cbm'], clmsT('Cargo Weight (kg)'), $container['cargo_totals']['weight']]);
    }
    clmsWriteCsv($out, ['']);
    clmsWriteCsv($out, array_map('clmsT', ['What Brand', 'Copy / Normal Goods', 'Code', 'Order ID', 'Customer', 'Supplier', 'I.I.N', 'Shipping Code', 'Description', 'Cartons', 'Qty/Carton', 'Total Qty', 'Unit Price', 'Total Amount', 'Cargo CBM', 'Cargo Weight', 'Express Number', 'Size', 'Photo Count', 'Item Number', 'Currency']));
    foreach ($ordersWithItems as $data) {
        $order = $data['order'] ?? [];
        foreach (($data['items'] ?? []) as $item) {
            $imagePaths = $item['image_paths'] ?? [];
            if (is_string($imagePaths)) {
                $imagePaths = json_decode($imagePaths, true) ?: [];
            }
            $cartons = (float) ($item['cartons'] ?? 0);
            $qtyPerCarton = (float) ($item['qty_per_carton'] ?? 0);
            $totalQty = (float) ($item['quantity'] ?? 0);
            $unitPrice = isset($item['sell_price']) && $item['sell_price'] !== null && $item['sell_price'] !== ''
                ? (float) $item['sell_price']
                : (float) ($item['unit_price'] ?? 0);
            clmsWriteCsv($out, array_map('clmsSpreadsheetCell',[
                (string) ($item['what_brand'] ?? ''),
                containerCopyNormalGoodsDisplay($item['copy_normal_goods'] ?? ''),
                (string) ($item['code'] ?? ''),
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
                isset($item['declared_cbm'])?round((float)$item['declared_cbm'],6):null,
                isset($item['declared_weight'])?round((float)$item['declared_weight'],4):null,
                (string) ($item['express_number'] ?? ''),
                (string) ($item['size'] ?? ''),
                count($imagePaths),
                (string) ($item['item_number'] ?? ''),
                (string) ($order['currency'] ?? 'USD'),
            ]));
        }
    }
    foreach (OrderExcelService::sharedCartonIdentifierRows($ordersWithItems) as $reference) {
        $row = array_fill(0, 21, '');
        $row[3] = $reference['order_id'];
        $row[6] = $reference['item_no'];
        $row[8] = clmsT('Contained item') . ': ' . $reference['description'];
        $row[19] = $reference['item_number'];
        clmsWriteCsv($out, array_map('clmsSpreadsheetCell',$row)); // Identifier-only rows leave cargo/financial totals unchanged.
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
    require_once __DIR__ . '/../authorization.php';
    clmsAuthorizeApiRequest('containers', $method, $id, $action);
    $pdo = getDb();
    if ($method === 'GET') { require_once dirname(__DIR__, 2) . '/services/QueryFilterService.php'; QueryFilterService::validate($_GET, 'containers'); }
    if($method==='GET'&&($action==='export'||$id==='export'))clmsBeginExportSnapshot($pdo);
    requirePermission('containers.'.($method==='GET'?'read':($action==='assign-orders'?'assign':'write')));

    try { switch ($method) {

        // -------------------------------------------------------------------------
        case 'GET':
            foreach(['q','fill','status_mode','format'] as $field)if(isset($_GET[$field])&&!is_string($_GET[$field]))jsonError("Invalid $field filter",422);
            foreach(['limit'=>1,'offset'=>0] as $field=>$minimum)if(isset($_GET[$field])&&(filter_var($_GET[$field],FILTER_VALIDATE_INT)===false||(int)$_GET[$field]<$minimum))jsonError("Invalid $field",422);
            if(isset($_GET['status']))foreach((array)$_GET['status'] as $value)if(!is_string($value))jsonError('Invalid status filter',422);
            if(isset($_GET['status_mode'])&&!in_array($_GET['status_mode'],['include','exclude'],true))jsonError('Invalid status filter mode',422);
            if(isset($_GET['format'])&&!in_array($_GET['format'],['csv','xlsx'],true))jsonError('Unsupported export format',422);
            if ($id === 'search') {
                $q = trim($_GET['q'] ?? '');
                if (strlen($q) < 1) {
                    jsonResponse(['data' => []]);
                }
                $execParams = [];
                $sql = 'SELECT c.* FROM containers c WHERE ' . containerSearchPredicate($pdo, $q, $execParams) . ' ORDER BY c.id DESC LIMIT 20';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($execParams);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as &$searchRow) $searchRow = enrichContainerDestination($pdo,$searchRow+fetchContainerUsage($pdo,(int)$searchRow['id']));
                unset($searchRow);
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
                require_once __DIR__ . '/orders.php';
                $containerItems = fetchOrderItemsForOrders($pdo, array_column($orders, 'id'));
                $totals = [
                    'order_count' => count($orders),
                    'item_count' => 0,
                    'cartons' => 0.0,
                    'quantity' => 0.0,
                    'cbm' => 0.0,
                    'weight' => 0.0,
                    'amount' => 0.0,
                ];
                $amountsByCurrency=[];
                foreach ($orders as &$ord) {
                    $ord['item_identifiers'] = [];
                    foreach ($containerItems[(int) $ord['id']] ?? [] as $item) {
                        $identifiedItems = !empty($item['shared_carton_enabled'])
                            ? orderDecodeSharedCartonContents($pdo, $item)
                            : [$item];
                        foreach ($identifiedItems as $identified) {
                            $ord['item_identifiers'][] = [
                                'item_no' => $identified['item_no'] ?? null,
                                'item_number' => $identified['item_number'] ?? null,
                                'description_en' => $identified['description_en'] ?? null,
                                'description_cn' => $identified['description_cn'] ?? null,
                            ];
                        }
                    }
                    $t = loadContainerOrderTotals($pdo, (int) $ord['id']);
                    $ord += $t;
                    $currency=$ord['currency']?:'USD';
                    if(!array_key_exists($currency,$amountsByCurrency))$amountsByCurrency[$currency]=0.0;
                    $amountsByCurrency[$currency]=$amountsByCurrency[$currency]===null||$t['total_amount']===null?null:$amountsByCurrency[$currency]+$t['total_amount'];
                    $totals['item_count'] += (int) $t['items'];
                    $totals['cartons'] += (float) $t['total_ctns'];
                    $totals['quantity'] = $totals['quantity']===null || $t['total_qty']===null ? null : $totals['quantity']+(float)$t['total_qty'];
                    $totals['cbm'] += (float) $t['total_cbm'];
                    $totals['weight'] += (float) $t['total_weight'];
                    $totals['amount'] = $totals['amount']===null || $t['total_amount']===null ? null : $totals['amount']+(float)$t['total_amount'];
                }
                unset($ord);
                $usage = fetchContainerUsage($pdo, (int) $id);
                $container['used_cbm']    = $usage['used_cbm'];
                $container['used_weight'] = $usage['used_weight'];
                $container['capacity_known'] = $usage['capacity_known'];
                $container['fill_pct_cbm'] = !$usage['capacity_known']?null:($container['max_cbm'] > 0
                    ? round($container['used_cbm'] / $container['max_cbm'] * 100, 1) : 0);
                $container = enrichContainerDestination($pdo, $container);
                $draftsStmt = $pdo->prepare(
                    "SELECT sd.id, sd.status, sd.container_id, sd.container_number, sd.booking_number, sd.tracking_url,
                            (SELECT COUNT(*) FROM shipment_draft_orders WHERE shipment_draft_id = sd.id) as order_count
                     FROM shipment_drafts sd WHERE sd.container_id = ? ORDER BY sd.id"
                );
                $draftsStmt->execute([$id]);
                $drafts = $draftsStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach($drafts as &$draft)$draft['revision']=ShipmentWriteService::revision($draft);unset($draft);
                jsonResponse(['data' => ['container' => $container, 'orders' => $orders, 'drafts' => $drafts, 'totals' => [
                    'order_count' => (int) $totals['order_count'],
                    'item_count' => (int) $totals['item_count'],
                    'cartons' => round($totals['cartons'], 4),
                    'quantity' => $totals['quantity']===null?null:round($totals['quantity'], 4),
                    'cbm' => $usage['used_cbm'],
                    'weight' => $usage['used_weight'],
                    'amount' => count($amountsByCurrency)===1?reset($amountsByCurrency):null,
                    'amounts_by_currency' => $amountsByCurrency,
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
                    $items = CargoMetricsService::shippingItems($pdo, normalizeOrderItems($pdo, fetchOrderItems($pdo, (int) $oid)));
                    $ordersWithItems[] = ['order' => $order, 'items' => $items];
                }
                $format = clmsExportFormat('xlsx');
                $cargo = CargoMetricsService::totals($pdo, $orderIds);
                foreach ($cargo as $summary) if (!$summary['quantity_complete']) jsonError('Historical item quantities require reconciliation before a packing-list export',409);
                $container['cargo_totals'] = ['cbm' => array_sum(array_column($cargo, 'cbm')), 'weight' => array_sum(array_column($cargo, 'weight'))];
                $code = preg_replace('/[^a-zA-Z0-9_-]/', '_', $container['code'] ?? 'container');
                if ($format === 'csv') {
                    outputContainerOrdersCsv($container, $ordersWithItems);
                }
                (new OrderExcelService($pdo))->exportOrders(
                    $ordersWithItems,
                    'container_' . $code . '_orders_' . date('Ymd_His') . '.xlsx',
                    [
                        'container' => $container,
                        'cargo_totals' => $container['cargo_totals'],
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
                if(array_diff($statusFilter,['planning','to_go','on_route','arrived','available']))jsonError('Invalid container status filter',422);
                $cargoSql = CargoMetricsService::orderTotalsSql($pdo);
                $sql = "SELECT c.*,
                    CASE WHEN cu.capacity_known=0 THEN NULL ELSE COALESCE(cu.used_cbm, 0) END AS used_cbm,
                    CASE WHEN cu.capacity_known=0 THEN NULL ELSE COALESCE(cu.used_weight, 0) END AS used_weight,
                    COALESCE(cu.capacity_known,1) AS capacity_known,
                    COALESCE(cu.order_count, 0) AS order_count
                FROM containers c
                LEFT JOIN (
                    SELECT ord.container_id,
                           COALESCE(SUM(cargo.cbm), 0) AS used_cbm,
                           COALESCE(SUM(cargo.weight), 0) AS used_weight,
                           COALESCE(SUM(cargo.measured_cargo_complete=0),0)=0 AS capacity_known,
                           COUNT(DISTINCT ord.order_id) AS order_count
                    FROM (
                        SELECT DISTINCT sd.container_id, sdo.order_id
                        FROM shipment_draft_orders sdo
                        JOIN shipment_drafts sd ON sdo.shipment_draft_id = sd.id
                        JOIN orders ovis ON sdo.order_id = ovis.id
                        WHERE sd.container_id IS NOT NULL
                    ) ord
                    LEFT JOIN ($cargoSql) cargo ON cargo.order_id = ord.order_id
                    GROUP BY ord.container_id
                ) cu ON cu.container_id = c.id
                WHERE 1=1";
                $params = [];
                if ($search !== '') {
                    $sql .= ' AND ' . containerSearchPredicate($pdo, $search, $params);
                }
                if (!empty($statusFilter)) {
                    $placeholders = implode(',', array_fill(0, count($statusFilter), '?'));
                    $sql .= $statusMode === 'exclude'
                        ? " AND c.status NOT IN ($placeholders)"
                        : " AND c.status IN ($placeholders)";
                    $params = array_merge($params, $statusFilter);
                }
                $fillFilter=strtolower(trim((string)($_GET['fill']??'')));
                if(!in_array($fillFilter,['','empty','partial','almost','full','launching_soon'],true))jsonError('Invalid capacity filter',422);
                if(in_array($fillFilter,['empty','partial','almost','full'],true))$sql.=' AND COALESCE(cu.capacity_known,1)=1';
                $fillRatio='GREATEST(COALESCE(cu.used_cbm,0)/NULLIF(c.max_cbm,0),COALESCE(cu.used_weight,0)/NULLIF(c.max_weight,0))';
                if($fillFilter==='empty')$sql.=' AND COALESCE(cu.order_count,0)=0';
                elseif($fillFilter==='partial')$sql.=" AND COALESCE(cu.order_count,0)>0 AND $fillRatio<0.85";
                elseif($fillFilter==='almost')$sql.=" AND $fillRatio>=0.85 AND $fillRatio<1";
                elseif($fillFilter==='full')$sql.=" AND $fillRatio>=1";
                elseif($fillFilter==='launching_soon')$sql.=' AND c.expected_ship_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)';
                $limit=clmsQueryLimit($_GET['limit']??null,50,200);$offset=clmsQueryOffset($_GET['offset']??null);
                $countStmt=$params?$pdo->prepare("SELECT COUNT(*) FROM ($sql) containers_filtered"):$pdo->query("SELECT COUNT(*) FROM ($sql) containers_filtered");if($params)$countStmt->execute($params);$total=(int)$countStmt->fetchColumn();
                $sql .= " ORDER BY c.id DESC LIMIT ".($limit+1)." OFFSET ".$offset;
                $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
                if ($params) $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $hasMore=count($rows)>$limit;if($hasMore)$rows=array_slice($rows,0,$limit);
                foreach ($rows as &$r) {
                    $r['capacity_known'] = (bool)$r['capacity_known'];
                    $r['used_cbm']    = $r['capacity_known'] ? (float) $r['used_cbm'] : null;
                    $r['used_weight'] = $r['capacity_known'] ? (float) $r['used_weight'] : null;
                    $r['order_count'] = (int)   $r['order_count'];
                    $r['fill_pct_cbm'] = !$r['capacity_known']?null:($r['max_cbm'] > 0
                        ? round($r['used_cbm'] / $r['max_cbm'] * 100, 1) : 0);
                    $r = enrichContainerDestination($pdo, $r);
                }
                unset($r);
                jsonResponse(['data' => $rows,'meta'=>['limit'=>$limit,'offset'=>$offset,'has_more'=>$hasMore,'total'=>$total]]);
            }

            $stmt = $pdo->prepare("SELECT * FROM containers WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) jsonError('Container not found', 404);
            $row += fetchContainerUsage($pdo,(int)$id);
            jsonResponse(['data' => enrichContainerDestination($pdo, $row)]);
            break;

        // -------------------------------------------------------------------------
        case 'PUT':
            if (!$id) jsonError('Container ID required', 400);
            $startedTransaction = !$pdo->inTransaction();
            if ($startedTransaction) {
                $pdo->beginTransaction();
                register_shutdown_function(static function () use ($pdo) { if ($pdo->inTransaction()) $pdo->rollBack(); });
            }
            $stmt = $pdo->prepare("SELECT * FROM containers WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) jsonError('Container not found', 404);
            if(!is_string($input['revision']??null)||!hash_equals(ContainerWriteService::revision($existing),$input['revision']))jsonError('Container changed or revision is missing; reopen before saving',409);
            $input=ContainerWriteService::normalize($pdo,$input,$existing);
            CargoStateService::assertContainerUpdate($pdo, $existing, $input);
            ContainerWriteService::assertDestination($pdo,$existing,$input);
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
                $maxCbm = ContainerCapacityService::limit($input['max_cbm'],'Max CBM');
                $sets[] = 'max_cbm = ?';
                $params[] = $maxCbm;
            }
            if (array_key_exists('max_weight', $input)) {
                $maxWeight = ContainerCapacityService::limit($input['max_weight'],'Max weight');
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
            if (isset($maxCbm) || isset($maxWeight)) ContainerCapacityService::check($pdo,array_replace($existing,['max_cbm'=>$maxCbm??$existing['max_cbm'],'max_weight'=>$maxWeight??$existing['max_weight']]));
            $params[] = $id;
            $pdo->prepare("UPDATE containers SET " . implode(', ', $sets) . " WHERE id = ?")
                ->execute($params);
            $stmt = $pdo->prepare("SELECT * FROM containers WHERE id = ?");
            $stmt->execute([$id]);
            $updated = $stmt->fetch(PDO::FETCH_ASSOC);
            $pdo->prepare("INSERT INTO audit_log(entity_type,entity_id,action,old_value,new_value,user_id) VALUES ('container',?,'update',?,?,?)")
                ->execute([$id,json_encode($existing),json_encode($updated),getAuthUserId()]);
            if ($startedTransaction) $pdo->commit();
            jsonResponse(['data' => enrichContainerDestination($pdo,$updated)]);
            break;

        // -------------------------------------------------------------------------
        case 'POST':
            // Shortcut: assign orders directly to a container, handling draft creation automatically
            if ($id && $action === 'assign-orders') {
                requirePermission('containers.assign');
                $orderIds = ShipmentAssignmentService::ids($input['order_ids'] ?? null);
                if (empty($orderIds)) jsonError('order_ids required', 400);
                $startedTransaction = !$pdo->inTransaction();
                if ($startedTransaction) {
                    $pdo->beginTransaction();
                    register_shutdown_function(static function () use ($pdo) { if ($pdo->inTransaction()) $pdo->rollBack(); });
                }

                $stmt = $pdo->prepare("SELECT * FROM containers WHERE id = ? FOR UPDATE");
                $stmt->execute([$id]);
                $container = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$container) jsonError('Container not found', 404);
                ShipmentAssignmentService::assertContainerOpen($pdo,$container);

                // Validate order eligibility
                $eligible = ['ReadyForConsolidation', 'Confirmed'];
                $newOrderIds=[];$existingDraftIds=[];
                foreach ($orderIds as $oid) {
                    $st = $pdo->prepare("SELECT id, status, destination_country_id, confirmation_token FROM orders WHERE id = ? FOR UPDATE");
                    $st->execute([$oid]);
                    $order = $st->fetch(PDO::FETCH_ASSOC);
                    if(!$order)jsonError("Order #$oid not found",404);
                    $memberships=ShipmentAssignmentService::memberships($pdo,$oid);
                    if($memberships){
                        $membership=$memberships[0];
                        if((int)$membership['container_id']===(int)$id && $membership['status']!=='finalized' && $order['status']==='AssignedToContainer'){$existingDraftIds[]=(int)$membership['shipment_draft_id'];continue;}
                        throw new ShipmentAssignmentException("Order #$oid is already reserved; remove or move its existing reservation first");
                    }
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
                    ShipmentAssignmentService::assertReceived($pdo,$oid);
                    $newOrderIds[]=$oid;
                }

                // Find or create a non-finalized draft for this container
                $draftRow = $pdo->prepare("SELECT id FROM shipment_drafts WHERE container_id = ? AND status != 'finalized' ORDER BY id DESC LIMIT 1");
                $draftRow->execute([$id]);
                $draftId = $draftRow->fetchColumn();
                if(!$newOrderIds){
                    $usage=fetchContainerUsage($pdo,(int)$id);
                    if($startedTransaction)$pdo->commit();
                    jsonResponse(['data'=>['draft_id'=>$existingDraftIds[0]??$draftId,'orders_added'=>0,'already_applied'=>true,'over_capacity'=>false]+$usage]);
                }
                $orderIds=$newOrderIds;

                $ph = implode(',', array_fill(0, count($orderIds), '?'));
                $newUsage = ContainerCapacityService::check($pdo,$container,$orderIds);
                $maxCbm    = (float) $container['max_cbm'];
                $maxWeight = (float) $container['max_weight'];

                // Insert into draft
                if (!$draftId) {
                    $pdo->prepare("INSERT INTO shipment_drafts (status, container_id) VALUES ('draft', ?)")->execute([$id]);
                    $draftId = (int) $pdo->lastInsertId();
                }
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
                ShipmentAssignmentService::audit($pdo,'assign_orders',(int)$draftId,[],['container_id'=>(int)$id,'order_ids'=>$orderIds],(int)getAuthUserId());

                if ($startedTransaction) $pdo->commit();
                jsonResponse([
                    'data' => [
                        'draft_id'      => $draftId,
                        'orders_added'  => count($orderIds),
                        'over_capacity' => false,
                        'used_cbm'      => (float) ($newUsage['used_cbm'] ?? 0),
                        'used_weight'   => (float) ($newUsage['used_weight'] ?? 0),
                        'max_cbm'       => $maxCbm,
                        'max_weight'    => $maxWeight,
                    ],
                ]);
            }

            if($id!==null||$action!==null)jsonError('Unsupported container action',400);
            $input=ContainerWriteService::normalize($pdo,$input);
            $code = $input['code'] ?? '';
            $maxCbm = ContainerCapacityService::limit($input['max_cbm']??null,'Max CBM');
            $maxWeight = ContainerCapacityService::limit($input['max_weight']??null,'Max weight');
            if (!$code || $maxCbm <= 0 || $maxWeight <= 0) {
                jsonError('code, max_cbm, max_weight required and positive', 400);
            }
            $status = $input['status'] ?? 'planning';
            if (!in_array($status, ['planning', 'to_go'], true)) jsonError('New containers must start in planning or to_go', 400);
            $key=OrderWriteService::requestKey($input['idempotency_key']??null);
            $cols = ['code', 'max_cbm', 'max_weight', 'status'];
            $vals = [$code, $maxCbm, $maxWeight, $status];
            foreach(['expected_ship_date','eta_date','actual_departure_date','actual_arrival_date','vessel_name','destination_country','destination','notes'] as $field){
                if(containerTableHasColumn($pdo,'containers',$field)){$cols[]=$field;$vals[]=$input[$field]??null;}
            }
            $hash=hash('sha256',json_encode($vals,JSON_THROW_ON_ERROR));
            $lock='container-create:'.substr(hash('sha256',$pdo->query('SELECT DATABASE()')->fetchColumn().':'.$key),0,42);
            $s=$pdo->prepare('SELECT GET_LOCK(?,5)');$s->execute([$lock]);if(!(int)$s->fetchColumn())jsonError('Container creation is busy; retry',409);
            register_shutdown_function(static function()use($pdo,$lock){if($pdo->inTransaction())$pdo->rollBack();$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);});
            $startedTransaction=!$pdo->inTransaction();if($startedTransaction)$pdo->beginTransaction();
            $prior=ContainerWriteService::findCreateRequest($pdo,$key);
            if($prior){
                $audit=json_decode($prior['new_value'],true);
                if((int)$prior['user_id']!==getAuthUserId()||!hash_equals($audit['request_hash']??'',$hash))jsonError('Container request key belongs to another payload or operator',409);
                $s=$pdo->prepare('SELECT * FROM containers WHERE id=? FOR UPDATE');$s->execute([$prior['entity_id']]);$saved=$s->fetch(PDO::FETCH_ASSOC);if(!$saved)jsonError('Original container is missing; do not replay this request',409);
                if($startedTransaction)$pdo->commit();jsonResponse(['data'=>enrichContainerDestination($pdo,$saved),'idempotent_replay'=>true]);
            }
            $s=$pdo->prepare('SELECT * FROM containers WHERE code=?');$s->execute([$code]);$saved=$s->fetch(PDO::FETCH_ASSOC);
            if($saved){
                jsonError('Container code already exists; open the existing container',409);
            }
            $ph = implode(',', array_fill(0, count($vals), '?'));
            $pdo->prepare("INSERT INTO containers (" . implode(',', $cols) . ") VALUES ($ph)")
                ->execute($vals);
            $newId = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO audit_log(entity_type,entity_id,action,new_value,user_id) VALUES ('container',?,'create',?,?)")->execute([$newId,json_encode(['idempotency_key'=>$key,'request_hash'=>$hash,'fields'=>array_combine($cols,$vals)]),getAuthUserId()]);
            $stmt = $pdo->prepare("SELECT * FROM containers WHERE id = ?");
            $stmt->execute([$newId]);
            $created=$stmt->fetch(PDO::FETCH_ASSOC);if($startedTransaction)$pdo->commit();jsonResponse(['data' => enrichContainerDestination($pdo,$created)], 201);
            break;
    } } catch (ContainerCapacityException $e) {
        jsonResponse(['error'=>true,'over_capacity'=>$e->overCapacity,'message'=>$e->getMessage(),'details'=>$e->details],$e->httpStatus);
    } catch (ShipmentAssignmentException $e) {
        jsonError($e->getMessage(),409);
    } catch (PDOException $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        if((int)($e->errorInfo[1]??0)===1062)jsonError('Container identity already exists; reload before retrying',409);
        throw $e;
    }
    jsonError('Method not allowed', 405);
};
