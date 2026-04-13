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

use PhpOffice\PhpSpreadsheet\Cell\Coordinate as SpreadsheetCoordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

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

function draftOrderSupportsSharedCartons(PDO $pdo): bool
{
    return draftOrderTableHasColumn($pdo, 'order_items', 'shared_carton_enabled')
        && draftOrderTableHasColumn($pdo, 'order_items', 'shared_carton_code')
        && draftOrderTableHasColumn($pdo, 'order_items', 'shared_carton_contents');
}

function draftOrderBuildSharedCartonSummaryDescriptions(array $contents): array
{
    $cnParts = [];
    $enParts = [];

    foreach ($contents as $content) {
        $cn = trim((string) ($content['description_cn'] ?? ''));
        $en = trim((string) ($content['description_en'] ?? ''));
        if ($cn !== '') {
            $cnParts[] = $cn;
        }
        if ($en !== '') {
            $enParts[] = $en;
        }
    }

    return [
        'description_cn' => $cnParts ? implode(' | ', $cnParts) : null,
        'description_en' => $enParts ? implode(' | ', $enParts) : null,
    ];
}

function draftOrderNormalizeSharedCartonContents(PDO $pdo, array $rawContents, int $sectionSupplierId, int $cartons): array
{
    $normalized = [];

    foreach ($rawContents as $rawContent) {
        $product = null;
        if (!empty($rawContent['product_id'])) {
            $product = draftOrderFetchProduct($pdo, (int) $rawContent['product_id']);
            if (!$product) {
                jsonError('Selected contained product not found.', 404);
            }
        }

        $supplierId = (int) ($rawContent['supplier_id'] ?? ($product['supplier_id'] ?? $sectionSupplierId));
        if ($supplierId <= 0) {
            jsonError('Each contained shared-carton item needs a supplier.', 400);
        }
        if ($product && !empty($product['supplier_id']) && (int) $product['supplier_id'] !== $supplierId) {
            jsonError('Selected contained product belongs to another supplier.', 400);
        }

        $description = draftOrderBuildDescriptionStrings($pdo, $rawContent['description_entries'] ?? []);
        if (!$description['entries'] && $product) {
            $description = draftOrderBuildDescriptionStrings($pdo, $product['description_entries'] ?? []);
        }
        if (!$description['entries']) {
            $description = draftOrderBuildDescriptionStrings($pdo, [[
                'description_text' => $rawContent['description_cn'] ?? $rawContent['description'] ?? '',
                'description_translated' => $rawContent['description_en'] ?? $rawContent['description'] ?? '',
            ]]);
        }
        if (!$description['entries']) {
            continue;
        }

        $quantityPerCarton = round((float) ($rawContent['quantity_per_carton'] ?? $rawContent['quantity'] ?? 0), 4);
        if ($quantityPerCarton <= 0) {
            jsonError('Each contained shared-carton item needs quantity inside the carton.', 400);
        }

        $unitPrice = isset($rawContent['unit_price']) && $rawContent['unit_price'] !== ''
            ? round((float) $rawContent['unit_price'], 4)
            : null;
        $sellPrice = isset($rawContent['sell_price']) && $rawContent['sell_price'] !== ''
            ? round((float) $rawContent['sell_price'], 4)
            : null;
        $totalQuantity = round($quantityPerCarton * $cartons, 4);
        $priceForTotal = $sellPrice ?? $unitPrice;

        $normalized[] = [
            'product_id' => !empty($rawContent['product_id']) ? (int) $rawContent['product_id'] : null,
            'supplier_id' => $supplierId,
            'item_no' => trim((string) ($rawContent['item_no'] ?? '')) ?: null,
            'item_no_manual' => !empty($rawContent['item_no_manual']) ? 1 : 0,
            'shipping_code' => trim((string) ($rawContent['shipping_code'] ?? '')) ?: null,
            'quantity_per_carton' => $quantityPerCarton,
            'quantity' => $totalQuantity,
            'unit_price' => $unitPrice,
            'sell_price' => $sellPrice,
            'total_amount' => $priceForTotal !== null ? round($priceForTotal * $totalQuantity, 4) : null,
            'hs_code' => draftOrderNormalizeHsCode($rawContent['hs_code'] ?? ($product['hs_code'] ?? null)),
            'description_entries' => $description['entries'],
            'description_cn' => $description['description_cn'],
            'description_en' => $description['description_en'],
            'notes' => trim((string) ($rawContent['notes'] ?? '')) ?: null,
        ];
    }

    if (!$normalized) {
        jsonError('Shared cartons need at least one contained item.', 400);
    }

    return $normalized;
}

function draftOrderSummarizeSharedCartonContents(array $contents, int $cartons): array
{
    $qtyPerCarton = 0.0;
    $quantity = 0.0;
    $buyTotal = 0.0;
    $sellTotal = 0.0;
    $hasBuy = false;
    $hasSell = false;

    foreach ($contents as $content) {
        $lineQtyPerCarton = (float) ($content['quantity_per_carton'] ?? 0);
        $lineQty = round($lineQtyPerCarton * $cartons, 4);
        $qtyPerCarton += $lineQtyPerCarton;
        $quantity += $lineQty;

        if ($content['unit_price'] !== null && $content['unit_price'] !== '') {
            $hasBuy = true;
            $buyTotal += $lineQty * (float) $content['unit_price'];
        }
        $lineSell = $content['sell_price'] ?? $content['unit_price'];
        if ($lineSell !== null && $lineSell !== '') {
            $hasSell = true;
            $sellTotal += $lineQty * (float) $lineSell;
        }
    }

    $priceForTotal = $hasSell ? $sellTotal : ($hasBuy ? $buyTotal : null);

    return [
        'pieces_per_carton' => round($qtyPerCarton, 4),
        'quantity' => round($quantity, 4),
        'unit_price' => ($hasBuy && $quantity > 0) ? round($buyTotal / $quantity, 4) : null,
        'sell_price' => (($hasSell || $hasBuy) && $quantity > 0)
            ? round(($hasSell ? $sellTotal : $buyTotal) / $quantity, 4)
            : null,
        'total_amount' => $priceForTotal !== null ? round($priceForTotal, 4) : null,
    ];
}

function draftOrderDecodeSharedCartonContents(PDO $pdo, array $item): array
{
    $raw = $item['shared_carton_contents'] ?? null;
    if (!$raw) {
        return [];
    }

    $decoded = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
    if (!$decoded) {
        return [];
    }

    $supplierIds = array_values(array_unique(array_filter(array_map(
        static fn(array $row): int => (int) ($row['supplier_id'] ?? 0),
        $decoded
    ))));
    $supplierNames = [];
    if ($supplierIds) {
        $placeholders = implode(',', array_fill(0, count($supplierIds), '?'));
        $stmt = $pdo->prepare("SELECT id, name FROM suppliers WHERE id IN ($placeholders)");
        $stmt->execute($supplierIds);
        $supplierNames = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    $cartons = (int) ($item['cartons'] ?? 0);
    foreach ($decoded as &$content) {
        $content['supplier_id'] = !empty($content['supplier_id']) ? (int) $content['supplier_id'] : null;
        $content['supplier_name'] = $content['supplier_id'] ? ($supplierNames[$content['supplier_id']] ?? null) : null;
        $content['quantity_per_carton'] = round((float) ($content['quantity_per_carton'] ?? $content['quantity'] ?? 0), 4);
        $content['quantity'] = round($content['quantity_per_carton'] * $cartons, 4);
        $content['unit_price'] = isset($content['unit_price']) && $content['unit_price'] !== '' ? round((float) $content['unit_price'], 4) : null;
        $content['sell_price'] = isset($content['sell_price']) && $content['sell_price'] !== '' ? round((float) $content['sell_price'], 4) : null;
        $linePrice = $content['sell_price'] ?? $content['unit_price'];
        $content['total_amount'] = $linePrice !== null ? round($content['quantity'] * $linePrice, 4) : null;
        $content['hs_code'] = draftOrderNormalizeHsCode($content['hs_code'] ?? null);
        $content['description_entries'] = draftOrderSplitDescriptionEntries(
            $content['description_cn'] ?? null,
            $content['description_en'] ?? null
        );
    }
    unset($content);

    return $decoded;
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
    if (draftOrderTableHasColumn($pdo, 'products', 'buy_price')) {
        $columns[] = 'buy_price';
        $values[] = $item['unit_price'];
    }
    if (draftOrderTableHasColumn($pdo, 'products', 'sell_price')) {
        $columns[] = 'sell_price';
        $values[] = $item['sell_price'];
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
    if (draftOrderTableHasColumn($pdo, 'products', 'buy_price')
        && $assignIfEmpty($product['buy_price'] ?? null)
        && $item['unit_price'] !== null) {
        $sets[] = 'buy_price = ?';
        $values[] = $item['unit_price'];
    }
    if (draftOrderTableHasColumn($pdo, 'products', 'sell_price')
        && $assignIfEmpty($product['sell_price'] ?? null)
        && $item['sell_price'] !== null) {
        $sets[] = 'sell_price = ?';
        $values[] = $item['sell_price'];
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
    $sellPrice = isset($rawItem['sell_price']) && $rawItem['sell_price'] !== '' ? round((float) $rawItem['sell_price'], 4) : null;
    $priceForTotal = $sellPrice ?? $unitPrice;
    $totalAmount = $priceForTotal !== null ? round($priceForTotal * $quantity, 4) : null;
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

    $sharedCartonEnabled = !empty($rawItem['shared_carton_enabled']) ? 1 : 0;
    $sharedCartonCode = trim((string) ($rawItem['shared_carton_code'] ?? '')) ?: null;
    $sharedCartonContents = [];

    if ($sharedCartonEnabled) {
        if (!draftOrderSupportsSharedCartons($pdo)) {
            jsonError('Shared-carton support is not ready in this database yet. Run the latest migrations first.', 409);
        }
        $sharedCartonContents = draftOrderNormalizeSharedCartonContents(
            $pdo,
            $rawItem['shared_carton_contents'] ?? [],
            $supplierId,
            $cartons
        );
        $summary = draftOrderSummarizeSharedCartonContents($sharedCartonContents, $cartons);
        $summaryDescriptions = draftOrderBuildSharedCartonSummaryDescriptions($sharedCartonContents);
        $description = draftOrderBuildDescriptionStrings($pdo, $rawItem['description_entries'] ?? []);
        if (!$description['entries']) {
            $description = [
                'entries' => [],
                'description_cn' => $summaryDescriptions['description_cn'],
                'description_en' => $summaryDescriptions['description_en'],
            ];
        }
        $quantity = $summary['quantity'];
        $piecesPerCarton = $summary['pieces_per_carton'];
        $unitPrice = $summary['unit_price'];
        $sellPrice = $summary['sell_price'];
        $totalAmount = $summary['total_amount'];
        $declaredCbm = round($cbmPerUnit * ($dimensionsScope === 'carton' ? $cartons : $quantity), 6);
        $declaredWeight = round($weightPerUnit * ($dimensionsScope === 'carton' ? $cartons : $quantity), 4);
    } else {
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
    }

    return [
        'product_id' => $sharedCartonEnabled ? null : (!empty($rawItem['product_id']) ? (int) $rawItem['product_id'] : null),
        'supplier_id' => $supplierId,
        'item_no' => $sharedCartonEnabled ? null : (trim((string) ($rawItem['item_no'] ?? '')) ?: null),
        'item_no_manual' => $sharedCartonEnabled ? 0 : (!empty($rawItem['item_no_manual']) ? 1 : 0),
        'shipping_code' => trim((string) ($rawItem['shipping_code'] ?? '')) ?: null,
        'cartons' => $cartons,
        'pieces_per_carton' => round($piecesPerCarton, 4),
        'quantity' => $quantity,
        'unit' => 'pieces',
        'unit_price' => $unitPrice,
        'sell_price' => $sellPrice,
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
        'shared_carton_enabled' => $sharedCartonEnabled,
        'shared_carton_code' => $sharedCartonCode,
        'shared_carton_contents' => $sharedCartonContents,
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
    $numberingTargets = [];
    $numberingMap = [];

    foreach ($items as $itemIndex => $item) {
        $itemShippingCode = trim((string) ($item['shipping_code'] ?? '')) ?: $shippingCode;
        $items[$itemIndex]['shipping_code'] = $itemShippingCode;

        if (!empty($item['shared_carton_enabled']) && !empty($item['shared_carton_contents'])) {
            foreach ($item['shared_carton_contents'] as $contentIndex => $content) {
                $numberingTargets[] = [
                    'supplier_id' => $content['supplier_id'] ?? $item['supplier_id'] ?? $defaultSupplierId,
                    'item_no' => $content['item_no'] ?? null,
                    'item_no_manual' => !empty($content['item_no_manual']) ? 1 : 0,
                    'shipping_code' => trim((string) ($content['shipping_code'] ?? '')) ?: $itemShippingCode,
                ];
                $numberingMap[] = [$itemIndex, $contentIndex];
            }
            continue;
        }

        $numberingTargets[] = [
            'supplier_id' => $item['supplier_id'] ?? $defaultSupplierId,
            'item_no' => $item['item_no'] ?? null,
            'item_no_manual' => !empty($item['item_no_manual']) ? 1 : 0,
            'shipping_code' => $itemShippingCode,
        ];
        $numberingMap[] = [$itemIndex, null];
    }

    if (!$numberingTargets) {
        return $items;
    }

    $assigned = OrderItemNumberingService::assignItemNumbers($numberingTargets, $shippingCode, $defaultSupplierId);
    foreach ($assigned as $index => $numbered) {
        [$itemIndex, $contentIndex] = $numberingMap[$index];
        if ($contentIndex === null) {
            $items[$itemIndex]['item_no'] = $numbered['item_no'] ?? null;
            $items[$itemIndex]['shipping_code'] = $numbered['shipping_code'] ?? $items[$itemIndex]['shipping_code'];
            continue;
        }
        $items[$itemIndex]['shared_carton_contents'][$contentIndex]['item_no'] = $numbered['item_no'] ?? null;
        $items[$itemIndex]['shared_carton_contents'][$contentIndex]['shipping_code'] = $numbered['shipping_code'] ?? ($items[$itemIndex]['shipping_code'] ?? null);
    }

    return $items;
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
    $hasBuyPrice = draftOrderTableHasColumn($pdo, 'order_items', 'buy_price');
    $hasSellPrice = draftOrderTableHasColumn($pdo, 'order_items', 'sell_price');
    $hasOrderCartons = draftOrderTableHasColumn($pdo, 'order_items', 'order_cartons');
    $hasOrderQtyPerCarton = draftOrderTableHasColumn($pdo, 'order_items', 'order_qty_per_carton');
    $hasHsCode = draftOrderTableHasColumn($pdo, 'order_items', 'hs_code');
    $hasCustomDesignRequired = draftOrderTableHasColumn($pdo, 'order_items', 'custom_design_required');
    $hasCustomDesignNote = draftOrderTableHasColumn($pdo, 'order_items', 'custom_design_note');
    $hasSharedCartonEnabled = draftOrderTableHasColumn($pdo, 'order_items', 'shared_carton_enabled');
    $hasSharedCartonCode = draftOrderTableHasColumn($pdo, 'order_items', 'shared_carton_code');
    $hasSharedCartonContents = draftOrderTableHasColumn($pdo, 'order_items', 'shared_carton_contents');

    $columns = "order_id, product_id, item_no, shipping_code, cartons, qty_per_carton, quantity, unit, declared_cbm, declared_weight, item_length, item_width, item_height, unit_price, total_amount, notes, image_paths, description_cn, description_en";
    $placeholders = "?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?";
    if ($hasItemSupplier) {
        $columns .= ", supplier_id";
        $placeholders .= ",?";
    }
    if ($hasBuyPrice) {
        $columns .= ", buy_price";
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
    if ($hasSharedCartonEnabled) {
        $columns .= ", shared_carton_enabled";
        $placeholders .= ",?";
    }
    if ($hasSharedCartonCode) {
        $columns .= ", shared_carton_code";
        $placeholders .= ",?";
    }
    if ($hasSharedCartonContents) {
        $columns .= ", shared_carton_contents";
        $placeholders .= ",?";
    }

    $insert = $pdo->prepare("INSERT INTO order_items ($columns) VALUES ($placeholders)");
    foreach ($items as &$item) {
        if (empty($item['shared_carton_enabled']) && empty($item['product_id'])) {
            $item['product_id'] = draftOrderCreateProduct($pdo, $item);
        } elseif (empty($item['shared_carton_enabled'])) {
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
        if ($hasBuyPrice) {
            $params[] = $item['unit_price'];
        }
        if ($hasSellPrice) {
            $params[] = $item['sell_price'];
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
        if ($hasSharedCartonEnabled) {
            $params[] = !empty($item['shared_carton_enabled']) ? 1 : 0;
        }
        if ($hasSharedCartonCode) {
            $params[] = $item['shared_carton_code'] ?? null;
        }
        if ($hasSharedCartonContents) {
            $params[] = !empty($item['shared_carton_contents'])
                ? json_encode($item['shared_carton_contents'], JSON_UNESCAPED_UNICODE)
                : null;
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
    $hasSharedCartonEnabled = draftOrderTableHasColumn($pdo, 'order_items', 'shared_carton_enabled');
    $hasSharedCartonCode = draftOrderTableHasColumn($pdo, 'order_items', 'shared_carton_code');
    $hasSharedCartonContents = draftOrderTableHasColumn($pdo, 'order_items', 'shared_carton_contents');
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
        $item['shared_carton_enabled'] = $hasSharedCartonEnabled ? (!empty($item['shared_carton_enabled']) ? 1 : 0) : 0;
        $item['shared_carton_code'] = $hasSharedCartonCode ? (trim((string) ($item['shared_carton_code'] ?? '')) ?: null) : null;
        $item['shared_carton_contents'] = ($hasSharedCartonEnabled && $hasSharedCartonContents && !empty($item['shared_carton_enabled']))
            ? draftOrderDecodeSharedCartonContents($pdo, $item)
            : [];
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
            'product_id' => (!empty($item['shared_carton_enabled']) ? null : (!empty($item['product_id']) ? (int) $item['product_id'] : null)),
            'item_no' => !empty($item['shared_carton_enabled']) ? null : ($item['item_no'] ?: null),
            'shipping_code' => $item['shipping_code'] ?: null,
            'cartons' => (int) ($item['cartons'] ?? 0),
            'pieces_per_carton' => isset($item['qty_per_carton']) ? (float) $item['qty_per_carton'] : null,
            'quantity' => draftOrderGetQuantity($item),
            'unit_price' => $item['unit_price'] !== null ? (float) $item['unit_price'] : null,
            'sell_price' => isset($item['sell_price']) && $item['sell_price'] !== null ? (float) $item['sell_price'] : null,
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
            'shared_carton_enabled' => !empty($item['shared_carton_enabled']) ? 1 : 0,
            'shared_carton_code' => $item['shared_carton_code'] ?? null,
            'shared_carton_contents' => $item['shared_carton_contents'] ?? [],
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

function draftOrderCountDisplayItems(array $items): int
{
    $count = 0;
    foreach ($items as $item) {
        if (!empty($item['shared_carton_enabled']) && !empty($item['shared_carton_contents'])) {
            $count += count($item['shared_carton_contents']);
            continue;
        }
        $count++;
    }

    return $count;
}

function draftOrderCollectSupplierNames(array $sections): array
{
    $names = [];
    foreach ($sections as $section) {
        $sectionName = trim((string) ($section['supplier_name'] ?? ''));
        if ($sectionName !== '') {
            $names[] = $sectionName;
        }
        foreach (($section['items'] ?? []) as $item) {
            if (empty($item['shared_carton_enabled']) || empty($item['shared_carton_contents'])) {
                continue;
            }
            foreach ($item['shared_carton_contents'] as $content) {
                $contentSupplier = trim((string) ($content['supplier_name'] ?? ''));
                if ($contentSupplier !== '') {
                    $names[] = $contentSupplier;
                }
            }
        }
    }

    return array_values(array_unique($names));
}

function draftOrderBuildExportRows(array $sections): array
{
    $rows = [];

    foreach ($sections as $section) {
        foreach (($section['items'] ?? []) as $item) {
            if (!empty($item['shared_carton_enabled']) && !empty($item['shared_carton_contents'])) {
                $rows[] = [
                    'row_type' => 'shared_carton_summary',
                    'supplier_name' => $section['supplier_name'] ?? '',
                    'item_no' => $item['shared_carton_code'] ?: $item['shipping_code'] ?: '',
                    'description' => 'Shared carton / multiple items',
                    'hs_code' => '',
                    'pieces_per_carton' => $item['pieces_per_carton'] ?? '',
                    'cartons' => $item['cartons'] ?? '',
                    'quantity' => $item['quantity'] ?? '',
                    'unit_price' => null,
                    'sell_price' => null,
                    'total_amount' => null,
                    'cbm' => $item['cbm'] ?? '',
                    'total_cbm' => round((float) (($item['cbm'] ?? 0) * (($item['dimensions_scope'] ?? 'carton') === 'carton' ? (float) ($item['cartons'] ?? 0) : (float) ($item['quantity'] ?? 0))), 6),
                    'weight' => $item['weight'] ?? '',
                    'total_weight' => round((float) (($item['weight'] ?? 0) * (($item['dimensions_scope'] ?? 'carton') === 'carton' ? (float) ($item['cartons'] ?? 0) : (float) ($item['quantity'] ?? 0))), 4),
                    'custom_design_required' => !empty($item['custom_design_required']) ? 'Yes' : 'No',
                    'image_paths' => $item['photo_paths'] ?? [],
                    'carton_note' => $item['shared_carton_code'] ? ('Shared carton ' . $item['shared_carton_code']) : 'Shared carton',
                ];

                foreach ($item['shared_carton_contents'] as $content) {
                    $description = implode(' | ', array_map(
                        static fn($entry) => trim((string) (($entry['description_translated'] ?? '') ?: ($entry['description_text'] ?? ''))),
                        $content['description_entries'] ?? []
                    ));
                    $customerPrice = isset($content['sell_price']) && $content['sell_price'] !== null && $content['sell_price'] !== ''
                        ? (float) $content['sell_price']
                        : (isset($content['unit_price']) && $content['unit_price'] !== null ? (float) $content['unit_price'] : null);
                    $rows[] = [
                        'row_type' => 'shared_carton_content',
                        'supplier_name' => $content['supplier_name'] ?? ($section['supplier_name'] ?? ''),
                        'item_no' => $content['item_no'] ? ('↳ ' . $content['item_no']) : '↳',
                        'description' => trim(($item['shared_carton_code'] ? ('[' . $item['shared_carton_code'] . '] ') : '') . $description),
                        'hs_code' => $content['hs_code'] ?? '',
                        'pieces_per_carton' => $content['quantity_per_carton'] ?? '',
                        'cartons' => '',
                        'quantity' => $content['quantity'] ?? '',
                        'unit_price' => $content['unit_price'] ?? '',
                        'sell_price' => $customerPrice,
                        'total_amount' => $content['total_amount'] ?? '',
                        'cbm' => '',
                        'total_cbm' => '',
                        'weight' => '',
                        'total_weight' => '',
                        'custom_design_required' => '',
                        'image_paths' => [],
                        'carton_note' => $item['shared_carton_code'] ? ('Shared carton ' . $item['shared_carton_code']) : 'Shared carton',
                    ];
                }
                continue;
            }

            $desc = implode(' | ', array_map(
                static fn($entry) => trim((string) (($entry['description_translated'] ?? '') ?: ($entry['description_text'] ?? ''))),
                $item['description_entries'] ?? []
            ));
            $multiplier = ($item['dimensions_scope'] ?? 'carton') === 'carton'
                ? (float) ($item['cartons'] ?? 0)
                : (float) ($item['quantity'] ?? 0);
            $customerPrice = isset($item['sell_price']) && $item['sell_price'] !== null && $item['sell_price'] !== ''
                ? (float) $item['sell_price']
                : (isset($item['unit_price']) && $item['unit_price'] !== null ? (float) $item['unit_price'] : null);
            $rows[] = [
                'row_type' => 'item',
                'supplier_name' => $section['supplier_name'] ?? '',
                'item_no' => $item['item_no'] ?: '',
                'description' => $desc,
                'hs_code' => $item['hs_code'] ?: '',
                'pieces_per_carton' => $item['pieces_per_carton'] ?? '',
                'cartons' => $item['cartons'] ?? '',
                'quantity' => $item['quantity'] ?? '',
                'unit_price' => $item['unit_price'] ?? '',
                'sell_price' => $customerPrice,
                'total_amount' => $item['total_amount'] ?? '',
                'cbm' => $item['cbm'] ?? '',
                'total_cbm' => round((float) (($item['cbm'] ?? 0) * $multiplier), 6),
                'weight' => $item['weight'] ?? '',
                'total_weight' => round((float) (($item['weight'] ?? 0) * $multiplier), 4),
                'custom_design_required' => !empty($item['custom_design_required']) ? 'Yes' : 'No',
                'image_paths' => $item['photo_paths'] ?? [],
                'carton_note' => '',
            ];
        }
    }

    return $rows;
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
        $payload['supplier_names'] = draftOrderCollectSupplierNames($payload['supplier_sections']);
        $payload['item_count'] = array_reduce(
            $payload['supplier_sections'],
            static fn($sum, $section) => $sum + draftOrderCountDisplayItems($section['items'] ?? []),
            0
        );
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
        fputcsv($out, ['Item No', 'Product / Names', 'HS Code', 'Pieces/Carton', 'Cartons', 'Quantity', 'Factory Price', 'Customer Price', 'Total Amount', 'CBM/Unit', 'Total CBM', 'Weight/Unit', 'Total Weight', 'Custom Design']);
        foreach (draftOrderBuildExportRows([$section]) as $item) {
            fputcsv($out, [
                $item['item_no'] ?: '',
                $item['description'] ?? '',
                $item['hs_code'] ?: '',
                $item['pieces_per_carton'] ?? '',
                $item['cartons'] ?? '',
                $item['quantity'] ?? '',
                $item['unit_price'] ?? '',
                $item['sell_price'] ?? '',
                $item['total_amount'] ?? '',
                $item['cbm'] ?? '',
                $item['total_cbm'] ?? '',
                $item['weight'] ?? '',
                $item['total_weight'] ?? '',
                !empty($item['custom_design_required']) ? 'Yes' : 'No',
            ]);
        }
        fputcsv($out, ['', 'Supplier subtotal', '', '', '', '', '', '', $section['totals']['amount'], '', $section['totals']['cbm'], '', $section['totals']['weight'], '']);
        fputcsv($out, ['']);
    }

    fputcsv($out, ['', 'Grand total', '', '', '', '', '', '', $order['totals']['amount'], '', $order['totals']['cbm'], '', $order['totals']['weight'], '']);
    fclose($out);
    exit;
}

function draftOrderExportXlsx(PDO $pdo, int $orderId): void
{
    $order = draftOrderFetchOrderPayload($pdo, $orderId);
    $excelItems = [];

    foreach (draftOrderBuildExportRows($order['supplier_sections']) as $row) {
        $isSummary = ($row['row_type'] ?? '') === 'shared_carton_summary';
        $excelItems[] = [
            'item_no' => $row['item_no'] ?? '',
            'shipping_code' => '',
            'description_en' => $row['description'] ?? '',
            'description_cn' => $row['description'] ?? '',
            'quantity' => $isSummary ? '' : (float) ($row['quantity'] ?? 0),
            'cartons' => $isSummary ? (float) ($row['cartons'] ?? 0) : (is_numeric($row['cartons'] ?? null) ? (float) $row['cartons'] : ''),
            'qty_per_carton' => is_numeric($row['pieces_per_carton'] ?? null) ? (float) $row['pieces_per_carton'] : '',
            'declared_cbm' => is_numeric($row['total_cbm'] ?? null) ? (float) $row['total_cbm'] : 0.0,
            'declared_weight' => is_numeric($row['total_weight'] ?? null) ? (float) $row['total_weight'] : 0.0,
            'unit_price' => is_numeric($row['unit_price'] ?? null) ? (float) $row['unit_price'] : null,
            'sell_price' => is_numeric($row['sell_price'] ?? null) ? (float) $row['sell_price'] : null,
            'supplier_name' => $row['supplier_name'] ?? '',
            'image_paths' => $row['image_paths'] ?? [],
            'dimensions_scope' => $isSummary ? 'carton' : 'piece',
            'product_dimensions_scope' => $isSummary ? 'carton' : 'piece',
        ];
    }

    require_once dirname(__DIR__, 2) . '/services/OrderExcelService.php';
    $filename = 'draft_order_' . $orderId . '.xlsx';
    (new OrderExcelService())->exportOrder($order, $excelItems, $filename);
}

function draftOrderImportCellString($value): string
{
    if ($value === null) {
        return '';
    }
    $value = trim(str_replace("\xC2\xA0", ' ', (string) $value));
    return preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
}

function draftOrderImportRowIsBlank(array $row): bool
{
    foreach ($row as $value) {
        if (draftOrderImportCellString($value) !== '') {
            return false;
        }
    }
    return true;
}

function draftOrderImportHeaderKey($value): string
{
    $value = strtolower(draftOrderImportCellString($value));
    return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
}

function draftOrderImportColumnAliases(): array
{
    return [
        'photo_count' => ['photocount', 'photo'],
        'item_no' => ['itemno', 'itemnumber', 'line'],
        'supplier_name' => ['supplier', 'suppliername'],
        'description' => ['description', 'productnames', 'productname', 'productnamesdescription', 'productdescription', 'names'],
        'hs_code' => ['hscode', 'optionalhscode'],
        'pieces_per_carton' => ['piecescarton', 'piecespercarton', 'qtyctn', 'qtyperctn', 'qtycarton', 'quantitypercarton'],
        'cartons' => ['cartons', 'totalctns', 'totalcartons', 'ctns'],
        'quantity' => ['quantity', 'totalqty', 'qty', 'totalquantity'],
        'factory_price' => ['factoryprice', 'factory'],
        'customer_price' => ['customerprice', 'customer', 'sellprice'],
        'unit_price' => ['unitprice', 'price'],
        'total_amount' => ['totalamount', 'amounttotal'],
        'cbm' => ['cbm', 'cbmunit', 'cbmperunit'],
        'total_cbm' => ['totalcbm', 'cbmtotal', 'declaredcbm'],
        'weight' => ['weightunit', 'gwkg', 'gw', 'weightkg', 'unitweight'],
        'total_weight' => ['totalweight', 'totalgw', 'weighttotal', 'declaredweight'],
        'custom_design_required' => ['customdesign', 'design'],
    ];
}

function draftOrderImportHeaderMap(array $row): array
{
    $aliases = draftOrderImportColumnAliases();
    $map = [];

    foreach ($row as $index => $label) {
        $key = draftOrderImportHeaderKey($label);
        if ($key === '') {
            continue;
        }
        foreach ($aliases as $field => $candidates) {
            if (in_array($key, $candidates, true) && !array_key_exists($field, $map)) {
                $map[$field] = $index;
                break;
            }
        }
    }

    return $map;
}

function draftOrderImportLooksLikeHeader(array $row): bool
{
    $map = draftOrderImportHeaderMap($row);
    return isset($map['description'])
        && (
            isset($map['item_no'])
            || isset($map['cartons'])
            || isset($map['pieces_per_carton'])
            || isset($map['quantity'])
            || isset($map['supplier_name'])
        );
}

function draftOrderImportReadRowsFromUpload(): array
{
    if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
        jsonError('Choose an Excel or CSV file to import.', 400);
    }

    $file = $_FILES['file'];
    $name = (string) ($file['name'] ?? 'import');
    $tmpName = (string) ($file['tmp_name'] ?? '');
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK || $tmpName === '' || !is_file($tmpName)) {
        jsonError('Upload failed. Choose the Excel or CSV file again.', 400);
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = ['csv', 'cv', 'xlsx', 'xls'];
    if (!in_array($ext, $allowed, true)) {
        jsonError('Unsupported import file. Use XLSX, XLS, or CSV.', 415);
    }

    $config = require dirname(__DIR__, 2) . '/config/config.php';
    $maxSize = max((int) ($config['upload_max_size'] ?? 0), 25 * 1024 * 1024);
    if (!empty($file['size']) && (int) $file['size'] > $maxSize) {
        jsonError('Import file is too large. Use a smaller Excel or CSV file.', 413);
    }

    $rows = [];
    $maxRows = 3000;
    if (in_array($ext, ['csv', 'cv'], true)) {
        $handle = fopen($tmpName, 'r');
        if (!$handle) {
            jsonError('Could not read the CSV file.', 400);
        }
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = array_map('draftOrderImportCellString', $row);
            if (count($rows) > $maxRows) {
                fclose($handle);
                jsonError('Import file has too many rows. Keep it under ' . $maxRows . ' rows.', 400);
            }
        }
        fclose($handle);
    } else {
        try {
            $reader = IOFactory::createReaderForFile($tmpName);
            if (method_exists($reader, 'setReadDataOnly')) {
                $reader->setReadDataOnly(true);
            }
            $spreadsheet = $reader->load($tmpName);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = min($sheet->getHighestDataRow(), $maxRows);
            $highestColumn = $sheet->getHighestDataColumn();
            $highestColumnIndex = min(60, SpreadsheetCoordinate::columnIndexFromString($highestColumn));

            for ($rowNumber = 1; $rowNumber <= $highestRow; $rowNumber++) {
                $row = [];
                for ($col = 1; $col <= $highestColumnIndex; $col++) {
                    $row[] = draftOrderImportCellString(
                        $sheet->getCellByColumnAndRow($col, $rowNumber)->getFormattedValue()
                    );
                }
                $rows[] = $row;
            }
            $spreadsheet->disconnectWorksheets();
        } catch (Throwable $e) {
            jsonError('Could not read the Excel file. Export it again or save it as CSV and retry.', 400);
        }
    }

    $rows = array_values(array_filter($rows, static fn(array $row): bool => !draftOrderImportRowIsBlank($row)));
    if (!$rows) {
        jsonError('Import file has no readable rows.', 400);
    }

    return [$rows, $name];
}

function draftOrderImportNumeric($value): ?float
{
    $value = draftOrderImportCellString($value);
    if ($value === '' || $value === '-' || $value === '—') {
        return null;
    }
    $value = preg_replace('/[^\d.\-]+/', '', str_replace(',', '', $value)) ?? '';
    if ($value === '' || $value === '-' || $value === '.') {
        return null;
    }
    return is_numeric($value) ? (float) $value : null;
}

function draftOrderImportNumberForForm(?float $value, int $maxDecimals = 4): string
{
    if ($value === null) {
        return '';
    }
    return format_display_number($value, $maxDecimals);
}

function draftOrderImportField(array $row, array $map, string $field): string
{
    if (!array_key_exists($field, $map)) {
        return '';
    }
    return draftOrderImportCellString($row[$map[$field]] ?? '');
}

function draftOrderImportFieldNumber(array $row, array $map, string $field): ?float
{
    if (!array_key_exists($field, $map)) {
        return null;
    }
    return draftOrderImportNumeric($row[$map[$field]] ?? '');
}

function draftOrderImportIsTruthy($value): bool
{
    $value = strtolower(draftOrderImportCellString($value));
    return in_array($value, ['1', 'yes', 'y', 'true', 'required', 'custom', 'x'], true);
}

function draftOrderImportStripDisplaySuffix(string $value): string
{
    $value = trim($value);
    return trim(preg_replace('/\s*\([^()]*\)\s*$/', '', $value) ?? $value);
}

function draftOrderImportResolveSupplier(PDO $pdo, string $value): array
{
    static $cache = [];
    $value = trim($value);
    if ($value === '' || $value === '-' || $value === '—') {
        return ['id' => null, 'name' => ''];
    }

    $cacheKey = spl_object_id($pdo) . ':supplier:' . strtolower($value);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $columns = ['name', 'code'];
    if (draftOrderTableHasColumn($pdo, 'suppliers', 'store_id')) {
        $columns[] = 'store_id';
    }
    $conditions = implode(' OR ', array_map(static fn(string $col): string => "`$col` = ?", $columns));
    $stmt = $pdo->prepare("SELECT id, name FROM suppliers WHERE $conditions ORDER BY id LIMIT 1");
    $stmt->execute(array_fill(0, count($columns), $value));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $cache[$cacheKey] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
    }

    return $cache[$cacheKey] = ['id' => null, 'name' => $value];
}

function draftOrderImportResolveCustomer(PDO $pdo, string $value): array
{
    $value = trim($value);
    if ($value === '' || $value === '-' || $value === '—') {
        return ['id' => null, 'name' => '', 'default_shipping_code' => ''];
    }

    $candidates = array_values(array_unique(array_filter([
        $value,
        draftOrderImportStripDisplaySuffix($value),
    ], static fn(string $candidate): bool => trim($candidate) !== '')));

    $select = 'id, name';
    if (draftOrderTableHasColumn($pdo, 'customers', 'default_shipping_code')) {
        $select .= ', default_shipping_code';
    }

    foreach ($candidates as $candidate) {
        $columns = ['name'];
        if (draftOrderTableHasColumn($pdo, 'customers', 'code')) {
            $columns[] = 'code';
        }
        if (draftOrderTableHasColumn($pdo, 'customers', 'default_shipping_code')) {
            $columns[] = 'default_shipping_code';
        }
        $conditions = implode(' OR ', array_map(static fn(string $col): string => "`$col` = ?", $columns));
        $stmt = $pdo->prepare("SELECT $select FROM customers WHERE $conditions ORDER BY id LIMIT 1");
        $stmt->execute(array_fill(0, count($columns), $candidate));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'default_shipping_code' => (string) ($row['default_shipping_code'] ?? ''),
            ];
        }
    }

    return ['id' => null, 'name' => $value, 'default_shipping_code' => ''];
}

function draftOrderImportResolveCountry(PDO $pdo, string $value): array
{
    $value = trim($value);
    if ($value === '' || $value === '-' || $value === '—') {
        return ['id' => null, 'name' => '', 'code' => ''];
    }

    $name = draftOrderImportStripDisplaySuffix($value);
    $code = '';
    if (preg_match('/\(([^()]+)\)\s*$/', $value, $matches)) {
        $code = strtoupper(trim($matches[1]));
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT id, name, code
             FROM countries
             WHERE name = ? OR code = ? OR name = ? OR code = ?
             ORDER BY id
             LIMIT 1"
        );
        $stmt->execute([$value, strtoupper($value), $name, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'code' => (string) $row['code']];
        }
    } catch (Throwable $e) {
        return ['id' => null, 'name' => $value, 'code' => ''];
    }

    return ['id' => null, 'name' => $value, 'code' => $code];
}

function draftOrderImportNormalizeDate(string $value, array &$warnings): ?string
{
    $value = draftOrderImportCellString($value);
    if ($value === '' || $value === '-' || $value === '—') {
        return null;
    }
    $ts = strtotime($value);
    if ($ts === false) {
        $warnings[] = 'Expected Ready could not be parsed and was left empty.';
        return null;
    }
    return date('Y-m-d', $ts);
}

function draftOrderImportNormalizeCurrency(string $value): string
{
    $value = strtoupper(trim($value));
    return in_array($value, ['USD', 'RMB'], true) ? $value : 'RMB';
}

function draftOrderImportResolvePerUnit(?float $perUnit, ?float $total, ?float $cartons, ?float $quantity, ?string $preferredScope = null): array
{
    $scope = null;
    $cartons = $cartons !== null ? (float) $cartons : 0.0;
    $quantity = $quantity !== null ? (float) $quantity : 0.0;

    if ($perUnit !== null) {
        if ($total !== null && $total > 0 && $perUnit > 0) {
            $cartonTotal = $cartons > 0 ? $perUnit * $cartons : null;
            $pieceTotal = $quantity > 0 ? $perUnit * $quantity : null;
            if ($cartonTotal !== null && abs($cartonTotal - $total) < 0.0001) {
                $scope = 'carton';
            } elseif ($pieceTotal !== null && abs($pieceTotal - $total) < 0.0001) {
                $scope = 'piece';
            }
        }
        return ['value' => $perUnit, 'scope' => $scope];
    }

    if ($total !== null && $total > 0) {
        if ($preferredScope === 'piece' && $quantity > 0) {
            return ['value' => $total / $quantity, 'scope' => 'piece'];
        }
        if ($preferredScope === 'carton' && $cartons > 0) {
            return ['value' => $total / $cartons, 'scope' => 'carton'];
        }
        if ($cartons > 0) {
            return ['value' => $total / $cartons, 'scope' => 'carton'];
        }
        if ($quantity > 0) {
            return ['value' => $total / $quantity, 'scope' => 'piece'];
        }
    }

    return ['value' => null, 'scope' => null];
}

function draftOrderImportStripSharedContentPrefix(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/^(↳|->|=>|-->|-)\s*/u', '', $value) ?? $value;
    return trim($value);
}

function draftOrderImportDescriptionWithoutCartonPrefix(string $value): string
{
    $value = trim($value);
    return trim(preg_replace('/^\[[^\]]+\]\s*/u', '', $value) ?? $value);
}

function draftOrderImportLooksLikeSharedContent(string $itemNo): bool
{
    $itemNo = trim($itemNo);
    return $itemNo !== '' && preg_match('/^(↳|->|=>|-->)/u', $itemNo) === 1;
}

function draftOrderImportSupplierMarkerName(array $row): string
{
    $firstValue = draftOrderImportCellString($row[0] ?? '');
    $first = draftOrderImportHeaderKey($firstValue);
    $second = draftOrderImportCellString($row[1] ?? '');

    if ($first === 'supplier' && $second !== '') {
        return $second;
    }
    if (preg_match('/^supplier\s*:\s*(.+)$/i', $firstValue, $matches)) {
        return draftOrderImportCellString($matches[1]);
    }

    return '';
}

function draftOrderImportBuildItem(array $row, array $map): ?array
{
    $itemNo = draftOrderImportField($row, $map, 'item_no');
    $description = draftOrderImportField($row, $map, 'description');
    $hsCode = draftOrderImportField($row, $map, 'hs_code');
    $cartons = draftOrderImportFieldNumber($row, $map, 'cartons');
    $piecesPerCarton = draftOrderImportFieldNumber($row, $map, 'pieces_per_carton');
    $quantity = draftOrderImportFieldNumber($row, $map, 'quantity');
    $factoryPrice = draftOrderImportFieldNumber($row, $map, 'factory_price');
    $customerPrice = draftOrderImportFieldNumber($row, $map, 'customer_price');
    $plainUnitPrice = draftOrderImportFieldNumber($row, $map, 'unit_price');
    $unitPrice = $factoryPrice;
    $sellPrice = $customerPrice;
    if ($unitPrice === null && $sellPrice === null && $plainUnitPrice !== null) {
        $sellPrice = $plainUnitPrice;
    }
    $totalAmount = draftOrderImportFieldNumber($row, $map, 'total_amount');
    $rawCbm = draftOrderImportFieldNumber($row, $map, 'cbm');
    $totalCbm = draftOrderImportFieldNumber($row, $map, 'total_cbm');
    $rawWeight = draftOrderImportFieldNumber($row, $map, 'weight');
    $totalWeight = draftOrderImportFieldNumber($row, $map, 'total_weight');

    if ($itemNo === '' && $description === '' && $cartons === null && $quantity === null && $totalAmount === null && $totalCbm === null && $totalWeight === null) {
        return null;
    }

    $descriptionToken = draftOrderImportHeaderKey($description);
    if (in_array($descriptionToken, ['suppliersubtotal', 'grandtotal', 'sectionitemtotal', 'overalltotal'], true)) {
        return null;
    }

    if ($piecesPerCarton === null && $quantity !== null && $cartons !== null && $cartons > 0) {
        $piecesPerCarton = $quantity / $cartons;
    }
    if ($cartons === null && $quantity !== null && $piecesPerCarton !== null && $piecesPerCarton > 0) {
        $derivedCartons = $quantity / $piecesPerCarton;
        if (abs($derivedCartons - round($derivedCartons)) < 0.0001) {
            $cartons = (float) round($derivedCartons);
        }
    }
    if ($unitPrice === null && $sellPrice === null && $totalAmount !== null && $quantity !== null && $quantity > 0) {
        $unitPrice = $totalAmount / $quantity;
    }
    if ($unitPrice !== null && $sellPrice !== null && abs($unitPrice - $sellPrice) < 0.0001) {
        $sellPrice = null;
    }

    $cbm = draftOrderImportResolvePerUnit($rawCbm, $totalCbm, $cartons, $quantity);
    $scope = $cbm['scope'] ?: 'carton';
    $weight = draftOrderImportResolvePerUnit($rawWeight, $totalWeight, $cartons, $quantity, $scope);
    if (!$cbm['scope'] && $weight['scope']) {
        $scope = $weight['scope'];
    }

    $customRaw = draftOrderImportField($row, $map, 'custom_design_required');
    $descriptionEntry = draftOrderImportDescriptionWithoutCartonPrefix($description);

    return [
        'product_id' => null,
        'item_no' => '',
        'item_no_manual' => 0,
        'shipping_code' => null,
        'description_entries' => $descriptionEntry !== '' ? [[
            'description_text' => $descriptionEntry,
            'description_translated' => '',
        ]] : [],
        'pieces_per_carton' => draftOrderImportNumberForForm($piecesPerCarton, 4),
        'cartons' => $cartons !== null ? draftOrderImportNumberForForm($cartons, 0) : '',
        'quantity' => draftOrderImportNumberForForm($quantity, 4),
        'unit_price' => draftOrderImportNumberForForm($unitPrice, 4),
        'sell_price' => draftOrderImportNumberForForm($sellPrice, 4),
        'total_amount' => draftOrderImportNumberForForm($totalAmount, 4),
        'cbm_mode' => 'direct',
        'cbm' => draftOrderImportNumberForForm($cbm['value'], 6),
        'item_length' => '',
        'item_width' => '',
        'item_height' => '',
        'weight' => draftOrderImportNumberForForm($weight['value'], 4),
        'dimensions_scope' => $scope,
        'hs_code' => draftOrderNormalizeHsCode($hsCode) ?: '',
        'photo_paths' => [],
        'custom_design_required' => draftOrderImportIsTruthy($customRaw) ? 1 : 0,
        'custom_design_note' => null,
        'custom_design_paths' => [],
        'shared_carton_enabled' => 0,
        'shared_carton_code' => null,
        'shared_carton_contents' => [],
    ];
}

function draftOrderImportBuildSharedContent(PDO $pdo, array $row, array $map, array $fallbackSupplier): ?array
{
    $item = draftOrderImportBuildItem($row, $map);
    if (!$item) {
        return null;
    }
    $supplierName = draftOrderImportField($row, $map, 'supplier_name');
    $supplier = draftOrderImportResolveSupplier($pdo, $supplierName !== '' ? $supplierName : (string) ($fallbackSupplier['name'] ?? ''));

    return [
        'supplier_id' => $supplier['id'],
        'supplier_name' => $supplier['name'],
        'product_id' => null,
        'item_no' => '',
        'item_no_manual' => 0,
        'shipping_code' => null,
        'quantity_per_carton' => $item['pieces_per_carton'],
        'unit_price' => $item['unit_price'],
        'sell_price' => $item['sell_price'],
        'hs_code' => $item['hs_code'],
        'description_entries' => $item['description_entries'],
        'description_cn' => '',
        'description_en' => '',
        'notes' => null,
    ];
}

function draftOrderImportSectionKey(array $supplier, array $sections): string
{
    if (!empty($supplier['id'])) {
        return 'id:' . (int) $supplier['id'];
    }
    $name = trim((string) ($supplier['name'] ?? ''));
    if ($name !== '') {
        return 'name:' . md5(strtolower($name));
    }
    return 'blank';
}

function draftOrderImportAddItemToSections(array &$sections, array $supplier, array $item): void
{
    $key = draftOrderImportSectionKey($supplier, $sections);
    if (!isset($sections[$key])) {
        $sections[$key] = [
            'supplier_id' => !empty($supplier['id']) ? (int) $supplier['id'] : null,
            'supplier_name' => trim((string) ($supplier['name'] ?? '')),
            'items' => [],
            'totals' => ['amount' => 0.0, 'cbm' => 0.0, 'weight' => 0.0],
        ];
    }
    $sections[$key]['items'][] = $item;
}

function draftOrderImportFinalizeShared(array &$sections, ?array &$pendingShared): void
{
    if ($pendingShared === null) {
        return;
    }
    $supplier = $pendingShared['supplier'];
    $item = $pendingShared['item'];
    $item['shared_carton_contents'] = $pendingShared['contents'];
    draftOrderImportAddItemToSections($sections, $supplier, $item);
    $pendingShared = null;
}

function draftOrderImportBuildPayload(PDO $pdo, array $rows, string $filename): array
{
    $warnings = [];
    $meta = [
        'customer_name' => '',
        'destination_country_name' => '',
        'expected_ready_date' => null,
        'currency' => 'RMB',
    ];
    $sections = [];
    $currentSupplier = ['id' => null, 'name' => ''];
    $currentHeader = null;
    $pendingShared = null;
    $importedRows = 0;

    foreach ($rows as $row) {
        if (draftOrderImportLooksLikeHeader($row)) {
            draftOrderImportFinalizeShared($sections, $pendingShared);
            $currentHeader = draftOrderImportHeaderMap($row);
            continue;
        }

        $first = draftOrderImportHeaderKey($row[0] ?? '');
        $second = draftOrderImportCellString($row[1] ?? '');

        if ($first === 'customer' && $second !== '') {
            $meta['customer_name'] = $second;
            continue;
        }
        if (in_array($first, ['destinationcountry', 'destination'], true) && $second !== '') {
            $meta['destination_country_name'] = $second;
            continue;
        }
        if ($first === 'expectedready' && $second !== '') {
            $meta['expected_ready_date'] = draftOrderImportNormalizeDate($second, $warnings);
            continue;
        }
        if ($first === 'currency' && $second !== '') {
            $meta['currency'] = draftOrderImportNormalizeCurrency($second);
            continue;
        }
        $supplierMarkerName = draftOrderImportSupplierMarkerName($row);
        if ($supplierMarkerName !== '') {
            draftOrderImportFinalizeShared($sections, $pendingShared);
            $currentSupplier = draftOrderImportResolveSupplier($pdo, $supplierMarkerName);
            continue;
        }
        if (draftOrderImportCellString($row[0] ?? '') === '@@' || draftOrderImportHeaderKey($row[1] ?? '') === 'suppliernameandinfo') {
            draftOrderImportFinalizeShared($sections, $pendingShared);
            $currentSupplier = draftOrderImportResolveSupplier($pdo, draftOrderImportCellString($row[2] ?? ''));
            continue;
        }
        if (!$currentHeader) {
            continue;
        }

        $rowSupplierName = draftOrderImportField($row, $currentHeader, 'supplier_name');
        $rowSupplier = $rowSupplierName !== ''
            ? draftOrderImportResolveSupplier($pdo, $rowSupplierName)
            : $currentSupplier;
        $itemNo = draftOrderImportField($row, $currentHeader, 'item_no');
        $description = draftOrderImportField($row, $currentHeader, 'description');
        $descriptionToken = draftOrderImportHeaderKey($description);

        if ($descriptionToken === 'sharedcartonmultipleitems') {
            draftOrderImportFinalizeShared($sections, $pendingShared);
            $summary = draftOrderImportBuildItem($row, $currentHeader);
            if ($summary) {
                $summary['shared_carton_enabled'] = 1;
                $summary['shared_carton_code'] = null;
                $summary['item_no'] = null;
                $summary['item_no_manual'] = 0;
                $summary['description_entries'] = [];
                $summary['hs_code'] = '';
                $pendingShared = [
                    'supplier' => $rowSupplier,
                    'item' => $summary,
                    'contents' => [],
                ];
                $importedRows++;
            }
            continue;
        }

        if ($pendingShared !== null && draftOrderImportLooksLikeSharedContent($itemNo)) {
            $content = draftOrderImportBuildSharedContent($pdo, $row, $currentHeader, $pendingShared['supplier']);
            if ($content) {
                $pendingShared['contents'][] = $content;
                $importedRows++;
            }
            continue;
        }

        draftOrderImportFinalizeShared($sections, $pendingShared);
        $item = draftOrderImportBuildItem($row, $currentHeader);
        if (!$item) {
            continue;
        }
        draftOrderImportAddItemToSections($sections, $rowSupplier, $item);
        $importedRows++;
    }
    draftOrderImportFinalizeShared($sections, $pendingShared);

    if (!$importedRows || !$sections) {
        jsonError('No draft-order item rows matched the exported column names. Use headers like "Item No" and "Product / Names", or "ITEM NO" and "DESCRIPTION".', 400);
    }

    $customer = draftOrderImportResolveCustomer($pdo, $meta['customer_name']);
    $country = draftOrderImportResolveCountry($pdo, $meta['destination_country_name']);

    if ($customer['name'] !== '' && empty($customer['id'])) {
        $warnings[] = 'Customer "' . $customer['name'] . '" was not found. Select it before saving.';
    }
    foreach ($sections as $section) {
        if (!empty($section['supplier_name']) && empty($section['supplier_id'])) {
            $warnings[] = 'Supplier "' . $section['supplier_name'] . '" was not found. Select or quick-add it before saving.';
        }
        foreach ($section['items'] as $item) {
            foreach (($item['shared_carton_contents'] ?? []) as $content) {
                if (!empty($content['supplier_name']) && empty($content['supplier_id'])) {
                    $warnings[] = 'Contained supplier "' . $content['supplier_name'] . '" was not found. Select or quick-add it before saving.';
                }
            }
        }
    }

    return [
        'customer' => $customer,
        'destination_country' => $country,
        'expected_ready_date' => $meta['expected_ready_date'],
        'currency' => $meta['currency'],
        'high_alert_notes' => null,
        'supplier_sections' => array_values($sections),
        'meta' => [
            'source_file' => $filename,
            'rows_imported' => $importedRows,
            'warnings' => array_values(array_unique($warnings)),
        ],
    ];
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

    if ($method === 'POST' && $id === 'import' && $action === null) {
        [$rows, $filename] = draftOrderImportReadRowsFromUpload();
        jsonResponse(['data' => draftOrderImportBuildPayload($pdo, $rows, $filename)]);
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
                    'sell_price' => isset($legacyItem['unit_price']) ? (float) $legacyItem['unit_price'] : null,
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
