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
require_once dirname(__DIR__, 2) . '/services/OrderBulkExcelService.php';
require_once dirname(__DIR__, 2) . '/services/OrderReceivingService.php';
require_once dirname(__DIR__, 2) . '/services/DraftOrderCostService.php';
require_once dirname(__DIR__, 2) . '/services/ShipmentAccountingService.php';
require_once dirname(__DIR__, 2) . '/services/ItemNumberReservationService.php';

function orderSupportsSharedCartons(PDO $pdo): bool
{
    return orderTableHasColumn($pdo, 'order_items', 'shared_carton_enabled')
        && orderTableHasColumn($pdo, 'order_items', 'shared_carton_contents');
}

function orderBuildDescriptionEntries(?string $cn, ?string $en): array
{
    $entries = [];
    $cnParts = array_values(array_filter(array_map('trim', preg_split('/\s*\|\s*/', (string) ($cn ?? '')) ?: [])));
    $enParts = array_values(array_filter(array_map('trim', preg_split('/\s*\|\s*/', (string) ($en ?? '')) ?: [])));
    $entryCount = max(count($cnParts), count($enParts));
    for ($i = 0; $i < $entryCount; $i++) {
        $entries[] = [
            'description_text' => $cnParts[$i] ?? $enParts[$i] ?? '',
            'description_translated' => $enParts[$i] ?? $cnParts[$i] ?? '',
        ];
    }

    return $entries;
}

function orderLookupSupplierNames(PDO $pdo, array $supplierIds): array
{
    $supplierIds = array_values(array_unique(array_filter(array_map('intval', $supplierIds))));
    if (!$supplierIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($supplierIds), '?'));
    $stmt = $pdo->prepare("SELECT id, name FROM suppliers WHERE id IN ($placeholders)");
    $stmt->execute($supplierIds);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

function orderDecodeSharedCartonContents(PDO $pdo, array $row): array
{
    $raw = $row['shared_carton_contents'] ?? null;
    if (!$raw) {
        return [];
    }

    $decoded = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
    if (!$decoded) {
        return [];
    }

    $supplierNames = orderLookupSupplierNames($pdo, array_map(
        static fn(array $content): int => (int) ($content['supplier_id'] ?? 0),
        $decoded
    ));
    $cartons = (float) ($row['cartons'] ?? 0);

    foreach ($decoded as &$content) {
        $content['supplier_id'] = !empty($content['supplier_id']) ? (int) $content['supplier_id'] : null;
        $content['supplier_name'] = $content['supplier_id'] ? ($supplierNames[$content['supplier_id']] ?? null) : null;
        $content['quantity_per_carton'] = round((float) ($content['quantity_per_carton'] ?? $content['quantity'] ?? 0), 4);
        $content['quantity'] = round($content['quantity_per_carton'] * $cartons, 4);
        $content['unit_price'] = isset($content['unit_price']) && $content['unit_price'] !== '' ? (float) $content['unit_price'] : null;
        $content['sell_price'] = isset($content['sell_price']) && $content['sell_price'] !== '' ? (float) $content['sell_price'] : null;
        $content['description_entries'] = orderBuildDescriptionEntries(
            $content['description_cn'] ?? '',
            $content['description_en'] ?? ''
        );
        $linePrice = $content['sell_price'] ?? $content['unit_price'];
        if (!isset($content['total_amount']) || $content['total_amount'] === null || $content['total_amount'] === '') {
            $content['total_amount'] = $linePrice !== null ? round($content['quantity'] * $linePrice, 4) : null;
        } else {
            $content['total_amount'] = (float) $content['total_amount'];
        }
    }
    unset($content);

    return $decoded;
}

function normalizeOrderItems(PDO $pdo, array $items): array
{
    foreach ($items as &$it) {
        $it['image_paths'] = $it['image_paths'] ? json_decode($it['image_paths'], true) : [];
        $it['shared_carton_enabled'] = !empty($it['shared_carton_enabled']) ? 1 : 0;
        $it['shared_carton_contents'] = !empty($it['shared_carton_enabled'])
            ? orderDecodeSharedCartonContents($pdo, $it)
            : [];
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

function orderUtf8LikeExpr(string $expr): string
{
    return "CONVERT($expr USING utf8mb4) COLLATE utf8mb4_unicode_ci";
}

function orderTableExists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        $cache[$table] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$table] = false;
    }
    return $cache[$table];
}

function orderFetchReceiptFees(PDO $pdo, int $receiptId): array
{
    if ($receiptId <= 0 || !orderTableExists($pdo, 'warehouse_receipt_fees')) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT id, receipt_id, order_id, fee_label, amount, currency, notes, created_by, created_at
         FROM warehouse_receipt_fees
         WHERE receipt_id = ?
         ORDER BY id ASC"
    );
    $stmt->execute([$receiptId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function orderFetchLatestReceiptForOrder(PDO $pdo, int $orderId): ?array
{
    if ($orderId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM warehouse_receipts WHERE order_id = ? ORDER BY received_at DESC LIMIT 1");
    $stmt->execute([$orderId]);
    $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$receipt) {
        return null;
    }
    $receipt['fees'] = orderFetchReceiptFees($pdo, (int) $receipt['id']);
    return $receipt;
}

function orderNormalizeReceiptPackagingSplits(array $itemInput): array
{
    $rawSplits = $itemInput['packaging_splits'] ?? $itemInput['splits'] ?? [];
    if (!is_array($rawSplits)) {
        $rawSplits = [];
    }

    $splits = [];
    foreach ($rawSplits as $rawSplit) {
        if (!is_array($rawSplit)) {
            continue;
        }
        $cartons = isset($rawSplit['cartons']) && $rawSplit['cartons'] !== '' ? (float) $rawSplit['cartons'] : null;
        $pieces = isset($rawSplit['pieces_per_carton']) && $rawSplit['pieces_per_carton'] !== '' ? (float) $rawSplit['pieces_per_carton'] : null;
        $quantity = isset($rawSplit['quantity']) && $rawSplit['quantity'] !== '' ? (float) $rawSplit['quantity'] : null;
        $unitPrice = isset($rawSplit['unit_price']) && $rawSplit['unit_price'] !== '' ? (float) $rawSplit['unit_price'] : null;
        $totalAmount = isset($rawSplit['total_amount']) && $rawSplit['total_amount'] !== '' ? (float) $rawSplit['total_amount'] : null;

        if (($quantity === null || $quantity <= 0) && $cartons !== null && $pieces !== null && $cartons > 0 && $pieces > 0) {
            $quantity = round($cartons * $pieces, 4);
        }
        if (($totalAmount === null || $totalAmount <= 0) && $quantity !== null && $unitPrice !== null && $quantity > 0 && $unitPrice >= 0) {
            $totalAmount = round($quantity * $unitPrice, 4);
        }

        if ($cartons === null && $pieces === null && $quantity === null && $unitPrice === null && $totalAmount === null) {
            continue;
        }

        $splits[] = [
            'cartons' => $cartons,
            'pieces_per_carton' => $pieces,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_amount' => $totalAmount,
        ];
    }

    if (!$splits) {
        $cartons = isset($itemInput['actual_cartons']) && $itemInput['actual_cartons'] !== '' ? (float) $itemInput['actual_cartons'] : null;
        $pieces = isset($itemInput['actual_pieces_per_carton']) && $itemInput['actual_pieces_per_carton'] !== '' ? (float) $itemInput['actual_pieces_per_carton'] : null;
        $quantity = isset($itemInput['actual_quantity']) && $itemInput['actual_quantity'] !== '' ? (float) $itemInput['actual_quantity'] : null;
        $unitPrice = isset($itemInput['unit_price']) && $itemInput['unit_price'] !== '' ? (float) $itemInput['unit_price'] : null;
        $totalAmount = isset($itemInput['total_amount']) && $itemInput['total_amount'] !== '' ? (float) $itemInput['total_amount'] : null;
        if ($cartons !== null || $pieces !== null || $quantity !== null || $unitPrice !== null || $totalAmount !== null) {
            $splits[] = [
                'cartons' => $cartons,
                'pieces_per_carton' => $pieces,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_amount' => $totalAmount,
            ];
        }
    }

    foreach ($splits as &$split) {
        if (($split['quantity'] === null || $split['quantity'] <= 0)
            && $split['cartons'] !== null && $split['pieces_per_carton'] !== null
            && $split['cartons'] > 0 && $split['pieces_per_carton'] > 0) {
            $split['quantity'] = round($split['cartons'] * $split['pieces_per_carton'], 4);
        }
        if (($split['total_amount'] === null || $split['total_amount'] <= 0)
            && $split['quantity'] !== null && $split['unit_price'] !== null
            && $split['quantity'] > 0 && $split['unit_price'] >= 0) {
            $split['total_amount'] = round($split['quantity'] * $split['unit_price'], 4);
        }
    }
    unset($split);

    return $splits;
}

function orderAggregateReceiptPackagingSplits(array $splits): array
{
    $cartons = 0.0;
    $quantity = 0.0;
    $amount = 0.0;
    $unitPrices = [];
    $piecesValues = [];
    foreach ($splits as $split) {
        if ($split['cartons'] !== null) $cartons += (float) $split['cartons'];
        if ($split['quantity'] !== null) $quantity += (float) $split['quantity'];
        if ($split['total_amount'] !== null) $amount += (float) $split['total_amount'];
        if ($split['unit_price'] !== null) $unitPrices[] = (float) $split['unit_price'];
        if ($split['pieces_per_carton'] !== null) $piecesValues[] = (float) $split['pieces_per_carton'];
    }
    $sameUnitPrice = count(array_unique(array_map(static fn($v) => (string) round($v, 4), $unitPrices))) === 1;
    $samePieces = count(array_unique(array_map(static fn($v) => (string) round($v, 4), $piecesValues))) === 1;
    return [
        'actual_cartons' => $cartons > 0 ? round($cartons, 4) : null,
        'actual_pieces_per_carton' => $samePieces && $piecesValues ? $piecesValues[0] : null,
        'actual_quantity' => $quantity > 0 ? round($quantity, 4) : null,
        'unit_price' => $sameUnitPrice && $unitPrices ? $unitPrices[0] : null,
        'total_amount' => $amount > 0 ? round($amount, 4) : null,
    ];
}

function orderNormalizeItemText($value, int $maxLength = 150): ?string
{
    $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($value ?? '')) ?? '');
    if ($value === '') {
        return null;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }
    return substr($value, 0, $maxLength);
}

function orderNormalizeOptionalDecimal($value, string $label): ?float
{
    if ($value === null || trim((string) $value) === '') {
        return null;
    }

    $normalized = str_replace(',', '', trim((string) $value));
    if (!is_numeric($normalized)) {
        jsonError($label . ' must be a number.', 400, ['items.' . strtolower($label) => $label . ' must be numeric.']);
    }
    $number = round((float) $normalized, 4);
    if ($number < 0) {
        jsonError($label . ' cannot be negative.', 400, ['items.' . strtolower($label) => $label . ' cannot be negative.']);
    }

    return $number;
}

function orderNormalizeItemMetadataValue(string $column, array $item)
{
    if (in_array($column, ['length', 'width', 'height'], true)) {
        $legacy = 'item_' . $column;
        return orderNormalizeOptionalDecimal($item[$column] ?? $item[$legacy] ?? null, ucfirst($column));
    }

    if ($column === 'copy_normal_goods') {
        return orderNormalizeCopyNormalGoods($item[$column] ?? null);
    }

    if ($column === 'materials') {
        return orderNormalizeItemText($item[$column] ?? null, 1000);
    }

    if ($column === 'brand') {
        return orderNormalizeItemText($item['brand'] ?? $item['what_brand'] ?? null, 150);
    }

    if ($column === 'what_brand') {
        return orderNormalizeItemText($item['what_brand'] ?? $item['brand'] ?? null, 150);
    }

    $limit = $column === 'code' ? 100 : 150;
    return orderNormalizeItemText($item[$column] ?? null, $limit);
}

function orderCopyNormalGoodsDisplay($value): string
{
    $raw = trim((string) ($value ?? ''));
    return match (strtolower($raw)) {
        'copy' => clmsT('Copy Goods'),
        'dangerous' => clmsT('Dangerous Goods'),
        'normal' => clmsT('Normal Goods'),
        default => $raw,
    };
}

function orderNormalizeCopyNormalGoods($value): ?string
{
    $raw = trim((string) ($value ?? ''));
    $normalized = strtolower(preg_replace('/[\s_\-\/]+/', '', $raw) ?? '');
    if (in_array($normalized, ['copy', 'copygoods', 'replica', '仿牌', '仿货'], true)) {
        return 'Copy';
    }
    if (in_array($normalized, ['dangerous', 'dangerousgoods', 'hazmat', 'hazardous', 'hazardousgoods', 'dg', '危险品', '危险货'], true)) {
        return 'Dangerous';
    }
    if (in_array($normalized, ['normal', 'normalgoods', 'regular', '普通货', '常规货'], true)) {
        return 'Normal';
    }
    return orderNormalizeItemText($raw, 60);
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

function supplierExists(PDO $pdo, int $supplierId): bool
{
    static $cache = [];

    if ($supplierId <= 0) {
        return false;
    }

    if (array_key_exists($supplierId, $cache)) {
        return $cache[$supplierId];
    }

    try {
        $stmt = $pdo->prepare("SELECT 1 FROM suppliers WHERE id = ? LIMIT 1");
        $stmt->execute([$supplierId]);
        $cache[$supplierId] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$supplierId] = false;
    }

    return $cache[$supplierId];
}

function normalizeExistingSupplierId(PDO $pdo, $supplierId, string $field = 'supplier_id'): ?int
{
    $normalized = (int) ($supplierId ?? 0);
    if ($normalized <= 0) {
        return null;
    }

    if (!supplierExists($pdo, $normalized)) {
        jsonError(
            'Selected supplier no longer exists. Please reselect the supplier and try again.',
            400,
            [$field => 'Selected supplier was not found.']
        );
    }

    return $normalized;
}

function validateOrderItemSupplierIds(PDO $pdo, array $items): void
{
    foreach ($items as $idx => $item) {
        if (empty($item['supplier_id'])) {
            continue;
        }
        $supplierId = normalizeExistingSupplierId($pdo, $item['supplier_id'], "items.$idx.supplier_id");
        $items[$idx]['supplier_id'] = $supplierId;
    }
}

function normalizeOrderItemsForPersistence(PDO $pdo, int $customerId, ?int $destinationCountryId, ?int $defaultSupplierId, array $items, ?string $currentStatus = 'Draft', ?int $excludeOrderId = null): array
{
    if (!hasPermission('item_numbers.override', ['SuperAdmin'])) {
        $preserved=[];
        if($excludeOrderId){$stmt=$pdo->prepare('SELECT item_no FROM order_items WHERE order_id=?');$stmt->execute([$excludeOrderId]);foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $value){$key=ItemNumberReservationService::normalize((string)$value);if($key!=='')$preserved[$key]=true;}}
        foreach($items as &$item){$key=ItemNumberReservationService::normalize((string)($item['item_no']??''));if($key!==''&&isset($preserved[$key])){$item['item_no_manual']=1;}else{$item['item_no']=null;$item['item_no_manual']=0;}}unset($item);
    }
    $shippingCode = OrderCountryService::resolveShippingCode($pdo, $customerId, $destinationCountryId);
    $history = OrderItemNumberingService::fetchNumberingHistory($pdo, $customerId, $excludeOrderId);
    return OrderItemNumberingService::prepareItemsForPersistence($items, $currentStatus, $shippingCode, $defaultSupplierId, $history);
}

function orderCreationIdempotencyKey($value): ?string
{
    $key = trim((string) $value);
    if ($key === '') return null;
    if (!preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $key)) {
        jsonError('Invalid order idempotency key', 400);
    }
    return $key;
}

function orderHandleLifecycleTransition(string $action, string $currentStatus, string $targetStatus): void
{
    if ($currentStatus === $targetStatus) {
        jsonResponse([
            'data' => [
                'status' => $targetStatus,
                'already_applied' => true,
            ],
            'message' => $action === 'submit'
                ? 'Order already submitted'
                : 'Order already approved',
        ]);
    }

    try {
        OrderStateService::validateTransition($currentStatus, $targetStatus);
    } catch (RuntimeException $e) {
        jsonError(
            $action === 'submit'
                ? 'Only draft orders can be submitted.'
                : 'Only submitted orders can be approved.',
            400,
            ['status' => "Current order status is $currentStatus."]
        );
    }
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
    $hasItemCode = orderTableHasColumn($pdo, 'order_items', 'code');
    $hasExpressNumber = orderTableHasColumn($pdo, 'order_items', 'express_number');
    $hasWhatBrand = orderTableHasColumn($pdo, 'order_items', 'what_brand');
    $hasBrand = orderTableHasColumn($pdo, 'order_items', 'brand');
    $hasMaterials = orderTableHasColumn($pdo, 'order_items', 'materials');
    $hasCopyNormalGoods = orderTableHasColumn($pdo, 'order_items', 'copy_normal_goods');
    $hasItemSize = orderTableHasColumn($pdo, 'order_items', 'size');

    $clauses = [];
    foreach ($terms as $term) {
        $like = '%' . $term . '%';
        $termClauses = [
            orderUtf8LikeExpr("CAST($orderAlias.id AS CHAR)") . " LIKE ?",
            orderUtf8LikeExpr("$customerAlias.name") . " LIKE ?",
            orderUtf8LikeExpr("COALESCE($supplierAlias.name, '')") . " LIKE ?",
        ];
        array_push($params, $like, $like, $like);

        if ($hasCustomerCode) {
            $termClauses[] = orderUtf8LikeExpr("COALESCE($customerAlias.code, '')") . " LIKE ?";
            $params[] = $like;
        }
        if ($hasCustomerPhone) {
            $termClauses[] = orderUtf8LikeExpr("COALESCE($customerAlias.phone, '')") . " LIKE ?";
            $params[] = $like;
        }
        if ($hasSupplierCode) {
            $termClauses[] = orderUtf8LikeExpr("COALESCE($supplierAlias.code, '')") . " LIKE ?";
            $params[] = $like;
        }
        if ($hasSupplierStoreId) {
            $termClauses[] = orderUtf8LikeExpr("COALESCE($supplierAlias.store_id, '')") . " LIKE ?";
            $params[] = $like;
        }
        if ($hasSupplierPhone) {
            $termClauses[] = orderUtf8LikeExpr("COALESCE($supplierAlias.phone, '')") . " LIKE ?";
            $params[] = $like;
        }

        $itemClauses = [];
        if ($hasShippingCode) {
            $itemClauses[] = orderUtf8LikeExpr("COALESCE(oi.shipping_code, '')") . " LIKE ?";
            $params[] = $like;
        }
        if ($hasItemNo) {
            $itemClauses[] = orderUtf8LikeExpr("COALESCE(oi.item_no, '')") . " LIKE ?";
            $params[] = $like;
        }
        if ($hasItemCode) {
            $itemClauses[] = orderUtf8LikeExpr("COALESCE(oi.code, '')") . " LIKE ?";
            $params[] = $like;
        }
        if ($hasExpressNumber) {
            $itemClauses[] = orderUtf8LikeExpr("COALESCE(oi.express_number, '')") . " LIKE ?";
            $params[] = $like;
        }
        if ($hasWhatBrand) {
            $itemClauses[] = orderUtf8LikeExpr("COALESCE(oi.what_brand, '')") . " LIKE ?";
            $params[] = $like;
        }
        if ($hasBrand) {
            $itemClauses[] = orderUtf8LikeExpr("COALESCE(oi.brand, '')") . " LIKE ?";
            $params[] = $like;
        }
        if ($hasMaterials) {
            $itemClauses[] = orderUtf8LikeExpr("COALESCE(oi.materials, '')") . " LIKE ?";
            $params[] = $like;
        }
        if ($hasCopyNormalGoods) {
            $itemClauses[] = orderUtf8LikeExpr("COALESCE(oi.copy_normal_goods, '')") . " LIKE ?";
            $params[] = $like;
        }
        if ($hasItemSize) {
            $itemClauses[] = orderUtf8LikeExpr("COALESCE(oi.size, '')") . " LIKE ?";
            $params[] = $like;
        }
        if ($hasDescriptionCn) {
            $itemClauses[] = orderUtf8LikeExpr("COALESCE(oi.description_cn, '')") . " LIKE ?";
            $params[] = $like;
        }
        if ($hasDescriptionEn) {
            $itemClauses[] = orderUtf8LikeExpr("COALESCE(oi.description_en, '')") . " LIKE ?";
            $params[] = $like;
        }
        if ($hasItemHsCode) {
            $itemClauses[] = orderUtf8LikeExpr("COALESCE(oi.hs_code, op.hs_code, '')") . " LIKE ?";
            $params[] = $like;
        }
        if ($hasOrderItemSupplier) {
            $itemClauses[] = orderUtf8LikeExpr("COALESCE(sis.name, '')") . " LIKE ?";
            $params[] = $like;
            if ($hasSupplierCode) {
                $itemClauses[] = orderUtf8LikeExpr("COALESCE(sis.code, '')") . " LIKE ?";
                $params[] = $like;
            }
            if ($hasSupplierStoreId) {
                $itemClauses[] = orderUtf8LikeExpr("COALESCE(sis.store_id, '')") . " LIKE ?";
                $params[] = $like;
            }
            if ($hasSupplierPhone) {
                $itemClauses[] = orderUtf8LikeExpr("COALESCE(sis.phone, '')") . " LIKE ?";
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

function fetchOrderItemsForOrders(PDO $pdo, array $orderIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds), static fn(int $id): bool => $id > 0)));
    if (empty($ids)) {
        return [];
    }

    $hasSupplier = orderTableHasColumn($pdo, 'order_items', 'supplier_id');
    $hasProductSupplier = orderTableHasColumn($pdo, 'products', 'supplier_id');
    $productAlertCol = orderTableHasColumn($pdo, 'products', 'high_alert_note')
        ? ", p.high_alert_note as product_high_alert_note"
        : '';
    if (orderTableHasColumn($pdo, 'products', 'required_design')) {
        $productAlertCol .= ", p.required_design as product_required_design";
    }
    if (orderTableHasColumn($pdo, 'products', 'dimensions_scope')) {
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
    if (orderTableHasColumn($pdo, 'order_items', 'shared_carton_enabled')) {
        $productAlertCol .= ", oi.shared_carton_enabled";
    }
    if (orderTableHasColumn($pdo, 'order_items', 'shared_carton_code')) {
        $productAlertCol .= ", oi.shared_carton_code";
    }
    if (orderTableHasColumn($pdo, 'order_items', 'shared_carton_contents')) {
        $productAlertCol .= ", oi.shared_carton_contents";
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
    $classificationCols = '';
    $classificationJoin = '';
    if (orderTableExists($pdo, 'item_classifications')) {
        $classificationCols = ', ic.item_type_code, ic.confidence AS item_type_confidence, ic.is_confirmed AS item_type_confirmed';
        $classificationJoin = " LEFT JOIN item_classifications ic ON ic.entity_type='order_item' AND ic.entity_id=oi.id";
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = $hasSupplier
        ? "SELECT oi.*$supplierCols$productAlertCol$classificationCols FROM order_items oi LEFT JOIN products p ON oi.product_id = p.id LEFT JOIN suppliers s ON $supplierJoinTarget = s.id$classificationJoin WHERE oi.order_id IN ($placeholders) ORDER BY oi.order_id ASC, oi.id ASC"
        : "SELECT oi.*$productAlertCol$classificationCols FROM order_items oi LEFT JOIN products p ON oi.product_id = p.id$classificationJoin WHERE oi.order_id IN ($placeholders) ORDER BY oi.order_id ASC, oi.id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $grouped = [];
    foreach ($rows as $row) {
        $grouped[(int) ($row['order_id'] ?? 0)][] = $row;
    }

    return $grouped;
}

function fetchOrderItems(PDO $pdo, int $orderId): array
{
    $grouped = fetchOrderItemsForOrders($pdo, [$orderId]);
    return $grouped[$orderId] ?? [];
}

function orderCollectSupplierNamesFromItem(array $item, string $fallback = ''): array
{
    $names = [];
    $primary = trim((string) ($item['supplier_name'] ?? $fallback));
    if ($primary !== '') {
        $names[$primary] = true;
    }
    if (!empty($item['shared_carton_enabled']) && !empty($item['shared_carton_contents'])) {
        foreach ((array) $item['shared_carton_contents'] as $content) {
            $name = trim((string) ($content['supplier_name'] ?? ''));
            if ($name !== '') {
                $names[$name] = true;
            }
        }
    }

    return array_keys($names);
}

function orderCollectSupplierNamesFromItems(array $items, string $fallback = ''): array
{
    $names = [];
    foreach ($items as $item) {
        foreach (orderCollectSupplierNamesFromItem($item, $fallback) as $name) {
            $names[$name] = true;
        }
    }

    return array_keys($names);
}

function orderBuildSupplierDisplayFromItems(array $items, string $fallback = ''): string
{
    $names = orderCollectSupplierNamesFromItems($items, $fallback);
    if (!$names) {
        return $fallback;
    }
    if (count($names) === 1) {
        return $names[0];
    }
    return 'Multiple (' . implode(', ', $names) . ')';
}

function orderRowMatchesSupplierFilter(array $row, int $supplierId): bool
{
    if ($supplierId <= 0) {
        return true;
    }
    if ((int) ($row['supplier_id'] ?? 0) === $supplierId) {
        return true;
    }
    foreach (($row['items'] ?? []) as $item) {
        if ((int) ($item['supplier_id'] ?? 0) === $supplierId) {
            return true;
        }
        if (!empty($item['shared_carton_enabled']) && !empty($item['shared_carton_contents'])) {
            foreach ((array) $item['shared_carton_contents'] as $content) {
                if ((int) ($content['supplier_id'] ?? 0) === $supplierId) {
                    return true;
                }
            }
        }
    }

    return false;
}

function fetchOrdersListRowsForRequest(PDO $pdo, bool $paginate = false, ?array &$meta = null): array
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
    $itemType = clmsNormalizeItemTypeFilter($_GET['item_type'] ?? null);
    $custCols = 'c.name as customer_name';
    $chkPrio = @$pdo->query("SHOW COLUMNS FROM customers LIKE 'priority_level'");
    if ($chkPrio && $chkPrio->rowCount() > 0) {
        $custCols .= ', c.priority_level as customer_priority_level, c.priority_note as customer_priority_note';
    }
    $destCols = orderTableHasColumn($pdo, 'orders', 'destination_country_id')
        ? ', co.name as destination_country_name, co.code as destination_country_code'
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
    if ($itemType !== null && orderTableExists($pdo, 'item_classifications')) {
        $sql .= " AND EXISTS (SELECT 1 FROM order_items oit JOIN item_classifications ict ON ict.entity_type='order_item' AND ict.entity_id=oit.id WHERE oit.order_id=o.id AND ict.item_type_code=?)";
        $params[] = $itemType;
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
        $sql .= " AND EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND " . orderUtf8LikeExpr('oi.shipping_code') . " LIKE ?)";
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
    $limit = clmsQueryLimit($_GET['limit'] ?? null, 50, 100);
    $offset = clmsQueryOffset($_GET['offset'] ?? null);
    $requiresPostFilterPagination = $paginate && $supplierId && orderSupportsSharedCartons($pdo);
    $total = 0;
    if ($paginate && !$requiresPostFilterPagination) {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ($sql) orders_filtered");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
    }
    $sql .= " ORDER BY o.expected_ready_date IS NULL ASC, o.expected_ready_date ASC, o.created_at DESC, o.id DESC";
    if ($paginate && !$requiresPostFilterPagination) {
        $sql .= ' LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset;
    }
    $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
    if ($params) {
        $stmt->execute($params);
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $itemsByOrderId = fetchOrderItemsForOrders($pdo, array_column($rows, 'id'));
    foreach ($rows as &$row) {
        $row['items'] = normalizeOrderItems($pdo, $itemsByOrderId[(int) ($row['id'] ?? 0)] ?? []);
        $supplierNames = orderCollectSupplierNamesFromItems($row['items'], (string) ($row['supplier_name'] ?? ''));
        if ($supplierNames) {
            $row['item_supplier_names'] = implode(', ', $supplierNames);
            $row['supplier_name_display'] = count($supplierNames) === 1
                ? $supplierNames[0]
                : 'Multiple (' . implode(', ', $supplierNames) . ')';
        } else {
            $row['supplier_name_display'] = (string) ($row['supplier_name'] ?? '');
        }
    }
    unset($row);

    if ($supplierId && orderSupportsSharedCartons($pdo)) {
        $filterSupplierId = (int) $supplierId;
        $rows = array_values(array_filter($rows, static fn(array $row): bool => orderRowMatchesSupplierFilter($row, $filterSupplierId)));
        if ($paginate) $total = count($rows);
    }

    if ($paginate) {
        $hasMore = count($rows) > ($requiresPostFilterPagination ? $offset + $limit : $limit);
        $rows = $requiresPostFilterPagination ? array_slice($rows, $offset, $limit) : array_slice($rows, 0, $limit);
        $meta = ['limit' => $limit, 'offset' => $offset, 'has_more' => $hasMore, 'total' => $total];
    }

    orderAttachDepositSummaries($pdo, $rows);
    orderAttachOperationalCostSummaries($pdo, $rows);

    return $rows;
}

function orderBuildExcelEntry(PDO $pdo, int $orderId): ?array
{
    if ($orderId <= 0) return null;

    $supplierColumns = 's.name as supplier_name, s.phone as supplier_phone, s.factory_location as supplier_factory';
    if (orderTableHasColumn($pdo, 'suppliers', 'address')) $supplierColumns .= ', s.address as supplier_address';
    if (orderTableHasColumn($pdo, 'suppliers', 'fax')) $supplierColumns .= ', s.fax as supplier_fax';
    if (orderTableHasColumn($pdo, 'suppliers', 'store_id')) $supplierColumns .= ', s.store_id as supplier_store_id';
    $destinationColumns = orderTableHasColumn($pdo, 'orders', 'destination_country_id')
        ? ', co.name as destination_country_name, co.code as destination_country_code'
        : '';
    $destinationJoin = orderTableHasColumn($pdo, 'orders', 'destination_country_id')
        ? ' LEFT JOIN countries co ON co.id = o.destination_country_id'
        : '';

    $stmt = $pdo->prepare(
        "SELECT o.*, c.name as customer_name, c.default_shipping_code, $supplierColumns$destinationColumns
         FROM orders o
         JOIN customers c ON c.id = o.customer_id
         LEFT JOIN suppliers s ON s.id = o.supplier_id$destinationJoin
         WHERE o.id = ?"
    );
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) return null;

    $items = normalizeOrderItems($pdo, fetchOrderItems($pdo, $orderId));
    $latestReceipt = orderFetchLatestReceiptForOrder($pdo, $orderId);
    if ($latestReceipt) {
        $order['receipt'] = $latestReceipt;
        $order['receipt_fees'] = $latestReceipt['fees'] ?? [];
    }
    if (orderTableExists($pdo, 'draft_order_costs')) {
        $order['operational_costs'] = (new DraftOrderCostService($pdo))->summarize($orderId);
    }

    $safeCustomer = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string) ($order['customer_name'] ?? 'customer')) ?: 'customer';
    return [
        'order' => $order,
        'items' => $items,
        'filename' => 'order_' . $orderId . '_' . trim($safeCustomer, '_') . '_' . date('Ymd_His') . '.xlsx',
    ];
}

function orderAttachOperationalCostSummaries(PDO $pdo, array &$rows): void
{
    if (!$rows || !orderTableExists($pdo, 'draft_order_costs')) {
        return;
    }

    $orderIds = array_values(array_unique(array_filter(array_map(
        static fn(array $row): int => (int) ($row['id'] ?? 0),
        $rows
    ))));
    if (!$orderIds) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT order_id, base_currency, SUM(base_amount) AS base_total, COUNT(*) AS line_count
         FROM draft_order_costs
         WHERE is_deleted=0 AND order_id IN ($placeholders)
         GROUP BY order_id, base_currency
         ORDER BY order_id, base_currency"
    );
    $stmt->execute($orderIds);
    $summaries = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $costRow) {
        $orderId = (int) $costRow['order_id'];
        $summaries[$orderId]['line_count'] = ($summaries[$orderId]['line_count'] ?? 0) + (int) $costRow['line_count'];
        $summaries[$orderId]['totals'][] = [
            'amount' => (string) $costRow['base_total'],
            'currency' => (string) $costRow['base_currency'],
        ];
    }

    foreach ($rows as &$row) {
        $row['operational_cost_summary'] = $summaries[(int) ($row['id'] ?? 0)] ?? [
            'line_count' => 0,
            'totals' => [],
        ];
    }
    unset($row);
}

function orderFormatOperationalCostSummary(array $row): string
{
    $parts = [];
    foreach (($row['operational_cost_summary']['totals'] ?? []) as $total) {
        $parts[] = format_display_amount($total['amount'] ?? '0', 4) . ' ' . (string) ($total['currency'] ?? '');
    }
    return implode(' + ', $parts);
}

function orderPaymentStatusFor(float $paid, float $due): string
{
    if ($paid <= 0.004) {
        return 'No Deposit';
    }
    if ($due > 0 && $paid > $due + 0.004) {
        return 'Overpaid';
    }
    if ($due > 0 && abs($paid - $due) <= 0.004) {
        return 'Paid';
    }
    if ($due > 0 && $paid < $due) {
        return 'Partial Deposit';
    }
    return 'Partial Deposit';
}

function orderAttachDepositSummaries(PDO $pdo, array &$rows): void
{
    if (!$rows) {
        return;
    }

    $orderIds = array_values(array_unique(array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $rows)));
    $orderIds = array_values(array_filter($orderIds, static fn(int $id): bool => $id > 0));
    if (!$orderIds) {
        return;
    }

    $paidByOrder = array_fill_keys($orderIds, '0.0000');
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

    if (orderTableExists($pdo, 'balance_transactions') && orderTableHasColumn($pdo, 'balance_transactions', 'order_id')) {
        $sql = "SELECT order_id, SUM(amount) as paid_amount
            FROM balance_transactions
            WHERE order_id IN ($placeholders)
              AND party_type = 'customer'
              AND direction = 'reduce_balance'
              AND transaction_type IN ('deposit', 'payment_received')
            GROUP BY order_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($orderIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $orderId = (int) $row['order_id'];
            $paidByOrder[$orderId] = DecimalMath::add($paidByOrder[$orderId], $row['paid_amount'] ?? '0');
        }
    }

    if (orderTableExists($pdo, 'customer_deposits') && orderTableHasColumn($pdo, 'customer_deposits', 'order_id')) {
        $notLinked = orderTableExists($pdo, 'balance_transactions')
            ? "AND NOT EXISTS (SELECT 1 FROM balance_transactions bt WHERE bt.source_table = 'customer_deposits' AND bt.source_id = cd.id)"
            : '';
        $sql = "SELECT order_id, SUM(amount) as paid_amount
            FROM customer_deposits cd
            WHERE order_id IN ($placeholders)
              $notLinked
            GROUP BY order_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($orderIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $orderId = (int) $row['order_id'];
            $paidByOrder[$orderId] = DecimalMath::add($paidByOrder[$orderId], $row['paid_amount'] ?? '0');
        }
    }

    foreach ($rows as &$row) {
        $due = '0.0000';
        foreach (($row['items'] ?? []) as $item) {
            if (isset($item['total_amount']) && $item['total_amount'] !== null && $item['total_amount'] !== '') {
                $due = DecimalMath::add($due, $item['total_amount']);
                continue;
            }
            $quantity = DecimalMath::normalize($item['quantity'] ?? '0');
            if (DecimalMath::compare($quantity, '0') <= 0) {
                $cartons = DecimalMath::normalize($item['cartons'] ?? '0');
                $qtyPerCarton = DecimalMath::normalize($item['qty_per_carton'] ?? '0');
                $quantity = DecimalMath::compare($cartons, '0') > 0 && DecimalMath::compare($qtyPerCarton, '0') > 0
                    ? DecimalMath::multiply($cartons, $qtyPerCarton)
                    : '0.0000';
            }
            $unitPrice = isset($item['sell_price']) && $item['sell_price'] !== null && $item['sell_price'] !== ''
                ? DecimalMath::normalize($item['sell_price'])
                : DecimalMath::normalize($item['unit_price'] ?? '0');
            $due = DecimalMath::add($due, DecimalMath::multiply($quantity, $unitPrice));
        }
        $paid = $paidByOrder[(int) ($row['id'] ?? 0)] ?? '0.0000';
        $row['order_total_amount'] = $due;
        $row['deposit_paid_amount'] = $paid;
        $row['remaining_balance'] = DecimalMath::subtract($due, $paid);
        $row['deposit_status'] = orderPaymentStatusForExact($paid, $due);
    }
    unset($row);
}

function orderPaymentStatusForExact(string $paid, string $due): string
{
    if (DecimalMath::compare($paid, '0.004') <= 0) return 'No Deposit';
    if (DecimalMath::compare($due, '0') > 0 && DecimalMath::compare($paid, DecimalMath::add($due, '0.004')) > 0) return 'Overpaid';
    if (DecimalMath::compare($due, '0') > 0 && DecimalMath::compare($paid, DecimalMath::subtract($due, '0.004')) >= 0 && DecimalMath::compare($paid, DecimalMath::add($due, '0.004')) <= 0) return 'Paid';
    return 'Partial Deposit';
}

function outputOrdersListCsv(array $rows, ?string $filename = null): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . ($filename ?: ('orders_' . date('Y-m-d') . '.csv')) . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $out = fopen('php://output', 'w');
    fputcsv($out, array_map('clmsT', ['ID', 'Order Type', 'Customer', 'Supplier', 'Expected Ready', 'Status', 'Deposit Status', 'Paid Amount', 'Remaining Balance', 'Shipment Charges', 'Total CBM', 'Total Weight']));
    foreach ($rows as $row) {
        $cbm = 0.0;
        $weight = 0.0;
        $supplierNames = [];
        foreach (($row['items'] ?? []) as $item) {
            $cbm += (float) ($item['declared_cbm'] ?? 0);
            $weight += (float) ($item['declared_weight'] ?? 0);
            foreach (orderCollectSupplierNamesFromItem($item, (string) ($row['supplier_name'] ?? '')) as $supplierName) {
                if ($supplierName !== '') {
                    $supplierNames[$supplierName] = true;
                }
            }
        }
        $supplierDisplay = trim((string) ($row['supplier_name_display'] ?? ($row['supplier_name'] ?? '')));
        if ($supplierNames && $supplierDisplay === '') {
            $names = array_keys($supplierNames);
            $supplierDisplay = count($names) === 1 ? $names[0] : clmsT('Multiple ({names})', ['names' => implode(', ', $names)]);
        }
        fputcsv($out, [
            (int) ($row['id'] ?? 0),
            (string) ($row['order_type'] ?? 'standard'),
            OrderExcelService::formatCustomerDisplay($row, $row['items'] ?? []),
            $supplierDisplay,
            (string) ($row['expected_ready_date'] ?? ''),
            clmsStatusLabel((string) ($row['status'] ?? '')),
            clmsT((string) ($row['deposit_status'] ?? 'No Deposit')),
            format_display_amount($row['deposit_paid_amount'] ?? 0, 2),
            format_display_amount($row['remaining_balance'] ?? 0, 2),
            orderFormatOperationalCostSummary($row),
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
    fputcsv($out, [clmsT('Order'), '#' . (int) ($order['id'] ?? 0)]);
    fputcsv($out, [clmsT('Customer'), OrderExcelService::formatCustomerDisplay($order, $items)]);
    fputcsv($out, [clmsT('Supplier'), (string) ($order['supplier_name'] ?? '')]);
    fputcsv($out, [clmsT('Expected Ready'), (string) ($order['expected_ready_date'] ?? '')]);
    fputcsv($out, [clmsT('Status'), clmsStatusLabel((string) ($order['status'] ?? ''))]);
    fputcsv($out, [clmsT('Currency'), (string) ($order['currency'] ?? '')]);
    fputcsv($out, ['']);
    fputcsv($out, array_map('clmsT', ['What Brand', 'Good Type', 'Code', 'Photo Count', 'Item No', 'Supplier', 'Description', 'Total CTNS', 'QTY/CTN', 'TOTAL QTY', 'UNIT PRICE', 'TOTAL AMOUNT', 'CBM', 'TOTAL CBM', 'GWKG', 'TOTAL GW', 'Express Number', 'Size']));

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
            (string) ($item['what_brand'] ?? ''),
            orderCopyNormalGoodsDisplay($item['copy_normal_goods'] ?? ''),
            (string) ($item['code'] ?? ''),
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
            (string) ($item['express_number'] ?? ''),
            (string) ($item['size'] ?? ''),
        ]);
    }

    $fees = $order['receipt_fees'] ?? ($order['receipt']['fees'] ?? []);
    if (is_array($fees) && $fees) {
        fputcsv($out, ['']);
        fputcsv($out, [clmsT('Customer-facing receiving fees')]);
        fputcsv($out, array_map('clmsT', ['Fee', 'Amount', 'Currency', 'Notes']));
        foreach ($fees as $fee) {
            if (!is_array($fee)) {
                continue;
            }
            fputcsv($out, [
                (string) ($fee['fee_label'] ?? $fee['label'] ?? clmsT('Warehouse fee')),
                isset($fee['amount']) ? format_display_amount($fee['amount'], 4) : '',
                (string) ($fee['currency'] ?? ($order['currency'] ?? '')),
                (string) ($fee['notes'] ?? ''),
            ]);
        }
    }
    $costs = $order['operational_costs'] ?? [];
    if (!empty($costs['lines']) && is_array($costs['lines'])) {
        fputcsv($out, ['']);
        fputcsv($out, [clmsT('Shipment Charges')]);
        fputcsv($out, array_map('clmsT', ['Type', 'Description', 'Amount', 'Currency', 'Exchange Rate', 'Base Amount', 'Base Currency', 'Supplier / Provider', 'Responsible Payer', 'Allocation', 'Notes', 'Accounting Treatment']));
        foreach ($costs['lines'] as $cost) {
            fputcsv($out, [
                (string) ($cost['cost_type_label_en'] ?? $cost['cost_type_code'] ?? ''),
                (string) ($cost['description_en'] ?? $cost['description_zh'] ?? ''),
                (string) ($cost['amount'] ?? ''),
                (string) ($cost['currency'] ?? ''),
                (string) ($cost['exchange_rate'] ?? ''),
                (string) ($cost['base_amount'] ?? ''),
                (string) ($cost['base_currency'] ?? ''),
                (string) ($cost['supplier_name'] ?? $cost['service_provider'] ?? ''),
                (string) ($cost['responsible_payer'] ?? ''),
                (string) ($cost['allocation_method'] ?? ''),
                (string) ($cost['notes'] ?? ''),
                clmsT(ucfirst((string)($cost['posting_status'] ?? 'pending'))),
            ]);
        }
        fputcsv($out, [clmsT('Shipment Charges Total'), '', '', '', '', (string) ($costs['base_total'] ?? '0.0000'), (string) ($costs['base_currency'] ?? ''), '', '', '', '', clmsT('Shipment-level; no item allocation')]);
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
                $hasSharedCartons = orderSupportsSharedCartons($pdo);
                if ($supplierId) {
                    $chkItemSupp = @$pdo->query("SHOW COLUMNS FROM order_items LIKE 'supplier_id'");
                    if ($chkItemSupp && $chkItemSupp->rowCount() > 0) {
                        if (!$hasSharedCartons) {
                            $where .= ' AND (o.supplier_id = ? OR EXISTS (SELECT 1 FROM order_items oi LEFT JOIN products p ON oi.product_id = p.id WHERE oi.order_id = o.id AND COALESCE(oi.supplier_id, p.supplier_id) = ?))';
                            $params[] = $supplierId;
                            $params[] = $supplierId;
                        }
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
                if ($hasSharedCartons) {
                    foreach ($rows as &$row) {
                        $row['items'] = normalizeOrderItems($pdo, fetchOrderItems($pdo, (int) $row['id']));
                        $supplierNames = orderCollectSupplierNamesFromItems($row['items'], (string) ($row['supplier_name'] ?? ''));
                        if ($supplierNames) {
                            $row['item_supplier_names'] = implode(', ', $supplierNames);
                            $row['supplier_name_display'] = count($supplierNames) === 1
                                ? $supplierNames[0]
                                : 'Multiple (' . implode(', ', $supplierNames) . ')';
                        } else {
                            $row['supplier_name_display'] = (string) ($row['supplier_name'] ?? '');
                        }
                    }
                    unset($row);
                    if ($supplierId) {
                        $filterSupplierId = (int) $supplierId;
                        $rows = array_values(array_filter($rows, static fn(array $row): bool => orderRowMatchesSupplierFilter($row, $filterSupplierId)));
                    }
                }
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
                    'orders_' . date('Ymd_His') . '.xlsx'
                );
            }
            if ($id && $action === 'export') {
                $entry = orderBuildExcelEntry($pdo, (int) $id);
                if (!$entry) jsonError('Order not found', 404);
                $order = $entry['order'];
                $items = $entry['items'];
                $format = strtolower(trim((string) ($_GET['format'] ?? 'xlsx')));
                if ($format === 'csv') {
                    outputOrderCsv($order, $items, 'order_' . (int) $id . '.csv');
                }
                (new OrderExcelService())->exportOrder($order, $items, $entry['filename']);
            }
            if ($id === null) {
                $meta = [];
                jsonResponse(['data' => fetchOrdersListRowsForRequest($pdo, true, $meta), 'meta' => $meta]);
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
            $stmt->execute([(int) $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) jsonError('Order not found', 404);
            $row['items'] = normalizeOrderItems($pdo, fetchOrderItems($pdo, (int) $id));
            $row['supplier_name_display'] = orderBuildSupplierDisplayFromItems($row['items'], (string) ($row['supplier_name'] ?? ''));
            $row['item_supplier_names'] = implode(', ', orderCollectSupplierNamesFromItems($row['items'], (string) ($row['supplier_name'] ?? '')));
            $att = $pdo->prepare("SELECT * FROM order_attachments WHERE order_id = ?");
            $att->execute([$id]);
            $row['attachments'] = $att->fetchAll(PDO::FETCH_ASSOC);
            $receipt = orderFetchLatestReceiptForOrder($pdo, (int) $id);
            if ($receipt) {
                $row['receipt'] = $receipt;
                $rip = $pdo->prepare("SELECT * FROM warehouse_receipt_photos WHERE receipt_id = ?");
                $rip->execute([$receipt['id']]);
                $row['receipt']['photos'] = $rip->fetchAll(PDO::FETCH_ASSOC);
                $receiptItemCols = "oi.description_cn, oi.description_en, oi.item_no, oi.shipping_code, oi.cartons, oi.qty_per_carton, oi.quantity, oi.unit_price as declared_unit_price, oi.total_amount as declared_total_amount";
                foreach (['what_brand', 'brand', 'materials', 'copy_normal_goods', 'code', 'express_number', 'size', 'length', 'width', 'height'] as $column) {
                    if (orderTableHasColumn($pdo, 'order_items', $column)) {
                        $receiptItemCols .= ", oi.$column";
                    }
                }
                $rii = $pdo->prepare("SELECT wri.*, $receiptItemCols FROM warehouse_receipt_items wri JOIN order_items oi ON wri.order_item_id = oi.id WHERE wri.receipt_id = ?");
                $rii->execute([$receipt['id']]);
                $row['receipt']['items'] = $rii->fetchAll(PDO::FETCH_ASSOC);
                if (orderTableExists($pdo, 'warehouse_receipt_item_splits')) {
                    $splitStmt = $pdo->prepare(
                        "SELECT wris.* FROM warehouse_receipt_item_splits wris
                         JOIN warehouse_receipt_items wri ON wris.receipt_item_id = wri.id
                         WHERE wri.receipt_id = ?
                         ORDER BY wris.receipt_item_id, wris.line_no, wris.id"
                    );
                    $splitStmt->execute([$receipt['id']]);
                    $receiptSplits = $splitStmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($row['receipt']['items'] as &$receiptItem) {
                        $splits = array_values(array_filter($receiptSplits, static fn($split) => (int) $split['receipt_item_id'] === (int) $receiptItem['id']));
                        if (!$splits && ((float) ($receiptItem['actual_cartons'] ?? 0) > 0 || (float) ($receiptItem['actual_quantity'] ?? 0) > 0)) {
                            $splits = [[
                                'receipt_item_id' => (int) $receiptItem['id'],
                                'line_no' => 1,
                                'cartons' => $receiptItem['actual_cartons'] ?? null,
                                'pieces_per_carton' => $receiptItem['actual_pieces_per_carton'] ?? null,
                                'quantity' => $receiptItem['actual_quantity'] ?? null,
                                'unit_price' => $receiptItem['unit_price'] ?? null,
                                'total_amount' => $receiptItem['total_amount'] ?? null,
                            ]];
                        }
                        $receiptItem['packaging_splits'] = $splits;
                    }
                    unset($receiptItem);
                }
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
            $singleRow = [$row];
            orderAttachDepositSummaries($pdo, $singleRow);
            $row = $singleRow[0];
            jsonResponse(['data' => $row]);
            break;

        case 'PUT':
            if (!$id) jsonError('ID required', 400);
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
            $stmt->execute([$id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) jsonError('Order not found', 404);
            $customerId = (int) ($input['customer_id'] ?? $order['customer_id']);
            $supplierId = isset($input['supplier_id'])
                ? normalizeExistingSupplierId($pdo, $input['supplier_id'], 'supplier_id')
                : normalizeExistingSupplierId($pdo, $order['supplier_id'] ?? null, 'supplier_id');
            $expectedDate = resolveOrderExpectedReadyDate($input, $order);
            $highAlertNotes = isset($input['high_alert_notes']) ? (trim($input['high_alert_notes']) ?: null) : ($order['high_alert_notes'] ?? null);
            $requestedDestinationCountryId = array_key_exists('destination_country_id', $input) ? (!empty($input['destination_country_id']) ? (int) $input['destination_country_id'] : null) : ($order['destination_country_id'] ?? null);
            $destinationCountryId = orderTableHasColumn($pdo, 'orders', 'destination_country_id')
                ? normalizeOrderDestinationCountryId($pdo, $customerId, $requestedDestinationCountryId)
                : $requestedDestinationCountryId;
            $items = normalizeOrderItemsForPersistence($pdo, $customerId, $destinationCountryId, $supplierId ? (int) $supplierId : null, $input['items'] ?? [], (string) ($order['status'] ?? 'Draft'), (int) $id);
            validateOrderItemSupplierIds($pdo, $items);
            $dupWarn = enforceDuplicateShippingCodePolicy($pdo, $customerId, (int) $id, $items);
            $pdo->beginTransaction();
            try {
                $lockedOrderStmt = $pdo->prepare("SELECT lock_version FROM orders WHERE id=? FOR UPDATE");
                $lockedOrderStmt->execute([(int) $id]);
                $lockedVersion = $lockedOrderStmt->fetchColumn();
                if ($lockedVersion === false) throw new RuntimeException('Order not found');
                if (array_key_exists('lock_version', $input) && (int) $input['lock_version'] !== (int) $lockedVersion) {
                    throw new RuntimeException('This order was changed by another request. Reload it before saving.');
                }
                $updSets = "customer_id=?, supplier_id=?, expected_ready_date=?, high_alert_notes=?, lock_version=lock_version+1";
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
                $metadataColumns = [];
                foreach (['what_brand', 'brand', 'materials', 'copy_normal_goods', 'code', 'express_number', 'size', 'length', 'width', 'height'] as $column) {
                    if (orderTableHasColumn($pdo, 'order_items', $column)) {
                        $metadataColumns[] = $column;
                    }
                }
                $insCols = "order_id, product_id, item_no, shipping_code, cartons, qty_per_carton, quantity, unit, declared_cbm, declared_weight, item_length, item_width, item_height, unit_price, total_amount, notes, image_paths, description_cn, description_en";
                $insVals = "?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?";
                foreach ($metadataColumns as $column) {
                    $insCols .= ", $column";
                    $insVals .= ",?";
                }
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
                    $l = orderNormalizeOptionalDecimal($it['item_length'] ?? $it['length'] ?? null, 'Length');
                    $w = orderNormalizeOptionalDecimal($it['item_width'] ?? $it['width'] ?? null, 'Width');
                    $h = orderNormalizeOptionalDecimal($it['item_height'] ?? $it['height'] ?? null, 'Height');
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
                    foreach ($metadataColumns as $column) {
                        $params[] = orderNormalizeItemMetadataValue($column, $it);
                    }
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
                (new ItemNumberReservationService($pdo))->reservePersistedOrder((int)$id,(int)$userId);
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
                $row['items'] = normalizeOrderItems($pdo, fetchOrderItems($pdo, (int) $id));
                jsonResponse(array_filter(['data' => $row, 'warning' => $dupWarn]));
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        case 'POST':
            if ($id === 'bulk-export') {
                $rawIds = is_array($input['ids'] ?? null) ? $input['ids'] : [];
                $ids = array_values(array_unique(array_filter(array_map(
                    static fn($value): int => filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0,
                    $rawIds
                ))));
                if (!$ids) jsonError('Select at least one downloadable record.', 422);
                if (count($ids) > 100) jsonError('Select no more than 100 records per download.', 422);

                $entries = [];
                $missing = [];
                foreach ($ids as $orderId) {
                    $entry = orderBuildExcelEntry($pdo, $orderId);
                    if (!$entry) {
                        $missing[] = $orderId;
                        continue;
                    }
                    $entries[] = $entry;
                }
                if ($missing) {
                    jsonError('One or more selected records are unavailable.', 404, ['ids' => $missing]);
                }

                $auditPayload = [
                    'order_ids' => $ids,
                    'record_count' => count($entries),
                    'format' => count($entries) === 1 ? 'xlsx' : 'zip',
                ];
                if (orderTableExists($pdo, 'audit_log')) {
                    $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('order', 0, 'bulk_export', ?, ?)")
                        ->execute([json_encode($auditPayload, JSON_UNESCAPED_UNICODE), getAuthUserId()]);
                }
                logClms('orders_bulk_export', $auditPayload + ['user_id' => getAuthUserId()]);
                (new OrderBulkExcelService())->output($entries, 'selected_orders');
            }
            if ($id === null) {
                $customerId = (int) ($input['customer_id'] ?? 0);
                $creationKey = orderCreationIdempotencyKey($input['idempotency_key'] ?? $input['creation_idempotency_key'] ?? null);
                $supplierId = normalizeExistingSupplierId($pdo, $input['supplier_id'] ?? null, 'supplier_id');
                $expectedDate = normalizeOptionalExpectedReadyDate($input['expected_ready_date'] ?? null);
                $currency = trim($input['currency'] ?? 'USD');
                if (!$customerId) {
                    jsonError('Missing required: customer_id', 400);
                }
                if ($creationKey !== null) {
                    $existing = $pdo->prepare("SELECT id FROM orders WHERE creation_idempotency_key=? AND COALESCE(order_type,'standard')<>'draft_procurement'");
                    $existing->execute([$creationKey]);
                    $existingId = (int) $existing->fetchColumn();
                    if ($existingId > 0) {
                        $stmt = $pdo->prepare("SELECT o.*,c.name customer_name,s.name supplier_name FROM orders o JOIN customers c ON c.id=o.customer_id LEFT JOIN suppliers s ON s.id=o.supplier_id WHERE o.id=?");
                        $stmt->execute([$existingId]);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        $row['items'] = normalizeOrderItems($pdo, fetchOrderItems($pdo, $existingId));
                        jsonResponse(['data' => $row, 'idempotent_replay' => true]);
                    }
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
                validateOrderItemSupplierIds($pdo, $items);
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
                    $customerLock = $pdo->prepare("SELECT id FROM customers WHERE id=? FOR UPDATE");
                    $customerLock->execute([$customerId]);
                    if (!$customerLock->fetchColumn()) throw new RuntimeException('Customer not found');
                    if ($creationKey !== null) {
                        $existing = $pdo->prepare("SELECT id FROM orders WHERE creation_idempotency_key=? FOR UPDATE");
                        $existing->execute([$creationKey]);
                        $existingId = (int) $existing->fetchColumn();
                        if ($existingId > 0) {
                            $pdo->commit();
                            $stmt = $pdo->prepare("SELECT o.*,c.name customer_name,s.name supplier_name FROM orders o JOIN customers c ON c.id=o.customer_id LEFT JOIN suppliers s ON s.id=o.supplier_id WHERE o.id=?");
                            $stmt->execute([$existingId]);
                            $row = $stmt->fetch(PDO::FETCH_ASSOC);
                            $row['items'] = normalizeOrderItems($pdo, fetchOrderItems($pdo, $existingId));
                            jsonResponse(['data' => $row, 'idempotent_replay' => true]);
                        }
                    }
                    $items = normalizeOrderItemsForPersistence($pdo, $customerId, $destinationCountryId, $supplierId ?: null, $input['items'] ?? [], 'Draft');
                    validateOrderItemSupplierIds($pdo, $items);
                    $dupWarn = enforceDuplicateShippingCodePolicy($pdo, $customerId, 0, $items);
                    $hasDestCountry = orderTableHasColumn($pdo, 'orders', 'destination_country_id');
                    $insCols = "customer_id, supplier_id, expected_ready_date, currency, status, high_alert_notes, created_by, creation_idempotency_key";
                    $insVals = "?,?,?,?,'Draft',?,?,?";
                    $insParams = [$customerId, $supplierId ?: null, $expectedDate, $currency, $highAlertNotes, $userId, $creationKey];
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
                    $metadataColumns = [];
                    foreach (['what_brand', 'brand', 'materials', 'copy_normal_goods', 'code', 'express_number', 'size', 'length', 'width', 'height'] as $column) {
                        if (orderTableHasColumn($pdo, 'order_items', $column)) {
                            $metadataColumns[] = $column;
                        }
                    }
                    $insCols = "order_id, product_id, item_no, shipping_code, cartons, qty_per_carton, quantity, unit, declared_cbm, declared_weight, item_length, item_width, item_height, unit_price, total_amount, notes, image_paths, description_cn, description_en";
                    $insVals = "?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?";
                    foreach ($metadataColumns as $column) {
                        $insCols .= ", $column";
                        $insVals .= ",?";
                    }
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
                            orderNormalizeOptionalDecimal($it['item_length'] ?? $it['length'] ?? null, 'Length'),
                            orderNormalizeOptionalDecimal($it['item_width'] ?? $it['width'] ?? null, 'Width'),
                            orderNormalizeOptionalDecimal($it['item_height'] ?? $it['height'] ?? null, 'Height'),
                            $unitPrice,
                            $totalAmount,
                            $it['notes'] ?? null,
                            $imagePaths,
                            $it['description_cn'] ?? null,
                            $it['description_en'] ?? null
                        ];
                        foreach ($metadataColumns as $column) {
                            $params[] = orderNormalizeItemMetadataValue($column, $it);
                        }
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
                    (new ItemNumberReservationService($pdo))->reservePersistedOrder($orderId,(int)$userId);
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
                    $row['items'] = normalizeOrderItems($pdo, fetchOrderItems($pdo, $orderId));
                    jsonResponse(array_filter(['data' => $row, 'warning' => $dupWarn]), 201);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
            }
            if ($id && $action === 'receive') {
                $stmt = $pdo->prepare("SELECT customer_id FROM orders WHERE id = ? LIMIT 1");
                $stmt->execute([(int) $id]);
                $customerId = (int) ($stmt->fetchColumn() ?: 0);
                if ($customerId <= 0) jsonError('Order not found', 404);
                try {
                    $result = (new OrderReceivingService())->receive($pdo, (int) $id, $input, $userId);
                    jsonResponse(['data' => $result]);
                } catch (OrderReceivingValidationException $e) {
                    jsonError($e->getMessage(), $e->getStatusCode(), $e->getFieldErrors());
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
                } elseif (!$token) {
                    requireAuth();
                }
                OrderReceiptWorkflowService::acceptAutoConfirmedOrder($pdo, (int) $id, $userId, 'confirm');
                jsonResponse(['data' => ['status' => 'ReadyForConsolidation']]);
            }
            if ($id && $action === 'reset-after-decline') {
                OrderReceiptWorkflowService::resetDeclinedOrder($pdo, (int) $id, $userId, trim((string) ($input['reason'] ?? '')) ?: null);
                jsonResponse(['data' => ['status' => 'Submitted']]);
            }
            if ($id && in_array($action, ['submit', 'approve'], true)) {
                $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
                $stmt->execute([$id]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$order) jsonError('Order not found', 404);
                if ($action === 'submit') {
                    orderHandleLifecycleTransition('submit', (string) ($order['status'] ?? ''), 'Submitted');
                    $si = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE order_id = ?");
                    $si->execute([$id]);
                    if ((int) $si->fetchColumn() === 0) {
                        jsonError('Order must have at least one item to submit', 400);
                    }
                    $config = require dirname(__DIR__, 2) . '/config/config.php';
                    $minPhotos = (int) ($config['min_photos_per_item'] ?? 0);
                    $submitWarning = null;
                    $skipPhotoMinimum = in_array((string) ($order['order_type'] ?? ''), ['draft_procurement'], true);
                    if ($minPhotos > 0) {
                        $itemsWithPhotos = $pdo->prepare("SELECT id, image_paths FROM order_items WHERE order_id = ?");
                        $itemsWithPhotos->execute([$id]);
                        $missingPhotoItems = [];
                        while ($row = $itemsWithPhotos->fetch(PDO::FETCH_ASSOC)) {
                            $paths = $row['image_paths'] ? (json_decode($row['image_paths'], true) ?? []) : [];
                            if (!is_array($paths) || count($paths) < $minPhotos) {
                                if ($skipPhotoMinimum) {
                                    $missingPhotoItems[] = (int) $row['id'];
                                    continue;
                                }
                                jsonError("Each item must have at least $minPhotos photo(s). Item #{$row['id']} has insufficient photos.", 400);
                            }
                        }
                        if ($skipPhotoMinimum && !empty($missingPhotoItems)) {
                            $submitWarning = 'Photos are optional for Draft Orders at this stage. Some items are missing photos.';
                        }
                    }
                    $pdo->prepare("UPDATE orders SET status='Submitted' WHERE id=?")->execute([$id]);
                    $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, user_id) VALUES ('order',?,'submit',?)")->execute([$id, $userId]);
                    (new NotificationService($pdo))->notifyOrderSubmitted((int) $id);
                    $response = ['data' => ['status' => 'Submitted']];
                    if ($submitWarning) {
                        $response['warning'] = $submitWarning;
                    }
                    jsonResponse($response);
                }
                if ($action === 'approve') {
                    $pdo->beginTransaction();
                    try {
                        $locked=$pdo->prepare('SELECT status FROM orders WHERE id=? FOR UPDATE');$locked->execute([(int)$id]);$lockedStatus=(string)$locked->fetchColumn();
                        if($lockedStatus==='Approved'){
                            $accounting=(new ShipmentAccountingService($pdo))->summarizeOrder((int)$id);
                            $pdo->commit();
                            jsonResponse(['data'=>['status'=>'Approved','already_applied'=>true,'accounting'=>$accounting],'message'=>'Order already approved']);
                        }
                        orderHandleLifecycleTransition('approve',$lockedStatus,'Approved');
                        $accounting=(new ShipmentAccountingService($pdo))->finalizeOrder((int)$id,(int)$userId);
                        $pdo->prepare("UPDATE orders SET status='Approved', lock_version=lock_version+1 WHERE id=?")->execute([$id]);
                        $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('order',?,'approve',?,?)")
                            ->execute([$id,json_encode(['accounting_finalized'=>true],JSON_UNESCAPED_UNICODE),$userId]);
                        (new NotificationService($pdo))->notifyOrderApproved((int)$id);
                        $pdo->commit();
                        jsonResponse(['data'=>['status'=>'Approved','accounting'=>$accounting]]);
                    } catch(Throwable $e) {
                        if($pdo->inTransaction())$pdo->rollBack();
                        throw $e;
                    }
                }
            }
            jsonError('Invalid action', 400);
            break;
    }

    jsonError('Method not allowed', 405);
};
