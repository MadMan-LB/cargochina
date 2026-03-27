<?php

/**
 * Draft Orders API
 * Real orders saved with order_type = draft_procurement
 * Roles: ChinaAdmin, ChinaEmployee, SuperAdmin
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 2) . '/services/NotificationService.php';
require_once dirname(__DIR__, 2) . '/services/OrderCountryService.php';
require_once dirname(__DIR__, 2) . '/services/OrderItemNumberingService.php';
require_once dirname(__DIR__, 2) . '/services/TranslationService.php';
require_once dirname(__DIR__, 2) . '/services/OrderExcelService.php';

function draftOrderTableHasColumn(PDO $pdo, string $table, string $column): bool
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

function draftOrderHasProductDescEntries(PDO $pdo): bool
{
    static $checked = null;

    if ($checked !== null) {
        return $checked;
    }

    try {
        $pdo->query("SELECT 1 FROM product_description_entries LIMIT 1");
        $checked = true;
    } catch (Throwable $e) {
        $checked = false;
    }

    return $checked;
}

function draftOrderNormalizeHsCode(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    return strtoupper($value);
}

function draftOrderNormalizeExpectedReadyDate($value): ?string
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

function draftOrderResolveExpectedReadyDate(array $input, array $order = []): ?string
{
    if (!array_key_exists('expected_ready_date', $input)) {
        $existing = trim((string) ($order['expected_ready_date'] ?? ''));
        return $existing !== '' ? $existing : null;
    }

    return draftOrderNormalizeExpectedReadyDate($input['expected_ready_date'] ?? null);
}

function draftOrderResolveDestinationCountryId(PDO $pdo, int $customerId, array $input, array $order = []): ?int
{
    if (!draftOrderTableHasColumn($pdo, 'orders', 'destination_country_id')) {
        return null;
    }

    $requestedCountryId = null;
    if (array_key_exists('destination_country_id', $input)) {
        $requestedCountryId = !empty($input['destination_country_id'])
            ? (int) $input['destination_country_id']
            : null;
    } elseif (!empty($order['destination_country_id'])) {
        $requestedCountryId = (int) $order['destination_country_id'];
    }

    return OrderCountryService::resolveDestinationCountryId(
        $pdo,
        $customerId,
        $requestedCountryId
    );
}

function draftOrderTranslationService(PDO $pdo): TranslationService
{
    static $instances = [];

    $key = spl_object_id($pdo);
    if (!isset($instances[$key])) {
        $instances[$key] = new TranslationService($pdo);
    }

    return $instances[$key];
}

function draftOrderContainsChinese(string $text): bool
{
    return preg_match('/[\x{4e00}-\x{9fff}]/u', $text) === 1;
}

function draftOrderTranslateText(PDO $pdo, string $text, string $sourceLang, string $targetLang): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $sourceLang = strtolower(substr(trim($sourceLang), 0, 2)) ?: 'auto';
    $targetLang = strtolower(substr(trim($targetLang), 0, 2)) ?: 'en';
    if ($sourceLang === $targetLang) {
        return $text;
    }

    return draftOrderTranslationService($pdo)->translate($text, $sourceLang, $targetLang);
}

function draftOrderNormalizeDescriptionPair(PDO $pdo, string $text, string $translated): array
{
    $text = trim($text);
    $translated = trim($translated);
    if ($text === '' && $translated === '') {
        return [
            'description_text' => '',
            'description_translated' => '',
        ];
    }

    $textHasChinese = draftOrderContainsChinese($text);
    $translatedHasChinese = draftOrderContainsChinese($translated);

    if ($text !== '' && $translated !== '') {
        if ($textHasChinese && !$translatedHasChinese) {
            return [
                'description_text' => $text,
                'description_translated' => $translated,
            ];
        }
        if (!$textHasChinese && $translatedHasChinese) {
            return [
                'description_text' => $translated,
                'description_translated' => $text,
            ];
        }
        if ($text === $translated) {
            if ($textHasChinese) {
                return [
                    'description_text' => $text,
                    'description_translated' => draftOrderTranslateText($pdo, $text, 'zh', 'en'),
                ];
            }
            return [
                'description_text' => draftOrderTranslateText($pdo, $text, 'en', 'zh'),
                'description_translated' => $text,
            ];
        }

        return [
            'description_text' => $text,
            'description_translated' => $translated,
        ];
    }

    $source = $text !== '' ? $text : $translated;
    if (draftOrderContainsChinese($source)) {
        return [
            'description_text' => $source,
            'description_translated' => draftOrderTranslateText($pdo, $source, 'zh', 'en'),
        ];
    }

    return [
        'description_text' => draftOrderTranslateText($pdo, $source, 'en', 'zh'),
        'description_translated' => $source,
    ];
}

function draftOrderSplitDescriptionEntries(?string $cn, ?string $en): array
{
    $cnParts = array_values(array_filter(array_map(
        static fn($v) => trim((string) $v),
        preg_split('/\s*\|\s*/', (string) ($cn ?? '')) ?: []
    ), static fn($v) => $v !== ''));
    $enParts = array_values(array_filter(array_map(
        static fn($v) => trim((string) $v),
        preg_split('/\s*\|\s*/', (string) ($en ?? '')) ?: []
    ), static fn($v) => $v !== ''));

    $count = max(count($cnParts), count($enParts));
    $entries = [];
    for ($i = 0; $i < $count; $i++) {
        $text = $cnParts[$i] ?? $enParts[$i] ?? '';
        $translated = $enParts[$i] ?? $cnParts[$i] ?? '';
        if ($text === '' && $translated === '') {
            continue;
        }
        $entries[] = [
            'description_text' => $text,
            'description_translated' => $translated,
        ];
    }

    return $entries;
}

function draftOrderBuildDescriptionStrings(PDO $pdo, array $entries): array
{
    $normalized = [];
    $cnParts = [];
    $enParts = [];

    foreach ($entries as $entry) {
        $text = trim((string) ($entry['description_text'] ?? $entry['text'] ?? ''));
        $translated = trim((string) ($entry['description_translated'] ?? $entry['translated'] ?? ''));
        if ($text === '' && $translated === '') {
            continue;
        }
        $pair = draftOrderNormalizeDescriptionPair($pdo, $text, $translated);
        $normalized[] = $pair;
        $cnParts[] = $pair['description_text'];
        $enParts[] = $pair['description_translated'];
    }

    return [
        'entries' => $normalized,
        'description_cn' => $cnParts ? implode(' | ', $cnParts) : null,
        'description_en' => $enParts ? implode(' | ', $enParts) : null,
    ];
}

function draftOrderGetQuantity(array $item): float
{
    $cartons = (int) ($item['cartons'] ?? 0);
    $piecesPerCarton = (float) ($item['pieces_per_carton'] ?? $item['qty_per_carton'] ?? 0);
    if ($cartons > 0 && $piecesPerCarton > 0) {
        return round($cartons * $piecesPerCarton, 4);
    }

    return (float) ($item['quantity'] ?? 0);
}

function draftOrderPerUnitCbm(array $item): float
{
    $cbmMode = trim((string) ($item['cbm_mode'] ?? 'direct'));
    if ($cbmMode === 'dimensions') {
        $l = (float) ($item['item_length'] ?? 0);
        $w = (float) ($item['item_width'] ?? 0);
        $h = (float) ($item['item_height'] ?? 0);
        return ($l > 0 && $w > 0 && $h > 0) ? round(($l * $w * $h) / 1000000, 6) : 0.0;
    }

    $direct = (float) ($item['cbm'] ?? 0);
    if ($direct > 0) {
        return round($direct, 6);
    }

    $l = (float) ($item['item_length'] ?? 0);
    $w = (float) ($item['item_width'] ?? 0);
    $h = (float) ($item['item_height'] ?? 0);
    return ($l > 0 && $w > 0 && $h > 0) ? round(($l * $w * $h) / 1000000, 6) : 0.0;
}

function draftOrderPerUnitWeight(array $item): float
{
    return round((float) ($item['weight'] ?? 0), 4);
}

function draftOrderCheckDuplicateShippingCodes(PDO $pdo, int $customerId, int $excludeOrderId, array $items): ?string
{
    $codes = [];
    foreach ($items as $item) {
        $code = trim((string) ($item['shipping_code'] ?? ''));
        if ($code !== '') {
            $codes[] = $code;
        }
    }
    if (!$codes) {
        return null;
    }

    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $stmt = $pdo->prepare(
        "SELECT DISTINCT oi.shipping_code
         FROM order_items oi
         JOIN orders o ON oi.order_id = o.id
         WHERE o.customer_id = ?
           AND o.id != ?
           AND oi.shipping_code IN ($placeholders)
           AND TRIM(COALESCE(oi.shipping_code, '')) <> ''"
    );
    $params = array_merge([$customerId, $excludeOrderId], $codes);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$rows) {
        return null;
    }

    return 'Duplicate shipping code(s) for this customer: ' . implode(', ', array_unique($rows));
}

function draftOrderEnforceDuplicateShippingCodePolicy(PDO $pdo, int $customerId, int $excludeOrderId, array $items): ?string
{
    $warning = draftOrderCheckDuplicateShippingCodes($pdo, $customerId, $excludeOrderId, $items);
    if ($warning === null) {
        return null;
    }

    $action = getBusinessSetting($pdo, 'SHIPPING_CODE_DUPLICATE_ACTION', 'warn');
    if ($action === 'block') {
        jsonError($warning, 409);
    }

    return $warning;
}

function draftOrderInsertDescriptionEntries(PDO $pdo, int $productId, array $entries): void
{
    if (!$entries || !draftOrderHasProductDescEntries($pdo)) {
        return;
    }

    $ins = $pdo->prepare("INSERT INTO product_description_entries (product_id, description_text, description_translated, sort_order) VALUES (?, ?, ?, ?)");
    foreach ($entries as $index => $entry) {
        $text = trim((string) ($entry['description_text'] ?? ''));
        if ($text === '') {
            continue;
        }
        $translated = trim((string) ($entry['description_translated'] ?? '')) ?: null;
        $ins->execute([$productId, $text, $translated, $index]);
    }
}

function draftOrderCreateProduct(PDO $pdo, array $item): int
{
    $columns = ['supplier_id', 'cbm', 'weight', 'length_cm', 'width_cm', 'height_cm', 'packaging', 'hs_code', 'description_cn', 'description_en', 'image_paths'];
    $values = [
        (int) $item['supplier_id'],
        $item['cbm_per_unit'],
        $item['weight_per_unit'],
        $item['item_length'],
        $item['item_width'],
        $item['item_height'],
        null,
        $item['hs_code'],
        $item['description_cn'],
        $item['description_en'],
        $item['photo_paths'] ? json_encode($item['photo_paths']) : null,
    ];

    if (draftOrderTableHasColumn($pdo, 'products', 'dimensions_scope')) {
        $columns[] = 'dimensions_scope';
        $values[] = $item['dimensions_scope'];
    }
    if (draftOrderTableHasColumn($pdo, 'products', 'required_design')) {
        $columns[] = 'required_design';
        $values[] = !empty($item['custom_design_required']) ? 1 : 0;
    }
    if (draftOrderTableHasColumn($pdo, 'products', 'pieces_per_carton')) {
        $columns[] = 'pieces_per_carton';
        $values[] = $item['pieces_per_carton'];
    }
    if (draftOrderTableHasColumn($pdo, 'products', 'unit_price')) {
        $columns[] = 'unit_price';
        $values[] = $item['unit_price'];
    }

    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $stmt = $pdo->prepare("INSERT INTO products (" . implode(', ', $columns) . ") VALUES ($placeholders)");
    $stmt->execute($values);
    $productId = (int) $pdo->lastInsertId();
    draftOrderInsertDescriptionEntries($pdo, $productId, $item['description_entries']);

    return $productId;
}

function draftOrderSafeSyncProduct(PDO $pdo, array $item): void
{
    $productId = !empty($item['product_id']) ? (int) $item['product_id'] : 0;
    if ($productId <= 0) {
        return;
    }

    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        return;
    }

    if (!empty($product['supplier_id']) && (int) $product['supplier_id'] !== (int) $item['supplier_id']) {
        jsonError('Selected product belongs to another supplier. Create a separate supplier section instead.', 400);
    }

    $assignIfEmpty = static function ($current): bool {
        if ($current === null) {
            return true;
        }
        if (is_string($current) && trim($current) === '') {
            return true;
        }
        if (is_numeric($current) && (float) $current <= 0) {
            return true;
        }
        return false;
    };

    $sets = [];
    $values = [];

    if ($assignIfEmpty($product['description_cn'] ?? null) && !empty($item['description_cn'])) {
        $sets[] = 'description_cn = ?';
        $values[] = $item['description_cn'];
    }
    if ($assignIfEmpty($product['description_en'] ?? null) && !empty($item['description_en'])) {
        $sets[] = 'description_en = ?';
        $values[] = $item['description_en'];
    }
    if (draftOrderTableHasColumn($pdo, 'products', 'pieces_per_carton')
        && $assignIfEmpty($product['pieces_per_carton'] ?? null)
        && !empty($item['pieces_per_carton'])) {
        $sets[] = 'pieces_per_carton = ?';
        $values[] = (int) $item['pieces_per_carton'];
    }
    if (draftOrderTableHasColumn($pdo, 'products', 'unit_price')
        && $assignIfEmpty($product['unit_price'] ?? null)
        && $item['unit_price'] !== null) {
        $sets[] = 'unit_price = ?';
        $values[] = $item['unit_price'];
    }
    if ($assignIfEmpty($product['hs_code'] ?? null) && !empty($item['hs_code'])) {
        $sets[] = 'hs_code = ?';
        $values[] = $item['hs_code'];
    }
    if ($assignIfEmpty($product['cbm'] ?? null) && $item['cbm_per_unit'] > 0) {
        $sets[] = 'cbm = ?';
        $values[] = $item['cbm_per_unit'];
    }
    if ($assignIfEmpty($product['weight'] ?? null) && $item['weight_per_unit'] > 0) {
        $sets[] = 'weight = ?';
        $values[] = $item['weight_per_unit'];
    }
    foreach ([
        'length_cm' => 'item_length',
        'width_cm' => 'item_width',
        'height_cm' => 'item_height',
    ] as $column => $itemKey) {
        if ($assignIfEmpty($product[$column] ?? null) && !empty($item[$itemKey])) {
            $sets[] = $column . ' = ?';
            $values[] = $item[$itemKey];
        }
    }
    if (draftOrderTableHasColumn($pdo, 'products', 'required_design')
        && !empty($item['custom_design_required'])
        && empty($product['required_design'])) {
        $sets[] = 'required_design = ?';
        $values[] = 1;
    }
    if ($assignIfEmpty($product['image_paths'] ?? null) && !empty($item['photo_paths'])) {
        $sets[] = 'image_paths = ?';
        $values[] = json_encode($item['photo_paths']);
    }

    if ($sets) {
        $values[] = $productId;
        $pdo->prepare("UPDATE products SET " . implode(', ', $sets) . " WHERE id = ?")->execute($values);
    }

    if (draftOrderHasProductDescEntries($pdo)) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM product_description_entries WHERE product_id = ?");
        $check->execute([$productId]);
        if ((int) $check->fetchColumn() === 0) {
            draftOrderInsertDescriptionEntries($pdo, $productId, $item['description_entries']);
        }
    }
}

function draftOrderFetchProduct(PDO $pdo, int $productId): ?array
{
    $stmt = $pdo->prepare("SELECT p.*, s.name as supplier_name FROM products p LEFT JOIN suppliers s ON p.supplier_id = s.id WHERE p.id = ?");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        return null;
    }

    if (draftOrderHasProductDescEntries($pdo)) {
        $entries = $pdo->prepare("SELECT description_text, description_translated, sort_order FROM product_description_entries WHERE product_id = ? ORDER BY sort_order, id");
        $entries->execute([$productId]);
        $product['description_entries'] = $entries->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $product['description_entries'] = draftOrderSplitDescriptionEntries($product['description_cn'] ?? null, $product['description_en'] ?? null);
    }

    return $product;
}

function draftOrderNormalizeItem(PDO $pdo, array $rawItem, int $supplierId, ?array $product = null): array
{
    $description = draftOrderBuildDescriptionStrings($pdo, $rawItem['description_entries'] ?? []);
    if (!$description['entries']) {
        $description = draftOrderBuildDescriptionStrings($pdo, [[
            'description_text' => $rawItem['description_cn'] ?? $rawItem['description'] ?? '',
            'description_translated' => $rawItem['description_en'] ?? $rawItem['description'] ?? '',
        ]]);
    }
    if (!$description['entries'] && $product) {
        $description = draftOrderBuildDescriptionStrings($pdo, $product['description_entries'] ?? []);
    }
    if (!$description['entries']) {
        jsonError('Each item needs a description.', 400);
    }

    $productScope = $product['dimensions_scope'] ?? $product['product_dimensions_scope'] ?? null;
    $dimensionsScope = strtolower(trim((string) ($rawItem['dimensions_scope'] ?? $productScope ?? 'carton')));
    if (!in_array($dimensionsScope, ['piece', 'carton'], true)) {
        $dimensionsScope = 'carton';
    }

    $cartons = (int) ($rawItem['cartons'] ?? 0);
    $piecesPerCarton = (float) ($rawItem['pieces_per_carton'] ?? $rawItem['qty_per_carton'] ?? 0);
    if ($cartons <= 0 || $piecesPerCarton <= 0) {
        jsonError('Each item needs cartons and pieces per carton.', 400);
    }

    $quantity = round($cartons * $piecesPerCarton, 4);
    $cbmPerUnit = draftOrderPerUnitCbm($rawItem);
    if ($cbmPerUnit <= 0) {
        jsonError('Each item needs CBM directly or dimensions (L/W/H).', 400);
    }

    $weightPerUnit = draftOrderPerUnitWeight($rawItem);
    if ($weightPerUnit < 0) {
        jsonError('Weight must be zero or positive.', 400);
    }

    $multiplier = $dimensionsScope === 'carton' ? $cartons : $quantity;
    $declaredCbm = round($cbmPerUnit * $multiplier, 6);
    $declaredWeight = round($weightPerUnit * $multiplier, 4);
    $unitPrice = isset($rawItem['unit_price']) && $rawItem['unit_price'] !== '' ? round((float) $rawItem['unit_price'], 4) : null;
    $totalAmount = $unitPrice !== null ? round($unitPrice * $quantity, 4) : null;
    $photoPaths = normalizeStoredUploadPathList($rawItem['photo_paths'] ?? []);
    $customDesignPaths = normalizeStoredUploadPathList($rawItem['custom_design_paths'] ?? []);
    $customDesignRequired = !empty($rawItem['custom_design_required']) ? 1 : 0;
    $customDesignNote = trim((string) ($rawItem['custom_design_note'] ?? '')) ?: null;
    if ($customDesignRequired && !$customDesignNote && !$customDesignPaths) {
        jsonError('Custom design items need a note or at least one design file.', 400);
    }

    if ($product && !empty($product['supplier_id']) && (int) $product['supplier_id'] !== $supplierId) {
        jsonError('Selected product belongs to another supplier. Create a separate supplier section instead.', 400);
    }

    return [
        'product_id' => !empty($rawItem['product_id']) ? (int) $rawItem['product_id'] : null,
        'supplier_id' => $supplierId,
        'item_no' => trim((string) ($rawItem['item_no'] ?? '')) ?: null,
        'shipping_code' => trim((string) ($rawItem['shipping_code'] ?? '')) ?: null,
        'cartons' => $cartons,
        'pieces_per_carton' => round($piecesPerCarton, 4),
        'quantity' => $quantity,
        'unit' => 'pieces',
        'unit_price' => $unitPrice,
        'total_amount' => $totalAmount,
        'cbm_mode' => ($rawItem['cbm_mode'] ?? 'direct') === 'dimensions' ? 'dimensions' : 'direct',
        'dimensions_scope' => $dimensionsScope,
        'cbm_per_unit' => $cbmPerUnit,
        'weight_per_unit' => $weightPerUnit,
        'declared_cbm' => $declaredCbm,
        'declared_weight' => $declaredWeight,
        'item_length' => isset($rawItem['item_length']) && $rawItem['item_length'] !== '' ? round((float) $rawItem['item_length'], 4) : null,
        'item_width' => isset($rawItem['item_width']) && $rawItem['item_width'] !== '' ? round((float) $rawItem['item_width'], 4) : null,
        'item_height' => isset($rawItem['item_height']) && $rawItem['item_height'] !== '' ? round((float) $rawItem['item_height'], 4) : null,
        'hs_code' => draftOrderNormalizeHsCode($rawItem['hs_code'] ?? ($product['hs_code'] ?? null)),
        'description_entries' => $description['entries'],
        'description_cn' => $description['description_cn'],
        'description_en' => $description['description_en'],
        'photo_paths' => $photoPaths,
        'custom_design_required' => $customDesignRequired,
        'custom_design_note' => $customDesignNote,
        'custom_design_paths' => $customDesignPaths,
    ];
}

function draftOrderFlattenSections(PDO $pdo, array $sections): array
{
    $normalized = [];
    foreach ($sections as $section) {
        $supplierId = (int) ($section['supplier_id'] ?? 0);
        if ($supplierId <= 0) {
            jsonError('Each supplier section needs a supplier.', 400);
        }

        foreach (($section['items'] ?? []) as $rawItem) {
            $product = null;
            if (!empty($rawItem['product_id'])) {
                $product = draftOrderFetchProduct($pdo, (int) $rawItem['product_id']);
                if (!$product) {
                    jsonError('Selected product not found.', 404);
                }
            }
            $normalized[] = draftOrderNormalizeItem($pdo, $rawItem, $supplierId, $product);
        }
    }

    if (!$normalized) {
        jsonError('At least one supplier section with one item is required.', 400);
    }

    return $normalized;
}

function draftOrderAssignCanonicalItemNumbers(PDO $pdo, int $customerId, array $items, ?int $defaultSupplierId = null, ?int $destinationCountryId = null): array
{
    $shippingCode = OrderCountryService::resolveShippingCode($pdo, $customerId, $destinationCountryId);
    return OrderItemNumberingService::assignItemNumbers($items, $shippingCode, $defaultSupplierId);
}

function draftOrderInsertDesignAttachments(PDO $pdo, int $itemId, array $paths, ?string $note, ?int $userId): void
{
    if (!$paths) {
        return;
    }

    $ins = $pdo->prepare("INSERT INTO design_attachments (entity_type, entity_id, file_path, file_type, uploaded_by, internal_note) VALUES ('order_item', ?, ?, ?, ?, ?)");
    foreach ($paths as $path) {
        $ins->execute([
            $itemId,
            $path,
            strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) ?: null,
            $userId ?: null,
            $note,
        ]);
    }
}

function draftOrderInsertItems(PDO $pdo, int $orderId, ?int $defaultSupplierId, array $items, ?int $userId): array
{
    $hasItemSupplier = draftOrderTableHasColumn($pdo, 'order_items', 'supplier_id');
    $hasSellPrice = draftOrderTableHasColumn($pdo, 'order_items', 'sell_price');
    $hasOrderCartons = draftOrderTableHasColumn($pdo, 'order_items', 'order_cartons');
    $hasOrderQtyPerCarton = draftOrderTableHasColumn($pdo, 'order_items', 'order_qty_per_carton');
    $hasHsCode = draftOrderTableHasColumn($pdo, 'order_items', 'hs_code');
    $hasCustomDesignRequired = draftOrderTableHasColumn($pdo, 'order_items', 'custom_design_required');
    $hasCustomDesignNote = draftOrderTableHasColumn($pdo, 'order_items', 'custom_design_note');

    $columns = "order_id, product_id, item_no, shipping_code, cartons, qty_per_carton, quantity, unit, declared_cbm, declared_weight, item_length, item_width, item_height, unit_price, total_amount, notes, image_paths, description_cn, description_en";
    $placeholders = "?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?";
    if ($hasItemSupplier) {
        $columns .= ", supplier_id";
        $placeholders .= ",?";
    }
    if ($hasSellPrice) {
        $columns .= ", sell_price";
        $placeholders .= ",?";
    }
    if ($hasOrderCartons) {
        $columns .= ", order_cartons";
        $placeholders .= ",?";
    }
    if ($hasOrderQtyPerCarton) {
        $columns .= ", order_qty_per_carton";
        $placeholders .= ",?";
    }
    if ($hasHsCode) {
        $columns .= ", hs_code";
        $placeholders .= ",?";
    }
    if ($hasCustomDesignRequired) {
        $columns .= ", custom_design_required";
        $placeholders .= ",?";
    }
    if ($hasCustomDesignNote) {
        $columns .= ", custom_design_note";
        $placeholders .= ",?";
    }

    $insert = $pdo->prepare("INSERT INTO order_items ($columns) VALUES ($placeholders)");
    foreach ($items as &$item) {
        if (empty($item['product_id'])) {
            $item['product_id'] = draftOrderCreateProduct($pdo, $item);
        } else {
            draftOrderSafeSyncProduct($pdo, $item);
        }

        $itemSupplierId = $item['supplier_id'] ?: $defaultSupplierId;
        $params = [
            $orderId,
            $item['product_id'] ?: null,
            $item['item_no'],
            $item['shipping_code'],
            $item['cartons'],
            $item['pieces_per_carton'],
            $item['quantity'],
            $item['unit'],
            $item['declared_cbm'],
            $item['declared_weight'],
            $item['item_length'],
            $item['item_width'],
            $item['item_height'],
            $item['unit_price'],
            $item['total_amount'],
            null,
            $item['photo_paths'] ? json_encode($item['photo_paths']) : null,
            $item['description_cn'],
            $item['description_en'],
        ];
        if ($hasItemSupplier) {
            $params[] = $itemSupplierId ?: null;
        }
        if ($hasSellPrice) {
            $params[] = null;
        }
        if ($hasOrderCartons) {
            $params[] = $item['cartons'];
        }
        if ($hasOrderQtyPerCarton) {
            $params[] = $item['pieces_per_carton'];
        }
        if ($hasHsCode) {
            $params[] = $item['hs_code'];
        }
        if ($hasCustomDesignRequired) {
            $params[] = $item['custom_design_required'] ? 1 : 0;
        }
        if ($hasCustomDesignNote) {
            $params[] = $item['custom_design_note'];
        }
        $insert->execute($params);
        $item['id'] = (int) $pdo->lastInsertId();
        draftOrderInsertDesignAttachments($pdo, $item['id'], $item['custom_design_paths'], $item['custom_design_note'], $userId);
    }
    unset($item);

    return $items;
}

function draftOrderFetchOrderItemRows(PDO $pdo, int $orderId): array
{
    $select = "oi.*, s.name as supplier_name, p.hs_code as product_hs_code, p.dimensions_scope as product_dimensions_scope";
    $stmt = $pdo->prepare(
        "SELECT $select
         FROM order_items oi
         LEFT JOIN suppliers s ON oi.supplier_id = s.id
         LEFT JOIN products p ON oi.product_id = p.id
         WHERE oi.order_id = ?
         ORDER BY COALESCE(oi.supplier_id, 0), oi.id"
    );
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $itemIds = array_map(static fn($row) => (int) $row['id'], $items);
    $designMap = [];
    if ($itemIds) {
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $designStmt = $pdo->prepare(
            "SELECT entity_id, file_path, internal_note
             FROM design_attachments
             WHERE entity_type = 'order_item'
               AND entity_id IN ($placeholders)
             ORDER BY uploaded_at ASC, id ASC"
        );
        $designStmt->execute($itemIds);
        foreach ($designStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $entityId = (int) $row['entity_id'];
            if (!isset($designMap[$entityId])) {
                $designMap[$entityId] = ['paths' => [], 'note' => null];
            }
            $designMap[$entityId]['paths'][] = normalizeStoredUploadPath((string) $row['file_path'], false);
            if ($designMap[$entityId]['note'] === null && trim((string) ($row['internal_note'] ?? '')) !== '') {
                $designMap[$entityId]['note'] = trim((string) $row['internal_note']);
            }
        }
    }

    foreach ($items as &$item) {
        $item['image_paths'] = $item['image_paths'] ? (json_decode($item['image_paths'], true) ?: []) : [];
        $item['description_entries'] = draftOrderSplitDescriptionEntries($item['description_cn'] ?? null, $item['description_en'] ?? null);
        $scope = strtolower((string) ($item['product_dimensions_scope'] ?? 'carton'));
        if (!in_array($scope, ['piece', 'carton'], true)) {
            $scope = 'carton';
        }
        $multiplier = $scope === 'carton'
            ? ((float) ($item['cartons'] ?? 0) ?: 0)
            : (draftOrderGetQuantity($item) ?: 0);
        $design = $designMap[(int) $item['id']] ?? ['paths' => [], 'note' => null];
        $item['dimensions_scope'] = $scope;
        $item['cbm_per_unit'] = $multiplier > 0 ? round(((float) ($item['declared_cbm'] ?? 0)) / $multiplier, 6) : 0.0;
        $item['weight_per_unit'] = $multiplier > 0 ? round(((float) ($item['declared_weight'] ?? 0)) / $multiplier, 4) : 0.0;
        $item['hs_code'] = $item['hs_code'] ?? $item['product_hs_code'] ?? null;
        $item['custom_design_required'] = !empty($item['custom_design_required']) || !empty($design['paths']) ? 1 : 0;
        $item['custom_design_note'] = $item['custom_design_note'] ?: $design['note'];
        $item['custom_design_paths'] = $design['paths'];
    }
    unset($item);

    return $items;
}

function draftOrderBuildSupplierSections(array $items): array
{
    $sections = [];
    foreach ($items as $item) {
        $supplierId = (int) ($item['supplier_id'] ?? 0);
        $key = $supplierId > 0 ? (string) $supplierId : '0';
        if (!isset($sections[$key])) {
            $sections[$key] = [
                'supplier_id' => $supplierId ?: null,
                'supplier_name' => $item['supplier_name'] ?? 'Unassigned supplier',
                'items' => [],
                'totals' => ['amount' => 0.0, 'cbm' => 0.0, 'weight' => 0.0],
            ];
        }
        $sections[$key]['items'][] = [
            'id' => (int) $item['id'],
            'product_id' => !empty($item['product_id']) ? (int) $item['product_id'] : null,
            'item_no' => $item['item_no'] ?: null,
            'shipping_code' => $item['shipping_code'] ?: null,
            'cartons' => (int) ($item['cartons'] ?? 0),
            'pieces_per_carton' => isset($item['qty_per_carton']) ? (float) $item['qty_per_carton'] : null,
            'quantity' => draftOrderGetQuantity($item),
            'unit_price' => $item['unit_price'] !== null ? (float) $item['unit_price'] : null,
            'total_amount' => $item['total_amount'] !== null ? (float) $item['total_amount'] : null,
            'cbm_mode' => (!empty($item['item_length']) && !empty($item['item_width']) && !empty($item['item_height'])) ? 'dimensions' : 'direct',
            'cbm' => $item['cbm_per_unit'],
            'item_length' => $item['item_length'] !== null ? (float) $item['item_length'] : null,
            'item_width' => $item['item_width'] !== null ? (float) $item['item_width'] : null,
            'item_height' => $item['item_height'] !== null ? (float) $item['item_height'] : null,
            'weight' => $item['weight_per_unit'],
            'dimensions_scope' => $item['dimensions_scope'],
            'hs_code' => draftOrderNormalizeHsCode($item['hs_code'] ?? null),
            'description_entries' => $item['description_entries'] ?? [],
            'photo_paths' => $item['image_paths'] ?? [],
            'custom_design_required' => !empty($item['custom_design_required']) ? 1 : 0,
            'custom_design_note' => $item['custom_design_note'] ?: null,
            'custom_design_paths' => $item['custom_design_paths'] ?? [],
        ];
        $sections[$key]['totals']['amount'] += (float) ($item['total_amount'] ?? 0);
        $sections[$key]['totals']['cbm'] += (float) ($item['declared_cbm'] ?? 0);
        $sections[$key]['totals']['weight'] += (float) ($item['declared_weight'] ?? 0);
    }

    foreach ($sections as &$section) {
        $section['totals']['amount'] = round($section['totals']['amount'], 4);
        $section['totals']['cbm'] = round($section['totals']['cbm'], 6);
        $section['totals']['weight'] = round($section['totals']['weight'], 4);
    }
    unset($section);

    return array_values($sections);
}

function draftOrderFetchOrderPayload(PDO $pdo, int $orderId): array
{
    $destCols = draftOrderTableHasColumn($pdo, 'orders', 'destination_country_id')
        ? ", co.id as destination_country_id, co.name as destination_country_name, co.code as destination_country_code"
        : "";
    $destJoin = draftOrderTableHasColumn($pdo, 'orders', 'destination_country_id')
        ? " LEFT JOIN countries co ON co.id = o.destination_country_id"
        : "";
    $stmt = $pdo->prepare(
        "SELECT o.*, c.name as customer_name, c.default_shipping_code, s.name as supplier_name$destCols
         FROM orders o
         JOIN customers c ON o.customer_id = c.id
         LEFT JOIN suppliers s ON o.supplier_id = s.id
         $destJoin
         WHERE o.id = ?
           AND o.order_type = 'draft_procurement'"
    );
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        jsonError('Draft order not found', 404);
    }

    $items = draftOrderFetchOrderItemRows($pdo, $orderId);
    $sections = draftOrderBuildSupplierSections($items);

    return [
        'id' => (int) $order['id'],
        'order_type' => $order['order_type'],
        'status' => $order['status'],
        'customer_id' => (int) $order['customer_id'],
        'customer_name' => $order['customer_name'],
        'default_shipping_code' => $order['default_shipping_code'] ?: null,
        'destination_country_id' => !empty($order['destination_country_id']) ? (int) $order['destination_country_id'] : null,
        'destination_country_name' => $order['destination_country_name'] ?? null,
        'destination_country_code' => $order['destination_country_code'] ?? null,
        'supplier_id' => $order['supplier_id'] ? (int) $order['supplier_id'] : null,
        'supplier_name' => $order['supplier_name'] ?: null,
        'expected_ready_date' => $order['expected_ready_date'],
        'currency' => $order['currency'] ?: 'USD',
        'high_alert_notes' => $order['high_alert_notes'] ?: null,
        'created_at' => $order['created_at'],
        'updated_at' => $order['updated_at'],
        'editable' => $order['status'] === 'Draft',
        'supplier_sections' => $sections,
        'totals' => [
            'amount' => round(array_reduce($items, static fn($sum, $item) => $sum + (float) ($item['total_amount'] ?? 0), 0.0), 4),
            'cbm' => round(array_reduce($items, static fn($sum, $item) => $sum + (float) ($item['declared_cbm'] ?? 0), 0.0), 6),
            'weight' => round(array_reduce($items, static fn($sum, $item) => $sum + (float) ($item['declared_weight'] ?? 0), 0.0), 4),
        ],
    ];
}

function draftOrderListRows(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT id
         FROM orders
         WHERE order_type = 'draft_procurement'
         ORDER BY created_at DESC, id DESC"
    );

    $list = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $orderId) {
        $payload = draftOrderFetchOrderPayload($pdo, (int) $orderId);
        $payload['supplier_names'] = array_values(array_filter(array_unique(array_map(
            static fn($section) => trim((string) ($section['supplier_name'] ?? '')),
            $payload['supplier_sections']
        ))));
        $payload['item_count'] = array_reduce($payload['supplier_sections'], static fn($sum, $section) => $sum + count($section['items'] ?? []), 0);
        $list[] = $payload;
    }

    return $list;
}

function draftOrderExportCsv(PDO $pdo, int $orderId): void
{
    $order = draftOrderFetchOrderPayload($pdo, $orderId);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="draft_order_' . $orderId . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Draft Order', '#' . $order['id']]);
    $customerItems = [];
    foreach (($order['supplier_sections'] ?? []) as $section) {
        foreach (($section['items'] ?? []) as $item) {
            $customerItems[] = $item;
        }
    }
    fputcsv($out, ['Customer', OrderExcelService::formatCustomerDisplay($order, $customerItems)]);
    fputcsv($out, ['Destination Country', trim((string) (($order['destination_country_name'] ?? '') . (!empty($order['destination_country_code']) ? ' (' . $order['destination_country_code'] . ')' : ''))) ?: '—']);
    fputcsv($out, ['Expected Ready', $order['expected_ready_date']]);
    fputcsv($out, ['Currency', $order['currency']]);
    fputcsv($out, ['Status', $order['status']]);
    fputcsv($out, ['']);

    foreach ($order['supplier_sections'] as $section) {
        fputcsv($out, ['Supplier', $section['supplier_name']]);
        fputcsv($out, ['Item No', 'Shipping Code', 'Description', 'HS Code', 'Pieces/Carton', 'Cartons', 'Quantity', 'Unit Price', 'Total Amount', 'CBM/Unit', 'Total CBM', 'Weight/Unit', 'Total Weight', 'Custom Design']);
        foreach ($section['items'] as $item) {
            $desc = implode(' | ', array_map(
                static fn($entry) => trim((string) ($entry['description_text'] ?? '')),
                $item['description_entries'] ?? []
            ));
            $multiplier = ($item['dimensions_scope'] ?? 'carton') === 'carton'
                ? (float) ($item['cartons'] ?? 0)
                : (float) ($item['quantity'] ?? 0);
            fputcsv($out, [
                $item['item_no'] ?: '',
                $item['shipping_code'] ?: '',
                $desc,
                $item['hs_code'] ?: '',
                $item['pieces_per_carton'] ?? '',
                $item['cartons'] ?? '',
                $item['quantity'] ?? '',
                $item['unit_price'] ?? '',
                $item['total_amount'] ?? '',
                $item['cbm'] ?? '',
                round((float) (($item['cbm'] ?? 0) * $multiplier), 6),
                $item['weight'] ?? '',
                round((float) (($item['weight'] ?? 0) * $multiplier), 4),
                !empty($item['custom_design_required']) ? 'Yes' : 'No',
            ]);
        }
        fputcsv($out, ['', '', 'Supplier subtotal', '', '', '', '', '', $section['totals']['amount'], '', $section['totals']['cbm'], '', $section['totals']['weight'], '']);
        fputcsv($out, ['']);
    }

    fputcsv($out, ['', '', 'Grand total', '', '', '', '', '', $order['totals']['amount'], '', $order['totals']['cbm'], '', $order['totals']['weight'], '']);
    fclose($out);
    exit;
}

function draftOrderExportXlsx(PDO $pdo, int $orderId): void
{
    $order = draftOrderFetchOrderPayload($pdo, $orderId);
    $excelItems = [];

    foreach ($order['supplier_sections'] as $section) {
        foreach (($section['items'] ?? []) as $item) {
            $englishParts = array_values(array_filter(array_map(
                static fn($entry) => trim((string) ($entry['description_translated'] ?? '')),
                $item['description_entries'] ?? []
            )));
            $cnParts = array_values(array_filter(array_map(
                static fn($entry) => trim((string) ($entry['description_text'] ?? '')),
                $item['description_entries'] ?? []
            )));
            $multiplier = ($item['dimensions_scope'] ?? 'carton') === 'carton'
                ? (float) ($item['cartons'] ?? 0)
                : (float) ($item['quantity'] ?? 0);
            $cbmPerUnit = (float) ($item['cbm'] ?? 0);
            $weightPerUnit = (float) ($item['weight'] ?? 0);
            $totalQty = (float) ($item['quantity'] ?? 0);
            $unitPrice = isset($item['unit_price']) && $item['unit_price'] !== null && $item['unit_price'] !== ''
                ? (float) $item['unit_price']
                : null;

            $excelItems[] = [
                'item_no' => $item['item_no'] ?? '',
                'shipping_code' => $item['shipping_code'] ?? '',
                'description_en' => $englishParts ? implode(' | ', $englishParts) : implode(' | ', $cnParts),
                'description_cn' => $cnParts ? implode(' | ', $cnParts) : implode(' | ', $englishParts),
                'quantity' => $totalQty,
                'cartons' => (float) ($item['cartons'] ?? 0),
                'qty_per_carton' => (float) ($item['pieces_per_carton'] ?? 0),
                'declared_cbm' => $multiplier > 0 ? round($cbmPerUnit * $multiplier, 6) : 0.0,
                'declared_weight' => $multiplier > 0 ? round($weightPerUnit * $multiplier, 4) : 0.0,
                'unit_price' => $unitPrice,
                'sell_price' => $unitPrice,
                'supplier_name' => $section['supplier_name'] ?? '',
                'image_paths' => $item['photo_paths'] ?? [],
                'dimensions_scope' => $item['dimensions_scope'] ?? 'piece',
                'product_dimensions_scope' => $item['dimensions_scope'] ?? 'piece',
            ];
        }
    }

    require_once dirname(__DIR__, 2) . '/services/OrderExcelService.php';
    $filename = 'draft_order_' . $orderId . '.xlsx';
    (new OrderExcelService())->exportOrder($order, $excelItems, $filename);
}

function draftOrderDeleteExistingItems(PDO $pdo, int $orderId): void
{
    $stmt = $pdo->prepare("SELECT id FROM order_items WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $itemIds = array_map(static fn($id) => (int) $id, $stmt->fetchAll(PDO::FETCH_COLUMN));
    if ($itemIds) {
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $delDesign = $pdo->prepare("DELETE FROM design_attachments WHERE entity_type = 'order_item' AND entity_id IN ($placeholders)");
        $delDesign->execute($itemIds);
    }
    $pdo->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$orderId]);
}

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();
    $userId = getAuthUserId();

    if (!$userId) {
        jsonError('Unauthorized', 401);
    }
    if (!hasAnyRole(['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin'])) {
        jsonError('Forbidden', 403);
    }

    if ($method === 'POST' && $id === 'legacy' && $action && preg_match('/^(\d+)\/migrate$/', $action, $matches)) {
        $legacyId = (int) $matches[1];
        $stmt = $pdo->prepare("SELECT * FROM procurement_drafts WHERE id = ?");
        $stmt->execute([$legacyId]);
        $legacy = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$legacy) {
            jsonError('Legacy procurement draft not found', 404);
        }
        if (!empty($legacy['converted_order_id'])) {
            jsonResponse(['data' => ['already_migrated' => true, 'order' => draftOrderFetchOrderPayload($pdo, (int) $legacy['converted_order_id'])]]);
        }

        $customerId = (int) ($input['customer_id'] ?? 0);
        $expectedDate = draftOrderNormalizeExpectedReadyDate($input['expected_ready_date'] ?? null);
        $currency = strtoupper(trim((string) ($input['currency'] ?? 'USD'))) ?: 'USD';
        if ($customerId <= 0) {
            jsonError('customer_id is required for legacy migration.', 400);
        }
        if (!in_array($currency, ['USD', 'RMB'], true)) {
            $currency = 'USD';
        }
        $destinationCountryId = null;
        if (draftOrderTableHasColumn($pdo, 'orders', 'destination_country_id')) {
            if (array_key_exists('destination_country_id', $input)) {
                $destinationCountryId = draftOrderResolveDestinationCountryId($pdo, $customerId, $input);
            } else {
                $customerCountries = OrderCountryService::fetchCustomerCountries($pdo, $customerId);
                $allowedIds = array_values(array_unique(array_filter(array_map(
                    static fn(array $row): int => (int) ($row['country_id'] ?? 0),
                    $customerCountries
                ))));
                if (count($allowedIds) === 1) {
                    $destinationCountryId = (int) $allowedIds[0];
                }
            }
        }

        $itemsStmt = $pdo->prepare(
            "SELECT pdi.*, p.description_cn, p.description_en, p.cbm, p.weight, p.unit_price, p.hs_code
             FROM procurement_draft_items pdi
             LEFT JOIN products p ON pdi.product_id = p.id
             WHERE pdi.draft_id = ?
             ORDER BY pdi.sort_order, pdi.id"
        );
        $itemsStmt->execute([$legacyId]);
        $legacyItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$legacyItems) {
            jsonError('Legacy procurement draft has no items to migrate.', 400);
        }

        $supplierId = !empty($legacy['supplier_id']) ? (int) $legacy['supplier_id'] : null;
        $pdo->beginTransaction();
        try {
            if (draftOrderTableHasColumn($pdo, 'orders', 'destination_country_id')) {
                $pdo->prepare(
                    "INSERT INTO orders (customer_id, supplier_id, expected_ready_date, currency, status, order_type, created_by, destination_country_id)
                     VALUES (?, ?, ?, ?, 'Draft', 'draft_procurement', ?, ?)"
                )->execute([$customerId, $supplierId, $expectedDate, $currency, $userId, $destinationCountryId]);
            } else {
                $pdo->prepare(
                    "INSERT INTO orders (customer_id, supplier_id, expected_ready_date, currency, status, order_type, created_by)
                     VALUES (?, ?, ?, ?, 'Draft', 'draft_procurement', ?)"
                )->execute([$customerId, $supplierId, $expectedDate, $currency, $userId]);
            }
            $orderId = (int) $pdo->lastInsertId();

            $normalizedItems = [];
            foreach ($legacyItems as $legacyItem) {
                $qty = (float) ($legacyItem['quantity'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                $description = trim((string) ($legacyItem['description_cn'] ?? $legacyItem['description_en'] ?? $legacyItem['notes'] ?? ''));
                $normalizedItems[] = [
                    'product_id' => !empty($legacyItem['product_id']) ? (int) $legacyItem['product_id'] : null,
                    'supplier_id' => $supplierId,
                    'item_no' => null,
                    'shipping_code' => null,
                    'cartons' => 1,
                    'pieces_per_carton' => $qty,
                    'quantity' => $qty,
                    'unit' => 'pieces',
                    'unit_price' => isset($legacyItem['unit_price']) ? (float) $legacyItem['unit_price'] : null,
                    'total_amount' => isset($legacyItem['unit_price']) ? round((float) $legacyItem['unit_price'] * $qty, 4) : null,
                    'cbm_mode' => 'direct',
                    'dimensions_scope' => 'piece',
                    'cbm_per_unit' => isset($legacyItem['cbm']) ? (float) $legacyItem['cbm'] : 0.0,
                    'weight_per_unit' => isset($legacyItem['weight']) ? (float) $legacyItem['weight'] : 0.0,
                    'declared_cbm' => round(((float) ($legacyItem['cbm'] ?? 0)) * $qty, 6),
                    'declared_weight' => round(((float) ($legacyItem['weight'] ?? 0)) * $qty, 4),
                    'item_length' => null,
                    'item_width' => null,
                    'item_height' => null,
                    'hs_code' => draftOrderNormalizeHsCode($legacyItem['hs_code'] ?? null),
                    'description_entries' => draftOrderBuildDescriptionStrings($pdo, [[
                        'description_text' => $description,
                        'description_translated' => $description,
                    ]])['entries'],
                    'description_cn' => $description ?: null,
                    'description_en' => $description ?: null,
                    'photo_paths' => [],
                    'custom_design_required' => 0,
                    'custom_design_note' => null,
                    'custom_design_paths' => [],
                ];
            }
            if (!$normalizedItems) {
                jsonError('Legacy procurement draft has no valid items to migrate.', 400);
            }

            $normalizedItems = draftOrderAssignCanonicalItemNumbers($pdo, $customerId, $normalizedItems, $supplierId, $destinationCountryId);
            draftOrderInsertItems($pdo, $orderId, $supplierId, $normalizedItems, $userId);
            $previousStatus = $legacy['status'] ?? 'draft';
            $pdo->prepare("UPDATE procurement_drafts SET status = 'converted', converted_order_id = ? WHERE id = ?")->execute([$orderId, $legacyId]);
            $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('order', ?, 'create', ?, ?)")
                ->execute([$orderId, json_encode([
                    'status' => 'Draft',
                    'order_type' => 'draft_procurement',
                    'migration_source' => [
                        'entity_type' => 'procurement_draft',
                        'entity_id' => $legacyId,
                        'legacy_name' => $legacy['name'] ?? null,
                        'legacy_status' => $previousStatus,
                    ],
                ], JSON_UNESCAPED_UNICODE), $userId]);
            $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('procurement_draft', ?, 'migrate', ?, ?)")
                ->execute([$legacyId, json_encode([
                    'order_id' => $orderId,
                    'legacy_name' => $legacy['name'] ?? null,
                    'legacy_status' => $previousStatus,
                ], JSON_UNESCAPED_UNICODE), $userId]);
            (new NotificationService($pdo))->notifyOrderCreated($orderId, $userId);
            $pdo->commit();
            jsonResponse(['data' => ['already_migrated' => false, 'order' => draftOrderFetchOrderPayload($pdo, $orderId)]], 201);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    if ($method === 'GET' && $id && ctype_digit((string) $id) && $action === 'export') {
        $format = strtolower(trim((string) ($_GET['format'] ?? 'csv')));
        if ($format === 'xlsx') {
            draftOrderExportXlsx($pdo, (int) $id);
        }
        draftOrderExportCsv($pdo, (int) $id);
    }

    if ($method === 'GET') {
        if ($id === null) {
            jsonResponse(['data' => draftOrderListRows($pdo)]);
        }
        if (!ctype_digit((string) $id)) {
            jsonError('Draft order not found', 404);
        }
        jsonResponse(['data' => draftOrderFetchOrderPayload($pdo, (int) $id)]);
    }

    if ($method === 'POST') {
        if ($id !== null) {
            jsonError('Invalid action', 400);
        }
        $customerId = (int) ($input['customer_id'] ?? 0);
        $expectedDate = draftOrderNormalizeExpectedReadyDate($input['expected_ready_date'] ?? null);
        $currency = strtoupper(trim((string) ($input['currency'] ?? 'USD'))) ?: 'USD';
        $highAlertNotes = trim((string) ($input['high_alert_notes'] ?? '')) ?: null;
        if ($customerId <= 0) {
            jsonError('customer_id is required.', 400);
        }
        if (!in_array($currency, ['USD', 'RMB'], true)) {
            jsonError('Currency must be USD or RMB', 400);
        }
        $destinationCountryId = draftOrderResolveDestinationCountryId($pdo, $customerId, $input);

        $items = draftOrderFlattenSections($pdo, $input['supplier_sections'] ?? []);
        $supplierIds = array_values(array_unique(array_filter(array_map(
            static fn($item) => (int) ($item['supplier_id'] ?? 0),
            $items
        ))));
        $defaultSupplierId = count($supplierIds) === 1 ? $supplierIds[0] : null;
        $items = draftOrderAssignCanonicalItemNumbers($pdo, $customerId, $items, $defaultSupplierId, $destinationCountryId);
        $dupWarn = draftOrderEnforceDuplicateShippingCodePolicy($pdo, $customerId, 0, $items);

        $pdo->beginTransaction();
        try {
            if (draftOrderTableHasColumn($pdo, 'orders', 'destination_country_id')) {
                $pdo->prepare(
                    "INSERT INTO orders (customer_id, supplier_id, expected_ready_date, currency, status, order_type, high_alert_notes, created_by, destination_country_id)
                     VALUES (?, ?, ?, ?, 'Draft', 'draft_procurement', ?, ?, ?)"
                )->execute([$customerId, $defaultSupplierId, $expectedDate, $currency, $highAlertNotes, $userId, $destinationCountryId]);
            } else {
                $pdo->prepare(
                    "INSERT INTO orders (customer_id, supplier_id, expected_ready_date, currency, status, order_type, high_alert_notes, created_by)
                     VALUES (?, ?, ?, ?, 'Draft', 'draft_procurement', ?, ?)"
                )->execute([$customerId, $defaultSupplierId, $expectedDate, $currency, $highAlertNotes, $userId]);
            }
            $orderId = (int) $pdo->lastInsertId();
            draftOrderInsertItems($pdo, $orderId, $defaultSupplierId, $items, $userId);
            $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('order', ?, 'create', ?, ?)")
                ->execute([$orderId, json_encode(['status' => 'Draft', 'order_type' => 'draft_procurement'], JSON_UNESCAPED_UNICODE), $userId]);
            (new NotificationService($pdo))->notifyOrderCreated($orderId, $userId);
            $pdo->commit();
            jsonResponse(array_filter(['data' => draftOrderFetchOrderPayload($pdo, $orderId), 'warning' => $dupWarn]), 201);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    if ($method === 'PUT') {
        if (!ctype_digit((string) $id)) {
            jsonError('ID required', 400);
        }
        $orderId = (int) $id;
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND order_type = 'draft_procurement'");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            jsonError('Draft order not found', 404);
        }
        if (($order['status'] ?? '') !== 'Draft') {
            jsonError('Only draft-status draft orders can be edited in the builder.', 400);
        }

        $customerId = (int) ($input['customer_id'] ?? $order['customer_id']);
        $expectedDate = draftOrderResolveExpectedReadyDate($input, $order);
        $currency = strtoupper(trim((string) ($input['currency'] ?? $order['currency'] ?? 'USD'))) ?: 'USD';
        $highAlertNotes = array_key_exists('high_alert_notes', $input)
            ? (trim((string) ($input['high_alert_notes'] ?? '')) ?: null)
            : ($order['high_alert_notes'] ?? null);
        if ($customerId <= 0) {
            jsonError('customer_id is required.', 400);
        }
        if (!in_array($currency, ['USD', 'RMB'], true)) {
            jsonError('Currency must be USD or RMB', 400);
        }
        $destinationCountryId = draftOrderResolveDestinationCountryId($pdo, $customerId, $input, $order);

        $items = draftOrderFlattenSections($pdo, $input['supplier_sections'] ?? []);
        $supplierIds = array_values(array_unique(array_filter(array_map(
            static fn($item) => (int) ($item['supplier_id'] ?? 0),
            $items
        ))));
        $defaultSupplierId = count($supplierIds) === 1 ? $supplierIds[0] : null;
        $items = draftOrderAssignCanonicalItemNumbers($pdo, $customerId, $items, $defaultSupplierId, $destinationCountryId);
        $dupWarn = draftOrderEnforceDuplicateShippingCodePolicy($pdo, $customerId, $orderId, $items);

        $pdo->beginTransaction();
        try {
            if (draftOrderTableHasColumn($pdo, 'orders', 'destination_country_id')) {
                $pdo->prepare("UPDATE orders SET customer_id = ?, supplier_id = ?, expected_ready_date = ?, currency = ?, high_alert_notes = ?, destination_country_id = ? WHERE id = ?")
                    ->execute([$customerId, $defaultSupplierId, $expectedDate, $currency, $highAlertNotes, $destinationCountryId, $orderId]);
            } else {
                $pdo->prepare("UPDATE orders SET customer_id = ?, supplier_id = ?, expected_ready_date = ?, currency = ?, high_alert_notes = ? WHERE id = ?")
                    ->execute([$customerId, $defaultSupplierId, $expectedDate, $currency, $highAlertNotes, $orderId]);
            }
            draftOrderDeleteExistingItems($pdo, $orderId);
            draftOrderInsertItems($pdo, $orderId, $defaultSupplierId, $items, $userId);
            $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('order', ?, 'update', ?, ?)")
                ->execute([$orderId, json_encode(['order_type' => 'draft_procurement'], JSON_UNESCAPED_UNICODE), $userId]);
            $pdo->commit();
            jsonResponse(array_filter(['data' => draftOrderFetchOrderPayload($pdo, $orderId), 'warning' => $dupWarn]));
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    jsonError('Method not allowed', 405);
};
