<?php
require_once dirname(__DIR__, 2) . '/services/LegacyProcurementMetricsService.php';

/**
 * Draft Orders API
 * Real orders saved with order_type = draft_procurement
 * Roles: ChinaAdmin, ChinaEmployee, SuperAdmin
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__,2).'/services/MasterDataImportService.php';
require_once dirname(__DIR__, 2) . '/services/NotificationService.php';
require_once dirname(__DIR__, 2) . '/services/OrderCountryService.php';
require_once dirname(__DIR__, 2) . '/services/OrderWriteService.php';
require_once dirname(__DIR__, 2) . '/services/OrderItemNumberingService.php';
require_once dirname(__DIR__, 2) . '/services/ItemNumberReservationService.php';
require_once dirname(__DIR__, 2) . '/services/OrderItemDescriptionService.php';
require_once dirname(__DIR__, 2) . '/services/ItemClassificationService.php';
require_once dirname(__DIR__, 2) . '/services/DraftOrderCostService.php';
require_once dirname(__DIR__, 2) . '/services/OrderExcelService.php';
require_once dirname(__DIR__, 2) . '/services/OrderBulkExcelService.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate as SpreadsheetCoordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing as SpreadsheetDrawing;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing as SpreadsheetMemoryDrawing;

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

function draftOrderNormalizeItemText($value, int $maxLength = 150): ?string
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

function draftOrderNormalizeItemNumberSource(array $input): string
{
    $source = strtolower(trim((string) ($input['item_no_source'] ?? '')));
    // Older clients may send a stale generated source alongside a manual flag.
    if (!empty($input['item_no_manual']) && $source !== 'imported') return 'manual';
    if (in_array($source, ['generated', 'manual', 'imported'], true)) return $source;
    return !empty($input['item_no_manual']) ? 'manual' : 'generated';
}

function draftOrderValidateItemNumber($value, string $source = 'generated'): ?string
{
    if ($value === null) return null;
    $value = (string) $value;
    if (trim($value) === '') return null;
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    if ($length > 150) {
        jsonError('Item number is too long.', 400, ['items.item_no' => 'Item number must be 150 characters or fewer.']);
    }
    if (preg_match('/[\x00-\x1F\x7F]/u', $value)) {
        jsonError('Item number contains unsupported control characters.', 400, ['items.item_no' => 'Remove line breaks or control characters from the item number.']);
    }
    return $value;
}

function draftOrderFirstFilledValue(array $input, array $keys)
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $input) && trim((string) ($input[$key] ?? '')) !== '') {
            return $input[$key];
        }
    }

    return null;
}

function draftOrderNormalizeOptionalDecimal($value, string $label): ?float
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

function draftOrderNormalizeUnit($value): string
{
    $unit = draftOrderNormalizeItemText($value, 20);
    return $unit ?: 'pieces';
}

function draftOrderCopyNormalGoodsDisplay($value): string
{
    $raw = trim((string) ($value ?? ''));
    return match (strtolower($raw)) {
        'copy' => clmsT('Copy Goods'),
        'dangerous' => clmsT('Dangerous Goods'),
        'normal' => clmsT('Normal Goods'),
        default => $raw,
    };
}

function draftOrderNormalizeCopyNormalGoods($value): ?string
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
    return draftOrderNormalizeItemText($raw, 60);
}

function draftOrderNormalizeExpectedReadyDate($value): ?string
{
    return OrderWriteService::date($value);
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

function draftOrderContainsChinese(string $text): bool
{
    return preg_match('/[\x{4e00}-\x{9fff}]/u', $text) === 1;
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
        return [
            'description_text' => $text,
            'description_translated' => $translated,
        ];
    }

    $source = $text !== '' ? $text : $translated;
    if (draftOrderContainsChinese($source)) {
        return [
            'description_text' => $source,
            'description_translated' => '',
        ];
    }

    return [
        'description_text' => '',
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
        $l = (float) ($item['item_length'] ?? $item['length'] ?? 0);
        $w = (float) ($item['item_width'] ?? $item['width'] ?? 0);
        $h = (float) ($item['item_height'] ?? $item['height'] ?? 0);
        return ($l > 0 && $w > 0 && $h > 0) ? (($l * $w * $h) / 1000000) : 0.0;
    }

    $direct = (float) ($item['cbm'] ?? 0);
    if ($direct > 0) {
        return $direct;
    }

    $l = (float) ($item['item_length'] ?? $item['length'] ?? 0);
    $w = (float) ($item['item_width'] ?? $item['width'] ?? 0);
    $h = (float) ($item['item_height'] ?? $item['height'] ?? 0);
    return ($l > 0 && $w > 0 && $h > 0) ? (($l * $w * $h) / 1000000) : 0.0;
}

function draftOrderPerUnitWeight(array $item): float
{
    return (float) ($item['weight'] ?? 0);
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
            jsonError('Each contained shared-carton item needs a supplier.', 400, ['shared_carton_contents.supplier_id' => 'Contained supplier is required.']);
        }
        if ($product && !empty($product['supplier_id']) && (int) $product['supplier_id'] !== $supplierId) {
            jsonError('Selected contained product belongs to another supplier.', 400, ['shared_carton_contents.supplier_id' => 'Selected contained product belongs to another supplier.']);
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

        OrderWriteService::validateRawNumbers($rawContent);
        $quantityPerCarton = round((float) ($rawContent['quantity_per_carton'] ?? $rawContent['quantity'] ?? 0), 4);
        if ($quantityPerCarton <= 0) {
            jsonError('Each contained shared-carton item needs quantity inside the carton.', 400, ['shared_carton_contents.quantity_per_carton' => 'Quantity inside the carton is required.']);
        }

        $unitPrice = isset($rawContent['unit_price']) && $rawContent['unit_price'] !== ''
            ? clmsFinancialDecimal($rawContent['unit_price'], 'Unit price', true)
            : null;
        $sellPrice = isset($rawContent['sell_price']) && $rawContent['sell_price'] !== ''
            ? clmsFinancialDecimal($rawContent['sell_price'], 'Sell price', true)
            : null;
        $totalQuantity = round($quantityPerCarton * $cartons, 4);
        $priceForTotal = $sellPrice ?? $unitPrice;
        $brand = draftOrderNormalizeItemText($rawContent['brand'] ?? null, 150);
        $whatBrand = draftOrderNormalizeItemText($rawContent['what_brand'] ?? $brand, 150);
        if ($brand === null && $whatBrand !== null) {
            $brand = $whatBrand;
        }

        $itemNumberSource = draftOrderNormalizeItemNumberSource($rawContent);
        $productImagePaths = clmsNormalizeImagePathList($product['image_paths'] ?? []);
        $explicitImagePaths = [];
        foreach (clmsMergeImagePathLists(
            $rawContent['image_paths'] ?? [],
            $rawContent['photo_paths'] ?? []
        ) as $candidate) {
            if (in_array($candidate, $productImagePaths, true)) {
                continue;
            }
            try {
                $meta = clmsResolveStoredUploadPathMeta($candidate, false);
                $explicitImagePaths[] = (string) $meta['normalized'];
            } catch (Throwable $e) {
                jsonError('A contained item photo reference is invalid.', 400, [
                    'shared_carton_contents.image_paths' => 'Select or upload the contained item photo again.',
                ]);
            }
        }
        $normalized[] = [
            'product_id' => !empty($rawContent['product_id']) ? (int) $rawContent['product_id'] : null,
            'supplier_id' => $supplierId,
            'item_no' => draftOrderValidateItemNumber($rawContent['item_no'] ?? null, $itemNumberSource),
            'item_number' => clmsPackingListItemNumber($rawContent['item_number'] ?? null),
            'item_number_present' => array_key_exists('item_number', $rawContent),
            'item_no_source' => $itemNumberSource,
            'item_no_manual' => $itemNumberSource !== 'generated' ? 1 : 0,
            'existing_item_id' => !empty($rawContent['existing_item_id']) ? (int) $rawContent['existing_item_id'] : null,
            'shipping_code' => trim((string) ($rawContent['shipping_code'] ?? '')) ?: null,
            'what_brand' => $whatBrand,
            'brand' => $brand,
            'materials' => draftOrderNormalizeItemText($rawContent['materials'] ?? $rawContent['material'] ?? null, 1000),
            'copy_normal_goods' => draftOrderNormalizeCopyNormalGoods($rawContent['copy_normal_goods'] ?? null),
            'code' => draftOrderNormalizeItemText($rawContent['code'] ?? null, 100),
            'express_number' => draftOrderNormalizeItemText($rawContent['express_number'] ?? null, 150),
            'size' => draftOrderNormalizeItemText($rawContent['size'] ?? null, 150),
            'length' => draftOrderNormalizeOptionalDecimal($rawContent['length'] ?? $rawContent['item_length'] ?? null, 'Length'),
            'width' => draftOrderNormalizeOptionalDecimal($rawContent['width'] ?? $rawContent['item_width'] ?? null, 'Width'),
            'height' => draftOrderNormalizeOptionalDecimal($rawContent['height'] ?? $rawContent['item_height'] ?? null, 'Height'),
            'quantity_per_carton' => $quantityPerCarton,
            'quantity' => $totalQuantity,
            'unit_price' => $unitPrice,
            'sell_price' => $sellPrice,
            'total_amount' => $priceForTotal !== null ? DecimalMath::multiply($priceForTotal, (string) $totalQuantity) : null,
            'hs_code' => draftOrderNormalizeHsCode($rawContent['hs_code'] ?? ($product['hs_code'] ?? null)),
            'description_entries' => $description['entries'],
            'description_cn' => $description['description_cn'],
            'description_en' => $description['description_en'],
            'image_paths' => clmsMergeImagePathLists($explicitImagePaths, $productImagePaths),
            'notes' => trim((string) ($rawContent['notes'] ?? '')) ?: null,
        ];
    }

    if (!$normalized) {
        jsonError('Shared cartons need at least one contained item.', 400, ['shared_carton_contents' => 'Shared cartons need contained items.']);
    }

    return $normalized;
}

function draftOrderSummarizeSharedCartonContents(array $contents, int $cartons): array
{
    $qtyPerCarton = 0.0;
    $quantity = 0.0;
    $buyTotal = '0.0000';
    $sellTotal = '0.0000';
    $hasBuy = false;
    $hasSell = false;

    foreach ($contents as $content) {
        $lineQtyPerCarton = (float) ($content['quantity_per_carton'] ?? 0);
        $lineQty = round($lineQtyPerCarton * $cartons, 4);
        $qtyPerCarton += $lineQtyPerCarton;
        $quantity += $lineQty;

        if ($content['unit_price'] !== null && $content['unit_price'] !== '') {
            $hasBuy = true;
            $buyTotal = DecimalMath::add($buyTotal, DecimalMath::multiply((string) $lineQty, $content['unit_price']));
        }
        $lineSell = $content['sell_price'] ?? $content['unit_price'];
        if ($lineSell !== null && $lineSell !== '') {
            $hasSell = true;
            $sellTotal = DecimalMath::add($sellTotal, DecimalMath::multiply((string) $lineQty, $lineSell));
        }
    }

    $priceForTotal = $hasSell ? $sellTotal : ($hasBuy ? $buyTotal : null);

    return [
        'pieces_per_carton' => round($qtyPerCarton, 4),
        'quantity' => round($quantity, 4),
        'unit_price' => ($hasBuy && $quantity > 0) ? DecimalMath::divide($buyTotal, (string) $quantity) : null,
        'sell_price' => (($hasSell || $hasBuy) && $quantity > 0)
            ? DecimalMath::divide($hasSell ? $sellTotal : $buyTotal, (string) $quantity)
            : null,
        'total_amount' => $priceForTotal,
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

    $decoded = clmsHydrateSharedCartonImagePaths($pdo, $decoded);
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
        $content['unit_price'] = isset($content['unit_price']) && $content['unit_price'] !== '' ? DecimalMath::normalize($content['unit_price']) : null;
        $content['sell_price'] = isset($content['sell_price']) && $content['sell_price'] !== '' ? DecimalMath::normalize($content['sell_price']) : null;
        $linePrice = $content['sell_price'] ?? $content['unit_price'];
        $content['total_amount'] = $linePrice !== null ? DecimalMath::multiply((string) $content['quantity'], $linePrice) : null;
        $content['hs_code'] = draftOrderNormalizeHsCode($content['hs_code'] ?? null);
        $content['brand'] = draftOrderNormalizeItemText($content['brand'] ?? $content['what_brand'] ?? null, 150);
        $content['what_brand'] = draftOrderNormalizeItemText($content['what_brand'] ?? $content['brand'] ?? null, 150);
        $content['materials'] = draftOrderNormalizeItemText($content['materials'] ?? null, 1000);
        $content['copy_normal_goods'] = draftOrderNormalizeCopyNormalGoods($content['copy_normal_goods'] ?? null);
        $content['code'] = draftOrderNormalizeItemText($content['code'] ?? null, 100);
        $content['express_number'] = draftOrderNormalizeItemText($content['express_number'] ?? null, 150);
        $content['size'] = draftOrderNormalizeItemText($content['size'] ?? null, 150);
        $content['length'] = draftOrderNormalizeOptionalDecimal($content['length'] ?? $content['item_length'] ?? null, 'Length');
        $content['width'] = draftOrderNormalizeOptionalDecimal($content['width'] ?? $content['item_width'] ?? null, 'Width');
        $content['height'] = draftOrderNormalizeOptionalDecimal($content['height'] ?? $content['item_height'] ?? null, 'Height');
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
        jsonError('Selected product belongs to another supplier. Create a separate supplier section instead.', 400, ['supplier_id' => 'Selected product belongs to another supplier. Create a separate supplier section instead.']);
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

    if ($assignIfEmpty($product['description_cn'] ?? null) && !empty($item['description_cn']) && mb_strlen($item['description_cn']) <= 500) {
        $sets[] = 'description_cn = ?';
        $values[] = $item['description_cn'];
    }
    if ($assignIfEmpty($product['description_en'] ?? null) && !empty($item['description_en']) && mb_strlen($item['description_en']) <= 500) {
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
    $productMultiplier = OrderWriteService::productMeasurementMultiplier($product, $item);
    $sameBasis = ($product['dimensions_scope'] ?? 'piece') === ($item['dimensions_scope'] ?? 'piece');
    if ($productMultiplier > 0 && $assignIfEmpty($product['cbm'] ?? null) && $item['cbm_per_unit'] > 0) {
        $sets[] = 'cbm = ?';
        $values[] = $item['declared_cbm'] / $productMultiplier;
    }
    if ($productMultiplier > 0 && $assignIfEmpty($product['weight'] ?? null) && $item['weight_per_unit'] > 0) {
        $sets[] = 'weight = ?';
        $values[] = $item['declared_weight'] / $productMultiplier;
    }
    foreach ([
        'length_cm' => 'item_length',
        'width_cm' => 'item_width',
        'height_cm' => 'item_height',
    ] as $column => $itemKey) {
        if ($sameBasis && $assignIfEmpty($product[$column] ?? null) && !empty($item[$itemKey])) {
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
    OrderWriteService::validateRawNumbers($rawItem);
    $productScope = $product['dimensions_scope'] ?? $product['product_dimensions_scope'] ?? null;
    $dimensionsScope = strtolower(trim((string) ($rawItem['dimensions_scope'] ?? $productScope ?? 'carton')));
    if (!in_array($dimensionsScope, ['piece', 'carton'], true)) {
        $dimensionsScope = 'carton';
    }

    $length = draftOrderNormalizeOptionalDecimal(draftOrderFirstFilledValue($rawItem, ['length', 'item_length']), 'Length');
    $width = draftOrderNormalizeOptionalDecimal(draftOrderFirstFilledValue($rawItem, ['width', 'item_width']), 'Width');
    $height = draftOrderNormalizeOptionalDecimal(draftOrderFirstFilledValue($rawItem, ['height', 'item_height']), 'Height');
    if ($length !== null) {
        $rawItem['item_length'] = $length;
        $rawItem['length'] = $length;
    }
    if ($width !== null) {
        $rawItem['item_width'] = $width;
        $rawItem['width'] = $width;
    }
    if ($height !== null) {
        $rawItem['item_height'] = $height;
        $rawItem['height'] = $height;
    }

    $cartons = (int) ($rawItem['cartons'] ?? 0);
    $piecesPerCarton = (float) ($rawItem['pieces_per_carton'] ?? $rawItem['qty_per_carton'] ?? 0);
    if ($cartons <= 0 || $piecesPerCarton <= 0) {
        jsonError('Each item needs cartons and pieces per carton.', 400, ['items.cartons' => 'Cartons are required.', 'items.pieces_per_carton' => 'Pieces per carton are required.']);
    }

    $quantity = round($cartons * $piecesPerCarton, 4);
    $cbmPerUnit = draftOrderPerUnitCbm($rawItem);
    if ($cbmPerUnit <= 0) {
        jsonError('Each item needs CBM directly or dimensions (L/W/H).', 400, ['items.cbm' => 'Add CBM or length, width, and height.']);
    }

    $weightPerUnit = draftOrderPerUnitWeight($rawItem);
    if ($weightPerUnit < 0) {
        jsonError('Weight must be zero or positive.', 400, ['items.weight' => 'Weight cannot be negative.']);
    }

    $multiplier = $dimensionsScope === 'carton' ? $cartons : $quantity;
    $declaredCbm = round($cbmPerUnit * $multiplier, 6);
    $declaredWeight = round($weightPerUnit * $multiplier, 4);
    $unitPrice = isset($rawItem['unit_price']) && $rawItem['unit_price'] !== '' ? clmsFinancialDecimal($rawItem['unit_price'], 'Unit price', true) : null;
    $sellPrice = isset($rawItem['sell_price']) && $rawItem['sell_price'] !== '' ? clmsFinancialDecimal($rawItem['sell_price'], 'Sell price', true) : null;
    $priceForTotal = $sellPrice ?? $unitPrice;
    $totalAmount = $priceForTotal !== null ? DecimalMath::multiply($priceForTotal, (string) $quantity) : null;
    $photoPaths = normalizeStoredUploadPathList($rawItem['photo_paths'] ?? []);
    $customDesignPaths = normalizeStoredUploadPathList($rawItem['custom_design_paths'] ?? []);
    $customDesignRequired = !empty($rawItem['custom_design_required']) ? 1 : 0;
    $customDesignNote = trim((string) ($rawItem['custom_design_note'] ?? '')) ?: null;
    if ($customDesignRequired && !$customDesignNote && !$customDesignPaths) {
        jsonError('Custom design items need a note or at least one design file.', 400, ['items.custom_design' => 'Add a custom design note or file.']);
    }

    if ($product && !empty($product['supplier_id']) && (int) $product['supplier_id'] !== $supplierId) {
        jsonError('Selected product belongs to another supplier. Create a separate supplier section instead.', 400, ['supplier_id' => 'Selected product belongs to another supplier. Create a separate supplier section instead.']);
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
            jsonError('Each item needs a description.', 400, ['items.description' => 'Item description is required.']);
        }
    }

    OrderWriteService::number($quantity,'Quantity');
    OrderWriteService::number($declaredCbm,'Declared CBM',false,6,999999.999999);
    OrderWriteService::number($declaredWeight,'Declared weight');
    $brand = draftOrderNormalizeItemText($rawItem['brand'] ?? null, 150);
    $whatBrand = draftOrderNormalizeItemText($rawItem['what_brand'] ?? $brand, 150);
    if ($brand === null && $whatBrand !== null) {
        $brand = $whatBrand;
    }
    $classificationService = new ItemClassificationService($pdo);
    $providedType = trim((string) ($rawItem['item_type_code'] ?? $rawItem['copy_normal_goods'] ?? ''));
    if ($providedType !== '') {
        $classification = [
            'item_type_code' => $classificationService->normalize($providedType),
            'confidence' => 1.0,
            'requires_confirmation' => false,
            'source' => 'manual',
        ];
    } else {
        $classification = $classificationService->suggest([
            'description_cn' => $description['description_cn'],
            'description_en' => $description['description_en'],
            'brand' => $brand,
            'supplier_name' => $product['supplier_name'] ?? null,
        ]);
        $classification['source'] = 'suggested';
    }
    $legacyGoodType = ['normal' => 'Normal', 'replica' => 'Copy', 'dangerous' => 'Dangerous'][$classification['item_type_code']] ?? null;
    $itemNumberSource = draftOrderNormalizeItemNumberSource($rawItem);

    return [
        'product_id' => $sharedCartonEnabled ? null : (!empty($rawItem['product_id']) ? (int) $rawItem['product_id'] : null),
        'supplier_id' => $supplierId,
        'item_no' => $sharedCartonEnabled ? null : draftOrderValidateItemNumber($rawItem['item_no'] ?? null, $itemNumberSource),
        'item_number' => clmsPackingListItemNumber($rawItem['item_number'] ?? null),
        'item_number_present' => array_key_exists('item_number', $rawItem),
        'item_no_source' => $sharedCartonEnabled ? 'generated' : $itemNumberSource,
        'item_no_manual' => $sharedCartonEnabled ? 0 : ($itemNumberSource !== 'generated' ? 1 : 0),
        'existing_item_id' => !empty($rawItem['existing_item_id']) ? (int) $rawItem['existing_item_id'] : null,
        'shipping_code' => trim((string) ($rawItem['shipping_code'] ?? '')) ?: null,
        'what_brand' => $whatBrand,
        'brand' => $brand,
        'materials' => draftOrderNormalizeItemText($rawItem['materials'] ?? $rawItem['material'] ?? null, 1000),
        'copy_normal_goods' => $legacyGoodType,
        'item_type_code' => $classification['item_type_code'],
        'item_type_confidence' => $classification['confidence'],
        'item_type_confirmed' => empty($classification['requires_confirmation']) ? 1 : 0,
        'item_type_source' => $classification['source'],
        'code' => draftOrderNormalizeItemText($rawItem['code'] ?? null, 100),
        'express_number' => draftOrderNormalizeItemText($rawItem['express_number'] ?? null, 150),
        'size' => draftOrderNormalizeItemText($rawItem['size'] ?? null, 150),
        'cartons' => $cartons,
        'pieces_per_carton' => round($piecesPerCarton, 4),
        'quantity' => $quantity,
        'unit' => draftOrderNormalizeUnit($rawItem['unit'] ?? null),
        'unit_price' => $unitPrice,
        'sell_price' => $sellPrice,
        'total_amount' => $totalAmount,
        'cbm_mode' => ($rawItem['cbm_mode'] ?? 'direct') === 'dimensions' ? 'dimensions' : 'direct',
        'dimensions_scope' => $dimensionsScope,
        'cbm_per_unit' => $cbmPerUnit,
        'weight_per_unit' => $weightPerUnit,
        'declared_cbm' => $declaredCbm,
        'declared_weight' => $declaredWeight,
        'item_length' => $length,
        'item_width' => $width,
        'item_height' => $height,
        'length' => $length,
        'width' => $width,
        'height' => $height,
        'hs_code' => draftOrderNormalizeHsCode($rawItem['hs_code'] ?? ($product['hs_code'] ?? null)),
        'description_entries' => $description['entries'],
        'description_cn' => $description['description_cn'],
        'description_en' => $description['description_en'],
        'notes' => trim((string) ($rawItem['notes'] ?? '')) ?: null,
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
    if(count($sections)>500)jsonError('Too many supplier sections',422);
    $normalized = [];
    foreach ($sections as $section) {
        if(!is_array($section)||!is_array($section['items']??null))jsonError('Supplier sections require an items array',422);
        $supplierId = (int) ($section['supplier_id'] ?? 0);
        $supplierCheck=$pdo->prepare('SELECT id FROM suppliers WHERE id=?');$supplierCheck->execute([$supplierId]);if(!$supplierCheck->fetchColumn())jsonError('Supplier section not found',422);
        if ($supplierId <= 0) {
            jsonError('Each supplier section needs a supplier.', 400, ['supplier_sections.supplier_id' => 'Supplier is required.']);
        }

        foreach (($section['items'] ?? []) as $rawItem) {
            if(!is_array($rawItem))jsonError('Draft item must be an object',422);
            OrderWriteService::validateRawNumbers($rawItem);
            if(count($normalized)>=500)jsonError('No more than 500 order items are supported',422);
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
        jsonError('At least one supplier section with one item is required.', 400, ['supplier_sections' => 'Add at least one supplier section.']);
    }

    return $normalized;
}

function draftOrderAssignCanonicalItemNumbers(PDO $pdo, int $customerId, array $items, ?int $defaultSupplierId = null, ?int $destinationCountryId = null, ?int $excludeOrderId = null): array
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
                    'item_no_source' => $content['item_no_source'] ?? 'generated',
                    'item_no_preserve' => !empty($content['item_no_preserve']) ? 1 : 0,
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
            'item_no_source' => $item['item_no_source'] ?? 'generated',
            'item_no_preserve' => !empty($item['item_no_preserve']) ? 1 : 0,
            'shipping_code' => $itemShippingCode,
        ];
        $numberingMap[] = [$itemIndex, null];
    }

    if (!$numberingTargets) {
        return $items;
    }

    $history = OrderItemNumberingService::fetchNumberingHistory($pdo, $customerId, $excludeOrderId);
    $assigned = OrderItemNumberingService::assignItemNumbers($numberingTargets, $shippingCode, $defaultSupplierId, $history);
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

function draftOrderLockCustomerNumbering(PDO $pdo, int $customerId): void
{
    $stmt = $pdo->prepare('SELECT id FROM customers WHERE id = ? FOR UPDATE');
    $stmt->execute([$customerId]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Customer not found while reserving item numbers');
    }
}

function draftOrderAssertUniqueItemNumbers(PDO $pdo, int $customerId, array $items, int $excludeOrderId = 0): array
{
    $numbers = [];
    $sources = [];
    $warnings = [];
    foreach ($items as $item) {
        $candidates = empty($item['shared_carton_enabled']) ? [[
            'value' => $item['item_no'] ?? null,
            'source' => $item['item_no_source'] ?? 'generated',
        ]] : [];
        foreach (($item['shared_carton_contents'] ?? []) as $content) {
            $candidates[] = is_array($content) ? ['value'=>$content['item_no']??null,'source'=>$content['item_no_source']??'generated'] : ['value'=>null,'source'=>'generated'];
        }
        foreach ($candidates as $candidate) {
            $number = (string) ($candidate['value'] ?? '');
            $source = (string) ($candidate['source'] ?? 'generated');
            if (trim($number) === '') {
                if (in_array($source, ['manual','imported'], true)) continue;
                throw new RuntimeException('Generated item number is blank');
            }
            $key = ItemNumberReservationService::normalize($number);
            if (isset($numbers[$key])) {
                if ($source === 'imported' || ($sources[$key] ?? '') === 'imported') {
                    $warnings[] = 'Duplicate imported item number preserved: ' . $number;
                    continue;
                }
                throw new RuntimeException('Duplicate item number in this draft: ' . $number);
            }
            $numbers[$key] = $number;
            $sources[$key] = $source;
        }
    }
    if (!$numbers) return $warnings;

    $strictNumbers=[];
    foreach($numbers as $key=>$number) if(($sources[$key]??'generated')!=='imported') $strictNumbers[]=$number;
    if($strictNumbers) (new ItemNumberReservationService($pdo))->assertCandidatesAvailable($customerId, $strictNumbers, $excludeOrderId);

    $sql = "SELECT oi.item_no,oi.shared_carton_contents FROM order_items oi JOIN orders o ON o.id=oi.order_id
            WHERE o.customer_id=?";
    $params = [$customerId];
    if ($excludeOrderId > 0) {
        $sql .= ' AND o.id<>?';
        $params[] = $excludeOrderId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
        $existing=[(string)($row['item_no']??'')];$contents=json_decode((string)($row['shared_carton_contents']??''),true);
        if(is_array($contents))foreach($contents as $content)$existing[]=(string)($content['item_no']??'');
        foreach($existing as $value){
            $normalized=ItemNumberReservationService::normalize($value);
            if($normalized===''||!isset($numbers[$normalized]))continue;
            if(($sources[$normalized]??'generated')==='imported'){$warnings[]='Imported item number conflicts with an existing active number and was preserved: '.$numbers[$normalized];continue;}
            throw new RuntimeException('Item number already exists for this customer: '.$value);
        }
    }
    return array_values(array_unique($warnings));
}

function draftOrderPreserveItemNumber(array $item, array $old): array
{
    $oldValue = (string) ($old['item_no'] ?? '');
    $newValue = (string) ($item['item_no'] ?? '');
    $oldSource = draftOrderNormalizeItemNumberSource($old);
    if ($newValue === $oldValue) {
        // Persisted identifiers (including generated ones) never need reallocation.
        $item['item_no_preserve'] = 1;
        if ($oldSource !== 'generated') $item['item_no_source'] = $oldSource;
    } elseif (($item['item_no_source'] ?? 'generated') === 'generated') {
        // An existing row's edited identifier is an override, even from an older UI.
        $item['item_no_source'] = 'manual';
    }
    $item['item_no_manual'] = ($item['item_no_source'] ?? 'generated') !== 'generated' ? 1 : 0;
    return $item;
}

function draftOrderPreserveExistingNumbers(PDO $pdo, int $orderId, array $items): array
{
    $sourceSelect = draftOrderTableHasColumn($pdo, 'order_items', 'item_no_source') ? 'item_no_source' : "'generated' AS item_no_source";
    $stmt=$pdo->prepare("SELECT id,item_no,item_number,$sourceSelect,shared_carton_contents FROM order_items WHERE order_id=?");
    $stmt->execute([$orderId]);
    $existing=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $existing[(int)$row['id']]=$row;

    foreach($items as &$item){
        $existingId=(int)($item['existing_item_id']??0);
        $old=$existing[$existingId]??null;
        if(!$old) continue;
        if (empty($item['item_number_present'])) $item['item_number'] = $old['item_number'] ?? null;
        $item = draftOrderPreserveItemNumber($item, $old);

        $oldContents=json_decode((string)($old['shared_carton_contents']??''),true);
        if(!is_array($oldContents)) $oldContents=[];
        if (!isset($item['shared_carton_contents']) || !is_array($item['shared_carton_contents'])) continue;
        foreach($item['shared_carton_contents'] as $index=>&$content){
            $oldContent=$oldContents[$index]??null;
            if(!is_array($oldContent)) continue;
            if (empty($content['item_number_present'])) {
                // Internal identifiers disambiguate contained products after reorder.
                foreach ($oldContents as $candidate) {
                    if (is_array($candidate) && !empty($content['item_no'])
                        && ($candidate['item_no'] ?? null) === $content['item_no']) {
                        $oldContent = $candidate;
                        break;
                    }
                }
                $content['item_number'] = $oldContent['item_number'] ?? null;
            }
            $content = draftOrderPreserveItemNumber($content, $oldContent);
        }
        unset($content);
    }
    unset($item);
    return $items;
}

function draftOrderCollectItemNumberAudit(PDO $pdo, int $orderId, array $items): array
{
    $sourceSelect = draftOrderTableHasColumn($pdo, 'order_items', 'item_no_source') ? 'item_no_source' : "'generated' AS item_no_source";
    $stmt=$pdo->prepare("SELECT id,item_no,$sourceSelect,shared_carton_contents FROM order_items WHERE order_id=?");
    $stmt->execute([$orderId]);
    $existing=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $existing[(int)$row['id']]=$row;
    $changes=[];
    foreach($items as $index=>$item){
        $oldId=(int)($item['existing_item_id']??0);
        $old=$existing[$oldId]??null;
        if(!$old) continue;
        $oldValue=(string)($old['item_no']??'');
        $newValue=(string)($item['item_no']??'');
        if(($item['item_no_source']??'generated')==='manual' && $oldValue!==$newValue){
            $changes[]=['item_index'=>$index,'old_item_id'=>$oldId,'supplier_id'=>$item['supplier_id']??null,'old_value'=>$oldValue,'new_value'=>$newValue];
        }
        $oldContents=json_decode((string)($old['shared_carton_contents']??''),true);
        if(!is_array($oldContents)) $oldContents=[];
        foreach(($item['shared_carton_contents']??[]) as $contentIndex=>$content){
            $oldValue=(string)($oldContents[$contentIndex]['item_no']??'');
            $newValue=(string)($content['item_no']??'');
            if(($content['item_no_source']??'generated')==='manual' && $oldValue!==$newValue){
                $changes[]=['item_index'=>$index,'content_index'=>$contentIndex,'old_item_id'=>$oldId,'supplier_id'=>$content['supplier_id']??$item['supplier_id']??null,'old_value'=>$oldValue,'new_value'=>$newValue];
            }
        }
    }
    return $changes;
}

function draftOrderWriteItemNumberAudit(PDO $pdo, int $orderId, array $changes, array $insertedItems, int $userId): void
{
    if(!$changes) return;
    $stmt=$pdo->prepare("INSERT INTO audit_log (entity_type,entity_id,action,old_value,new_value,user_id) VALUES ('order_item',?,'item_number_changed',?,?,?)");
    foreach($changes as $change){
        $newItemId=(int)($insertedItems[$change['item_index']]['id']??0);
        $context=['draft_id'=>$orderId,'old_item_id'=>$change['old_item_id'],'new_item_id'=>$newItemId,'supplier_id'=>$change['supplier_id']??null];
        if(isset($change['content_index'])) $context['shared_content_index']=$change['content_index'];
        $stmt->execute([
            $newItemId?:$change['old_item_id'],
            json_encode($context+['item_no'=>$change['old_value']],JSON_UNESCAPED_UNICODE),
            json_encode($context+['item_no'=>$change['new_value']],JSON_UNESCAPED_UNICODE),
            $userId,
        ]);
    }
}

function draftOrderIdempotencyKey($value): string
{
    return OrderWriteService::requestKey($value);
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
    OrderWriteService::requireMeasurementSchema($pdo);
    $hasItemNoSource = draftOrderTableHasColumn($pdo, 'order_items', 'item_no_source');
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
    $metadataColumns = [];
    foreach (['dimensions_scope', 'item_number', 'what_brand', 'brand', 'materials', 'copy_normal_goods', 'code', 'express_number', 'size', 'length', 'width', 'height'] as $column) {
        if (draftOrderTableHasColumn($pdo, 'order_items', $column)) {
            $metadataColumns[] = $column;
        }
    }

    $columns = "order_id, product_id, item_no, shipping_code, cartons, qty_per_carton, quantity, unit, declared_cbm, declared_weight, item_length, item_width, item_height, unit_price, total_amount, notes, image_paths, description_cn, description_en";
    $placeholders = "?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?";
    foreach ($metadataColumns as $column) {
        $columns .= ", $column";
        $placeholders .= ",?";
    }
    if ($hasItemNoSource) {
        $columns .= ", item_no_source";
        $placeholders .= ",?";
    }
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
            $item['notes'] ?? null,
            $item['photo_paths'] ? json_encode($item['photo_paths']) : null,
            $item['description_cn'],
            $item['description_en'],
        ];
        foreach ($metadataColumns as $column) {
            $params[] = $item[$column] ?? null;
        }
        if ($hasItemNoSource) {
            $params[] = in_array((string) ($item['item_no_source'] ?? ''), ['generated', 'manual', 'imported'], true)
                ? $item['item_no_source']
                : 'generated';
        }
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
            $storedContents = array_map(static function (array $content): array {
                unset($content['item_number_present']); // Request-only omission tracking.
                return $content;
            }, $item['shared_carton_contents'] ?? []);
            $params[] = !empty($item['shared_carton_contents'])
                ? json_encode($storedContents, JSON_UNESCAPED_UNICODE)
                : null;
        }
        $insert->execute($params);
        $item['id'] = (int) $pdo->lastInsertId();
        if (draftOrderTableHasColumn($pdo, 'item_classifications', 'item_type_code')) {
            (new ItemClassificationService($pdo))->set(
                'order_item',
                $item['id'],
                $item['item_type_code'] ?? 'unclassified',
                isset($item['item_type_confidence']) ? (float) $item['item_type_confidence'] : null,
                !empty($item['item_type_confirmed']),
                $userId,
                (string) ($item['item_type_source'] ?? 'suggested')
            );
        }
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
    $productImageSelect = draftOrderTableHasColumn($pdo, 'products', 'image_paths')
        ? ', p.image_paths as product_image_paths'
        : '';
    $select = "oi.*, s.name as supplier_name, p.hs_code as product_hs_code, p.dimensions_scope as product_dimensions_scope$productImageSelect";
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

    $receiptImages = clmsReceiptItemImagePaths($pdo, $itemIds);
    $orderReceiptImages = clmsOrderReceiptImagePaths($pdo, [$orderId]);
    $firstItemId = isset($items[0]['id']) ? (int) $items[0]['id'] : 0;
    foreach ($items as &$item) {
        $item['image_paths'] = clmsMergeImagePathLists(
            $item['image_paths'] ?? [],
            $item['product_image_paths'] ?? [],
            $receiptImages[(int) $item['id']] ?? [],
            (int) $item['id'] === $firstItemId ? ($orderReceiptImages[$orderId] ?? []) : []
        );
        $item['description_entries'] = draftOrderSplitDescriptionEntries($item['description_cn'] ?? null, $item['description_en'] ?? null);
        $scope = strtolower((string) ($item['dimensions_scope'] ?? $item['product_dimensions_scope'] ?? 'carton'));
        if (!in_array($scope, ['piece', 'carton'], true)) {
            $scope = 'carton';
        }
        $multiplier = $scope === 'carton'
            ? ((float) ($item['cartons'] ?? 0) ?: 0)
            : (draftOrderGetQuantity($item) ?: 0);
        $design = $designMap[(int) $item['id']] ?? ['paths' => [], 'note' => null];
        $item['dimensions_scope'] = $scope;
        $item['cbm_per_unit'] = $multiplier > 0 ? (((float) ($item['declared_cbm'] ?? 0)) / $multiplier) : 0.0;
        $item['weight_per_unit'] = $multiplier > 0 ? (((float) ($item['declared_weight'] ?? 0)) / $multiplier) : 0.0;
        $item['hs_code'] = $item['hs_code'] ?? $item['product_hs_code'] ?? null;
        $item['brand'] = draftOrderNormalizeItemText($item['brand'] ?? $item['what_brand'] ?? null, 150);
        $item['what_brand'] = draftOrderNormalizeItemText($item['what_brand'] ?? $item['brand'] ?? null, 150);
        $item['materials'] = draftOrderNormalizeItemText($item['materials'] ?? null, 1000);
        $item['length'] = isset($item['length']) && $item['length'] !== null && $item['length'] !== ''
            ? round((float) $item['length'], 4)
            : (isset($item['item_length']) && $item['item_length'] !== null && $item['item_length'] !== '' ? round((float) $item['item_length'], 4) : null);
        $item['width'] = isset($item['width']) && $item['width'] !== null && $item['width'] !== ''
            ? round((float) $item['width'], 4)
            : (isset($item['item_width']) && $item['item_width'] !== null && $item['item_width'] !== '' ? round((float) $item['item_width'], 4) : null);
        $item['height'] = isset($item['height']) && $item['height'] !== null && $item['height'] !== ''
            ? round((float) $item['height'], 4)
            : (isset($item['item_height']) && $item['item_height'] !== null && $item['item_height'] !== '' ? round((float) $item['item_height'], 4) : null);
        $item['custom_design_required'] = !empty($item['custom_design_required']) || !empty($design['paths']) ? 1 : 0;
        $item['custom_design_note'] = $item['custom_design_note'] ?: $design['note'];
        $item['custom_design_paths'] = $design['paths'];
        $item['shared_carton_enabled'] = $hasSharedCartonEnabled ? (!empty($item['shared_carton_enabled']) ? 1 : 0) : 0;
        $item['shared_carton_code'] = $hasSharedCartonCode ? (trim((string) ($item['shared_carton_code'] ?? '')) ?: null) : null;
        $item['shared_carton_contents'] = ($hasSharedCartonEnabled && $hasSharedCartonContents && !empty($item['shared_carton_enabled']))
            ? draftOrderDecodeSharedCartonContents($pdo, $item)
            : [];
        $classification = draftOrderTableHasColumn($pdo, 'item_classifications', 'item_type_code')
            ? (new ItemClassificationService($pdo))->get('order_item', (int) $item['id'])
            : null;
        $item['item_type_code'] = $classification['item_type_code'] ?? ((new ItemClassificationService($pdo))->normalize($item['copy_normal_goods'] ?? ''));
        $item['item_type_confidence'] = isset($classification['confidence']) ? (float) $classification['confidence'] : null;
        $item['item_type_confirmed'] = !empty($classification['is_confirmed']) ? 1 : 0;
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
                'totals' => ['amount' => '0.0000', 'cbm' => 0.0, 'weight' => 0.0],
            ];
        }
        $sections[$key]['items'][] = [
            'id' => (int) $item['id'],
            'existing_item_id' => (int) $item['id'],
            'product_id' => (!empty($item['shared_carton_enabled']) ? null : (!empty($item['product_id']) ? (int) $item['product_id'] : null)),
            'item_no' => !empty($item['shared_carton_enabled']) ? null : ($item['item_no'] ?? null),
            'item_number' => $item['item_number'] ?? null,
            'item_no_source' => in_array((string) ($item['item_no_source'] ?? ''), ['generated', 'manual', 'imported'], true) ? $item['item_no_source'] : 'generated',
            'item_no_manual' => in_array((string) ($item['item_no_source'] ?? ''), ['manual', 'imported'], true) ? 1 : 0,
            'shipping_code' => $item['shipping_code'] ?: null,
            'what_brand' => $item['what_brand'] ?? null,
            'brand' => $item['brand'] ?? $item['what_brand'] ?? null,
            'materials' => $item['materials'] ?? null,
            'copy_normal_goods' => $item['copy_normal_goods'] ?? null,
            'item_type_code' => $item['item_type_code'] ?? 'unclassified',
            'item_type_confidence' => $item['item_type_confidence'] ?? null,
            'item_type_confirmed' => !empty($item['item_type_confirmed']) ? 1 : 0,
            'code' => $item['code'] ?? null,
            'express_number' => $item['express_number'] ?? null,
            'size' => $item['size'] ?? null,
            'cartons' => (int) ($item['cartons'] ?? 0),
            'pieces_per_carton' => isset($item['qty_per_carton']) ? (float) $item['qty_per_carton'] : null,
            'quantity' => draftOrderGetQuantity($item),
            'unit' => draftOrderNormalizeUnit($item['unit'] ?? null),
            'unit_price' => $item['unit_price'] !== null ? DecimalMath::normalize($item['unit_price']) : null,
            'sell_price' => isset($item['sell_price']) && $item['sell_price'] !== null ? DecimalMath::normalize($item['sell_price']) : null,
            'total_amount' => $item['total_amount'] !== null ? DecimalMath::normalize($item['total_amount']) : null,
            'cbm_mode' => (!empty($item['item_length']) && !empty($item['item_width']) && !empty($item['item_height'])) ? 'dimensions' : 'direct',
            'cbm' => $item['cbm_per_unit'],
            'declared_cbm' => $item['declared_cbm']!==null?(float)$item['declared_cbm']:null,
            'declared_weight' => $item['declared_weight']!==null?(float)$item['declared_weight']:null,
            'item_length' => $item['item_length'] !== null ? (float) $item['item_length'] : null,
            'item_width' => $item['item_width'] !== null ? (float) $item['item_width'] : null,
            'item_height' => $item['item_height'] !== null ? (float) $item['item_height'] : null,
            'length' => isset($item['length']) && $item['length'] !== null ? (float) $item['length'] : ($item['item_length'] !== null ? (float) $item['item_length'] : null),
            'width' => isset($item['width']) && $item['width'] !== null ? (float) $item['width'] : ($item['item_width'] !== null ? (float) $item['item_width'] : null),
            'height' => isset($item['height']) && $item['height'] !== null ? (float) $item['height'] : ($item['item_height'] !== null ? (float) $item['item_height'] : null),
            'weight' => $item['weight_per_unit'],
            'dimensions_scope' => $item['dimensions_scope'],
            'hs_code' => draftOrderNormalizeHsCode($item['hs_code'] ?? null),
            'description_entries' => $item['description_entries'] ?? [],
            'description_cn' => $item['description_cn'] ?? null,
            'description_en' => $item['description_en'] ?? null,
            'notes' => trim((string) ($item['notes'] ?? '')) ?: null,
            'photo_paths' => $item['image_paths'] ?? [],
            'custom_design_required' => !empty($item['custom_design_required']) ? 1 : 0,
            'custom_design_note' => $item['custom_design_note'] ?: null,
            'custom_design_paths' => $item['custom_design_paths'] ?? [],
            'shared_carton_enabled' => !empty($item['shared_carton_enabled']) ? 1 : 0,
            'shared_carton_code' => $item['shared_carton_code'] ?? null,
            'shared_carton_contents' => $item['shared_carton_contents'] ?? [],
        ];
        $sections[$key]['totals']['amount'] = DecimalMath::add($sections[$key]['totals']['amount'], $item['total_amount'] ?? '0');
        $sections[$key]['totals']['cbm'] += (float) ($item['declared_cbm'] ?? 0);
        $sections[$key]['totals']['weight'] += (float) ($item['declared_weight'] ?? 0);
    }

    foreach ($sections as &$section) {
        $section['totals']['amount'] = DecimalMath::normalize($section['totals']['amount']);
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
    $costs = draftOrderTableHasColumn($pdo, 'draft_order_costs', 'base_amount')
        ? (new DraftOrderCostService($pdo))->summarize($orderId)
        : ['lines' => [], 'totals_by_currency' => [], 'base_currency' => null, 'base_total' => '0.0000', 'accounting_treatment' => 'shipment_expense_customer_charge'];

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
        'lock_version' => (int) ($order['lock_version'] ?? 0),
        'editable' => $order['status'] === 'Draft',
        'supplier_sections' => $sections,
        'operational_costs' => $costs,
        'totals' => [
            'amount' => DecimalMath::sum(array_map(static fn($item) => $item['total_amount'] ?? '0', $items)),
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
                $sharedContentImages = [];
                foreach ($item['shared_carton_contents'] as $content) {
                    $sharedContentImages = clmsMergeImagePathLists(
                        $sharedContentImages,
                        $content['image_paths'] ?? [],
                        $content['photo_paths'] ?? []
                    );
                }
                $rows[] = [
                    'row_type' => 'shared_carton_summary',
                    'source_item_id' => (int) ($item['id'] ?? 0),
                    'product_id' => !empty($item['product_id']) ? (int) $item['product_id'] : null,
                    'supplier_id' => $section['supplier_id'] ?? null,
                    'supplier_name' => $section['supplier_name'] ?? '',
                    'item_no' => $item['shared_carton_code'] ?: $item['shipping_code'] ?: '',
                    'item_number' => $item['item_number'] ?? null,
                    'what_brand' => $item['what_brand'] ?? '',
                    'brand' => $item['brand'] ?? $item['what_brand'] ?? '',
                    'materials' => $item['materials'] ?? '',
                    'copy_normal_goods' => $item['copy_normal_goods'] ?? '',
                    'code' => $item['code'] ?? '',
                    'express_number' => $item['express_number'] ?? '',
                    'size' => $item['size'] ?? '',
                    'length' => $item['length'] ?? $item['item_length'] ?? '',
                    'width' => $item['width'] ?? $item['item_width'] ?? '',
                    'height' => $item['height'] ?? $item['item_height'] ?? '',
                    'description' => 'Shared carton / multiple items',
                    'description_en' => 'Shared carton / multiple items',
                    'description_cn' => '共享纸箱 / 多件商品',
                    'hs_code' => '',
                    'pieces_per_carton' => $item['pieces_per_carton'] ?? '',
                    'cartons' => $item['cartons'] ?? '',
                    'quantity' => $item['quantity'] ?? '',
                    'unit' => $item['unit'] ?? '',
                    'unit_price' => null,
                    'sell_price' => null,
                    'total_amount' => null,
                    'cbm' => $item['cbm'] ?? '',
                    'total_cbm' => $item['declared_cbm'] ?? round((float) (($item['cbm'] ?? 0) * (($item['dimensions_scope'] ?? 'carton') === 'carton' ? (float) ($item['cartons'] ?? 0) : (float) ($item['quantity'] ?? 0))), 6),
                    'weight' => $item['weight'] ?? '',
                    'total_weight' => $item['declared_weight'] ?? round((float) (($item['weight'] ?? 0) * (($item['dimensions_scope'] ?? 'carton') === 'carton' ? (float) ($item['cartons'] ?? 0) : (float) ($item['quantity'] ?? 0))), 4),
                    'custom_design_required' => !empty($item['custom_design_required']) ? 'Yes' : 'No',
                    'notes' => $item['notes'] ?? '',
                    'image_paths' => clmsMergeImagePathLists($item['photo_paths'] ?? [], $sharedContentImages),
                    'carton_note' => $item['shared_carton_code'] ? ('Shared carton ' . $item['shared_carton_code']) : 'Shared carton',
                ];

                foreach ($item['shared_carton_contents'] as $content) {
                    $descriptionEn = implode(' | ', array_map(
                        static fn($entry) => trim((string) (($entry['description_translated'] ?? '') ?: ($entry['description_text'] ?? ''))),
                        $content['description_entries'] ?? []
                    ));
                    $descriptionCn = implode(' | ', array_map(
                        static fn($entry) => trim((string) (($entry['description_text'] ?? '') ?: ($entry['description_translated'] ?? ''))),
                        $content['description_entries'] ?? []
                    ));
                    $customerPrice = isset($content['sell_price']) && $content['sell_price'] !== null && $content['sell_price'] !== ''
                        ? (float) $content['sell_price']
                        : (isset($content['unit_price']) && $content['unit_price'] !== null ? (float) $content['unit_price'] : null);
                    $rows[] = [
                        'row_type' => 'shared_carton_content',
                        'source_item_id' => (int) ($item['id'] ?? 0),
                        'product_id' => !empty($content['product_id']) ? (int) $content['product_id'] : null,
                        'supplier_id' => $content['supplier_id'] ?? ($section['supplier_id'] ?? null),
                        'supplier_name' => $content['supplier_name'] ?? ($section['supplier_name'] ?? ''),
                        // The row type already identifies shared-carton contents. Keep the
                        // stored item number byte-for-byte visible in every export.
                        'item_no' => $content['item_no'] ?? '',
                        'item_number' => $content['item_number'] ?? null,
                        'what_brand' => $content['what_brand'] ?? '',
                        'brand' => $content['brand'] ?? $content['what_brand'] ?? '',
                        'materials' => $content['materials'] ?? '',
                        'copy_normal_goods' => $content['copy_normal_goods'] ?? '',
                        'code' => $content['code'] ?? '',
                        'express_number' => $content['express_number'] ?? '',
                        'size' => $content['size'] ?? '',
                        'length' => $content['length'] ?? $content['item_length'] ?? '',
                        'width' => $content['width'] ?? $content['item_width'] ?? '',
                        'height' => $content['height'] ?? $content['item_height'] ?? '',
                        'description' => trim(($item['shared_carton_code'] ? ('[' . $item['shared_carton_code'] . '] ') : '') . $descriptionEn),
                        'description_en' => $descriptionEn,
                        'description_cn' => $descriptionCn,
                        'hs_code' => $content['hs_code'] ?? '',
                        'pieces_per_carton' => $content['quantity_per_carton'] ?? '',
                        'cartons' => '',
                        'quantity' => $content['quantity'] ?? '',
                        'unit' => $content['unit'] ?? '',
                        'unit_price' => $content['unit_price'] ?? '',
                        'sell_price' => $customerPrice,
                        'total_amount' => $content['total_amount'] ?? '',
                        'cbm' => '',
                        'total_cbm' => '',
                        'weight' => '',
                        'total_weight' => '',
                        'custom_design_required' => '',
                        'notes' => $content['notes'] ?? '',
                        'image_paths' => clmsMergeImagePathLists(
                            $content['image_paths'] ?? [],
                            $content['photo_paths'] ?? []
                        ),
                        'carton_note' => $item['shared_carton_code'] ? ('Shared carton ' . $item['shared_carton_code']) : 'Shared carton',
                    ];
                }
                continue;
            }

            $descEn = implode(' | ', array_map(
                static fn($entry) => trim((string) (($entry['description_translated'] ?? '') ?: ($entry['description_text'] ?? ''))),
                $item['description_entries'] ?? []
            ));
            $descCn = implode(' | ', array_map(
                static fn($entry) => trim((string) (($entry['description_text'] ?? '') ?: ($entry['description_translated'] ?? ''))),
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
                'source_item_id' => (int) ($item['id'] ?? 0),
                'product_id' => !empty($item['product_id']) ? (int) $item['product_id'] : null,
                'supplier_id' => $section['supplier_id'] ?? null,
                'supplier_name' => $section['supplier_name'] ?? '',
                'item_no' => $item['item_no'] ?? '',
                'item_number' => $item['item_number'] ?? null,
                'what_brand' => $item['what_brand'] ?? '',
                'brand' => $item['brand'] ?? $item['what_brand'] ?? '',
                'materials' => $item['materials'] ?? '',
                'copy_normal_goods' => $item['copy_normal_goods'] ?? '',
                'code' => $item['code'] ?? '',
                'express_number' => $item['express_number'] ?? '',
                'size' => $item['size'] ?? '',
                'length' => $item['length'] ?? $item['item_length'] ?? '',
                'width' => $item['width'] ?? $item['item_width'] ?? '',
                'height' => $item['height'] ?? $item['item_height'] ?? '',
                'description' => $descEn,
                'description_en' => $descEn,
                'description_cn' => $descCn,
                'hs_code' => $item['hs_code'] ?: '',
                'pieces_per_carton' => $item['pieces_per_carton'] ?? '',
                'cartons' => $item['cartons'] ?? '',
                'quantity' => $item['quantity'] ?? '',
                'unit' => $item['unit'] ?? '',
                'unit_price' => $item['unit_price'] ?? '',
                'sell_price' => $customerPrice,
                'total_amount' => $item['total_amount'] ?? '',
                'cbm' => $item['cbm'] ?? '',
                'total_cbm' => $item['declared_cbm'] ?? round((float) (($item['cbm'] ?? 0) * $multiplier), 6),
                'weight' => $item['weight'] ?? '',
                'total_weight' => $item['declared_weight'] ?? round((float) (($item['weight'] ?? 0) * $multiplier), 4),
                'custom_design_required' => !empty($item['custom_design_required']) ? 'Yes' : 'No',
                'notes' => $item['notes'] ?? '',
                'image_paths' => $item['photo_paths'] ?? [],
                'carton_note' => '',
            ];
        }
    }

    return $rows;
}

function draftOrderListQuery(PDO $pdo, array $filters, bool $paginate = true): array
{
    $visibleStatuses = ['Draft', 'Submitted', 'Confirmed', 'Approved'];
    $where = ["o.order_type = 'draft_procurement'", 'o.status IN (?,?,?,?)'];
    $params = $visibleStatuses;
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $like = clmsSearchLike($q);
        $classificationJoin = draftOrderTableHasColumn($pdo, 'item_classifications', 'item_type_code')
            ? " LEFT JOIN item_classifications icq ON icq.entity_type='order_item' AND icq.entity_id=oi.id"
            : '';
        $classificationSearch = $classificationJoin !== '' ? ' OR icq.item_type_code LIKE ?' : '';
        $sharedParams = [];
        $sharedSearch = draftOrderSupportsSharedCartons($pdo)
            ? ' OR ' . clmsSharedCartonIdentifierSearch('oi.shared_carton_contents', 'item_no', $pdo, $like, $sharedParams)
                . ' OR ' . clmsSharedCartonIdentifierSearch('oi.shared_carton_contents', 'item_number', $pdo, $like, $sharedParams)
            : '';
        $where[] = "(CAST(o.id AS CHAR) LIKE ? OR c.name LIKE ? OR EXISTS (
            SELECT 1 FROM order_items oi
            LEFT JOIN suppliers si ON si.id = oi.supplier_id
            $classificationJoin
            WHERE oi.order_id = o.id AND (
                oi.item_no LIKE ? OR oi.item_number LIKE ? OR oi.shipping_code LIKE ? OR oi.code LIKE ?
                OR oi.description_en LIKE ? OR oi.description_cn LIKE ?
                OR oi.brand LIKE ? OR oi.what_brand LIKE ? OR oi.materials LIKE ?
                OR oi.copy_normal_goods LIKE ? OR si.name LIKE ?$classificationSearch$sharedSearch
            )
        ))";
        $params = array_merge($params, array_fill(0, $classificationJoin !== '' ? 14 : 13, $like), $sharedParams);
    }
    $statuses = $filters['status'] ?? [];
    if (!is_array($statuses)) $statuses = preg_split('/\s*,\s*/', (string) $statuses) ?: [];
    $statuses = array_values(array_intersect(
        $visibleStatuses,
        array_unique(array_filter(array_map('trim', $statuses)))
    ));
    if ($statuses) {
        $where[] = 'o.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
        array_push($params, ...$statuses);
    }
    foreach (['customer_id' => 'o.customer_id', 'creator_id' => 'o.created_by'] as $key => $column) {
        if ($key === 'creator_id' && !empty($filters[$key]) && !hasAnyRole(['SuperAdmin', 'ChinaAdmin', 'LebanonAdmin'])) jsonError('Creator filtering requires administrator access',403);
        $value = (int) ($filters[$key] ?? 0);
        if ($value > 0) { $where[] = "$column = ?"; $params[] = $value; }
    }
    $supplierId = (int) ($filters['supplier_id'] ?? 0);
    if ($supplierId > 0) {
        $sharedParams = [];
        $sharedPredicate = draftOrderSupportsSharedCartons($pdo) ? ' OR ' . clmsSharedCartonSupplierPredicate('ois.shared_carton_contents', $pdo, $supplierId, $sharedParams) : '';
        $where[] = 'EXISTS (SELECT 1 FROM order_items ois WHERE ois.order_id=o.id AND (ois.supplier_id=?' . $sharedPredicate . '))';
        $params[] = $supplierId;
        array_push($params, ...$sharedParams);
    }
    $goodsType = trim((string) ($filters['goods_type'] ?? ''));
    if ($goodsType !== '') {
        $canonicalType = (new ItemClassificationService($pdo))->normalize($goodsType);
        if ($canonicalType === 'unclassified' && strtolower($goodsType) !== 'unclassified') jsonError('Invalid goods type filter',422);
        $legacyAliases = [
            'normal' => ['normal'],
            'replica' => ['copy', 'replica'],
            'dangerous' => ['dangerous'],
        ][$canonicalType] ?? [$canonicalType];
        $legacySql = implode(',', array_fill(0, count($legacyAliases), '?'));
        if (draftOrderTableHasColumn($pdo, 'item_classifications', 'item_type_code')) {
            $where[] = "EXISTS (
                SELECT 1 FROM order_items oif
                LEFT JOIN item_classifications icf ON icf.entity_type='order_item' AND icf.entity_id=oif.id
                WHERE oif.order_id=o.id AND (icf.item_type_code=? OR LOWER(TRIM(COALESCE(oif.copy_normal_goods,''))) IN ($legacySql))
            )";
            $params[] = $canonicalType;
        } else {
            $where[] = "EXISTS (SELECT 1 FROM order_items oif WHERE oif.order_id=o.id AND LOWER(TRIM(COALESCE(oif.copy_normal_goods,''))) IN ($legacySql))";
        }
        array_push($params, ...$legacyAliases);
    }
    $brand = trim((string) ($filters['brand'] ?? ''));
    if ($brand !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM order_items oif WHERE oif.order_id=o.id AND (oif.brand=? OR oif.what_brand=?))';
        $params[] = $brand;
        $params[] = $brand;
    }
    foreach ([
        'created_from' => ['o.created_at', '>=', ' 00:00:00'],
        'created_to' => ['o.created_at', '<=', ' 23:59:59'],
        'expected_from' => ['o.expected_ready_date', '>=', ''],
        'expected_to' => ['o.expected_ready_date', '<=', ''],
    ] as $key => [$column, $operator, $suffix]) {
        $value = trim((string) ($filters[$key] ?? ''));
        if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $where[] = "$column $operator ?";
            $params[] = $value . $suffix;
        }
    }

    $from = ' FROM orders o JOIN customers c ON c.id=o.customer_id WHERE ' . implode(' AND ', $where);
    $countStmt = $pdo->prepare('SELECT COUNT(*)' . $from);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $page = max(1, (int) ($filters['page'] ?? 1));
    $limit = max(10, min(100, (int) ($filters['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $sql = 'SELECT o.id' . $from . ' ORDER BY o.created_at DESC, o.id DESC';
    if ($paginate) $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

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

    return [$list, [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'pages' => max(1, (int) ceil($total / $limit)),
    ]];
}

function draftOrderFilterOptions(PDO $pdo, array $filters = []): array
{
    $statuses = ['Draft', 'Submitted', 'Confirmed', 'Approved'];
    $brands = $pdo->query("SELECT DISTINCT COALESCE(NULLIF(TRIM(brand),''), NULLIF(TRIM(what_brand),'')) brand FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.order_type='draft_procurement' HAVING brand IS NOT NULL ORDER BY brand LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);
    $creators = [];
    if (hasAnyRole(['SuperAdmin', 'ChinaAdmin', 'LebanonAdmin'])) {
        $creators = $pdo->query("SELECT DISTINCT u.id, COALESCE(NULLIF(u.full_name,''),u.email) name FROM users u JOIN orders o ON o.created_by=u.id WHERE o.order_type='draft_procurement' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    }
    $selected = [];
    foreach (['customer_id' => ['customers', 'name'], 'supplier_id' => ['suppliers', 'name']] as $key => [$table, $nameColumn]) {
        $selectedId = (int) ($filters[$key] ?? 0);
        if ($selectedId <= 0) continue;
        $stmt = $pdo->prepare("SELECT id, $nameColumn AS name FROM $table WHERE id=? LIMIT 1");
        $stmt->execute([$selectedId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $selected[$key] = $row;
    }
    return ['statuses' => $statuses, 'brands' => $brands, 'creators' => $creators, 'selected' => $selected];
}

function draftOrderListRows(PDO $pdo): array
{
    return draftOrderListQuery($pdo, [], false)[0];
}

function draftOrderRequestFilters(): array
{
    return [
        'q' => $_GET['q'] ?? '',
        'status' => $_GET['status'] ?? [],
        'customer_id' => $_GET['customer_id'] ?? null,
        'supplier_id' => $_GET['supplier_id'] ?? null,
        'goods_type' => $_GET['goods_type'] ?? '',
        'brand' => $_GET['brand'] ?? '',
        'creator_id' => $_GET['creator_id'] ?? null,
        'created_from' => $_GET['created_from'] ?? '',
        'created_to' => $_GET['created_to'] ?? '',
        'expected_from' => $_GET['expected_from'] ?? '',
        'expected_to' => $_GET['expected_to'] ?? '',
        'page' => $_GET['page'] ?? 1,
        'limit' => $_GET['limit'] ?? 20,
    ];
}

function draftOrderExportCsv(PDO $pdo, int $orderId): void
{
    $order = draftOrderFetchOrderPayload($pdo, $orderId);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="draft_order_' . $orderId . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $out = fopen('php://output', 'w');
    clmsWriteCsv($out, [clmsT('Draft Order') . ':', '#' . $order['id']]);
    $customerItems = [];
    foreach (($order['supplier_sections'] ?? []) as $section) {
        foreach (($section['items'] ?? []) as $item) {
            $customerItems[] = $item;
        }
    }
    clmsWriteCsv($out, [clmsT('Customer') . ':', OrderExcelService::formatCustomerDisplay($order, $customerItems)]);
    clmsWriteCsv($out, [clmsT('Destination Country') . ':', !empty($order['destination_country_name']) ? clmsCountryDisplayLabel($order['destination_country_name'] ?? '', $order['destination_country_code'] ?? '') : '—']);
    clmsWriteCsv($out, [clmsT('Expected Ready') . ':', $order['expected_ready_date']]);
    clmsWriteCsv($out, [clmsT('Currency') . ':', $order['currency']]);
    clmsWriteCsv($out, [clmsT('Status') . ':', clmsStatusLabel((string) $order['status'])]);
    clmsWriteCsv($out, ['']);

    foreach ($order['supplier_sections'] as $section) {
        clmsWriteCsv($out, [clmsT('Supplier') . ':', $section['supplier_name']]);
        clmsWriteCsv($out, array_map('clmsT', ['Supplier', 'Supplier Name', 'Brand', 'Materials', 'Height', 'Width', 'Length', 'What Brand', 'Good Type', 'Code', 'I.I.N', 'English Description', 'Chinese Description', 'Notes', 'HS Code', 'Pieces/Carton', 'Cartons', 'Quantity', 'Unit', 'Factory Price', 'Customer Price', 'Total Amount', 'CBM/Unit', 'Total CBM', 'Weight/Unit', 'Total Weight', 'Custom Design', 'Express Number', 'Size', 'Item Number']));
        foreach (draftOrderBuildExportRows([$section]) as $item) {
            $customDesignValue = strtolower(trim((string) ($item['custom_design_required'] ?? '')));
            $customDesignLabel = $customDesignValue === ''
                ? ''
                : (in_array($customDesignValue, ['1', 'yes', 'true'], true) ? clmsT('Yes') : clmsT('No'));
            clmsWriteCsv($out, [
                $item['supplier_id'] ?? '',
                $item['supplier_name'] ?? $section['supplier_name'] ?? '',
                $item['brand'] ?? '',
                $item['materials'] ?? '',
                $item['height'] ?? '',
                $item['width'] ?? '',
                $item['length'] ?? '',
                $item['what_brand'] ?? '',
                draftOrderCopyNormalGoodsDisplay($item['copy_normal_goods'] ?? ''),
                $item['code'] ?? '',
                $item['item_no'] ?? '',
                $item['description_en'] ?? $item['description'] ?? '',
                $item['description_cn'] ?? $item['description'] ?? '',
                $item['notes'] ?? '',
                $item['hs_code'] ?: '',
                $item['pieces_per_carton'] ?? '',
                $item['cartons'] ?? '',
                $item['quantity'] ?? '',
                $item['unit'] ?? '',
                $item['unit_price'] ?? '',
                $item['sell_price'] ?? '',
                $item['total_amount'] ?? '',
                $item['cbm'] ?? '',
                $item['total_cbm'] ?? '',
                $item['weight'] ?? '',
                $item['total_weight'] ?? '',
                $customDesignLabel,
                $item['express_number'] ?? '',
                $item['size'] ?? '',
                $item['item_number'] ?? '',
            ]);
        }
        $subtotal=array_fill(0,30,'');$subtotal[4]=clmsT('Supplier subtotal');$subtotal[21]=$section['totals']['amount'];$subtotal[23]=$section['totals']['cbm'];$subtotal[25]=$section['totals']['weight'];clmsWriteCsv($out,$subtotal);
        clmsWriteCsv($out, ['']);
    }

    $grand=array_fill(0,30,'');$grand[4]=clmsT('Grand total');$grand[21]=$order['totals']['amount'];$grand[23]=$order['totals']['cbm'];$grand[25]=$order['totals']['weight'];clmsWriteCsv($out,$grand);
    if (!empty($order['operational_costs']['lines'])) {
        clmsWriteCsv($out, ['']);
        clmsWriteCsv($out, [clmsT('Shipment Charges')]);
        clmsWriteCsv($out, array_map('clmsT', ['Type', 'Description', 'Amount', 'Currency', 'Exchange Rate', 'Base Amount', 'Base Currency', 'Responsible Payer', 'Allocation', 'Accounting Treatment']));
        foreach ($order['operational_costs']['lines'] as $cost) {
            clmsWriteCsv($out, [$cost['cost_type_label_en'] ?? $cost['cost_type_code'], $cost['description_en'] ?? $cost['description_zh'] ?? '', $cost['amount'], $cost['currency'], $cost['exchange_rate'], $cost['base_amount'], $cost['base_currency'], $cost['responsible_payer'], $cost['allocation_method'], $cost['accounting_treatment']]);
        }
        clmsWriteCsv($out, [clmsT('Shipment Charges Total'), '', '', '', '', $order['operational_costs']['base_total'], $order['operational_costs']['base_currency'], '', '', clmsT('Shipment-level; no item allocation')]);
    }
    fclose($out);
    exit;
}

function draftOrderBuildExcelEntry(PDO $pdo, int $orderId): array
{
    $order = draftOrderFetchOrderPayload($pdo, $orderId);
    $excelItems = [];

    foreach (draftOrderBuildExportRows($order['supplier_sections']) as $row) {
        $isSummary = ($row['row_type'] ?? '') === 'shared_carton_summary';
        $excelItems[] = [
            'id' => (int) ($row['source_item_id'] ?? 0),
            'product_id' => !empty($row['product_id']) ? (int) $row['product_id'] : null,
            'item_no' => $row['item_no'] ?? '',
            'item_number' => $row['item_number'] ?? null,
            'shipping_code' => '',
            'what_brand' => $row['what_brand'] ?? '',
            'brand' => $row['brand'] ?? ($row['what_brand'] ?? ''),
            'materials' => $row['materials'] ?? '',
            'copy_normal_goods' => $row['copy_normal_goods'] ?? '',
            'code' => $row['code'] ?? '',
            'express_number' => $row['express_number'] ?? '',
            'size' => $row['size'] ?? '',
            'length' => is_numeric($row['length'] ?? null) ? (float) $row['length'] : null,
            'width' => is_numeric($row['width'] ?? null) ? (float) $row['width'] : null,
            'height' => is_numeric($row['height'] ?? null) ? (float) $row['height'] : null,
            'item_length' => is_numeric($row['length'] ?? null) ? (float) $row['length'] : null,
            'item_width' => is_numeric($row['width'] ?? null) ? (float) $row['width'] : null,
            'item_height' => is_numeric($row['height'] ?? null) ? (float) $row['height'] : null,
            'description_en' => $row['description_en'] ?? $row['description'] ?? '',
            'description_cn' => $row['description_cn'] ?? $row['description'] ?? '',
            'notes' => $row['notes'] ?? '',
            'quantity' => $isSummary ? '' : (float) ($row['quantity'] ?? 0),
            'unit' => $row['unit'] ?? 'pieces',
            'cartons' => $isSummary ? (float) ($row['cartons'] ?? 0) : (is_numeric($row['cartons'] ?? null) ? (float) $row['cartons'] : ''),
            'qty_per_carton' => is_numeric($row['pieces_per_carton'] ?? null) ? (float) $row['pieces_per_carton'] : '',
            'declared_cbm' => is_numeric($row['total_cbm'] ?? null) ? (float) $row['total_cbm'] : 0.0,
            'declared_weight' => is_numeric($row['total_weight'] ?? null) ? (float) $row['total_weight'] : 0.0,
            'unit_price' => is_numeric($row['unit_price'] ?? null) ? (float) $row['unit_price'] : null,
            'sell_price' => is_numeric($row['sell_price'] ?? null) ? (float) $row['sell_price'] : null,
            'supplier_id' => $row['supplier_id'] ?? null,
            'supplier_name' => $row['supplier_name'] ?? '',
            'image_paths' => $row['image_paths'] ?? [],
            'dimensions_scope' => $isSummary ? 'carton' : 'piece',
            'product_dimensions_scope' => $isSummary ? 'carton' : 'piece',
        ];
    }

    $customerName = preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim((string) ($order['customer_name'] ?? 'customer')));
    $customerName = trim((string) $customerName, '_') ?: 'customer';
    $filename = 'draft_order_' . $orderId . '_' . $customerName . '_' . date('Ymd_His') . '.xlsx';
    return ['order' => $order, 'items' => $excelItems, 'filename' => $filename];
}

function draftOrderExportXlsx(PDO $pdo, int $orderId): void
{
    $entry = draftOrderBuildExcelEntry($pdo, $orderId);
    (new OrderExcelService($pdo))->exportOrder($entry['order'], $entry['items'], $entry['filename']);
}

function draftOrderImportCellString($value): string
{
    $value = str_replace("\xC2\xA0", ' ', draftOrderImportRawCellString($value));
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    return trim($value);
}

function draftOrderImportRawCellString($value): string
{
    if ($value === null) {
        return '';
    }
    return (string) $value;
}

function draftOrderImportRowIsBlank(array $row): bool
{
    foreach ($row as $index => $value) {
        if (!is_int($index)) {
            continue;
        }
        if (draftOrderImportCellString($value) !== '') {
            return false;
        }
    }
    return true;
}

function draftOrderImportHeaderKey($value): string
{
    $value = strtolower(draftOrderImportCellString($value));
    $value = str_replace(['&', '+'], ' and ', $value);
    $value = preg_replace('/[\s_\-\/\\\\]+/u', ' ', $value) ?? $value;
    return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
}

function draftOrderImportColumnAliases(): array
{
    return [
        'photo' => ['photo', 'image', 'picture', 'itemphoto', 'productimage', 'productphoto'],
        'photo_count' => ['photocount'],
        'brand' => ['brand', 'brandname'],
        'what_brand' => ['whatbrand', 'whatebrand'],
        'materials' => ['material', 'materials'],
        'copy_normal_goods' => ['goodtype', 'goodstype', 'goodsclassification', 'goodscategory', 'itemtype', 'producttype', 'copynormalgoods', 'copynormal', 'copygoods', 'normalgoods', 'dangerousgoods', 'copyornormalgoods'],
        'code' => ['code', 'serialcode', 'serialno', 'serialnumber', 'sku', 'itemcode', 'skucode', 'skuitemcode'],
        'express_number' => ['expressnumber', 'expressno', 'express', 'trackingnumber', 'trackingno', 'couriernumber', 'waybill', 'waybillnumber'],
        'size' => ['size', 'outsidecartonsize', 'cartonsize', 'outercartonsize'],
        'length' => ['length', 'lenght', 'l'],
        'width' => ['width', 'w'],
        'height' => ['height', 'h'],
        'item_no' => ['iin', 'internalitemnumber', 'itemno', 'no', 'lineno', 'line'],
        'item_number' => ['itemnumber', 'packinglistitemnumber', 'packinglistreference'],
        'supplier_name' => ['supplier', 'suppliername', 'factoryname', 'factory', 'vendorname'],
        'supplier_code' => ['suppliercode', 'supplierno'],
        'supplier_id' => ['supplierid'],
        'description' => ['description', 'productnames', 'productname', 'productnamesdescription', 'productdescription', 'names', 'itemname'],
        'description_en' => ['englishitemname', 'englishname', 'englishdescription', 'descriptionen', 'endescription'],
        'description_cn' => ['chineseitemname', 'chinesename', 'chinesedescription', 'descriptioncn', 'cndescription'],
        'notes' => ['notesdescription', 'notes', 'note', 'remarks', 'remark'],
        'hs_code' => ['hscode', 'optionalhscode', 'hs'],
        'unit' => ['unit', 'units', 'uom'],
        'pieces_per_carton' => ['piecescarton', 'piecespercarton', 'pcscarton', 'pcspercarton', 'qtyctn', 'qtyperctn', 'qtycarton', 'qtypercarton', 'quantitypercarton'],
        'cartons' => ['cartons', 'carton', 'totalctns', 'totalcartons', 'ctn', 'ctns'],
        'quantity' => ['quantity', 'totalqty', 'qty', 'totalquantity'],
        'factory_price' => ['factoryprice', 'buyprice', 'cost', 'supplierprice'],
        'customer_price' => ['customerprice', 'customer', 'sellprice', 'sellingprice'],
        'unit_price' => ['unitprice', 'price'],
        'total_amount' => ['totalamount', 'amounttotal', 'total', 'amount'],
        'cbm' => ['cbm', 'cbmunit', 'cbmperunit', 'm3', 'volume'],
        'total_cbm' => ['totalcbm', 'cbmtotal', 'declaredcbm'],
        'weight' => ['weight', 'kg', 'grossweight', 'weightunit', 'gwkg', 'gw', 'weightkg', 'unitweight'],
        'total_weight' => ['totalweight', 'totalgw', 'weighttotal', 'declaredweight'],
        'custom_design_required' => ['customdesign', 'design'],
        'custom_design_note' => ['customdesignnote', 'designnote'],
    ];
}

function draftOrderImportOptionalColumnLabels(): array
{
    return [
        'photo' => 'Photo',
        'brand' => 'Brand',
        'materials' => 'Materials',
        'copy_normal_goods' => 'Good Type',
        'height' => 'Height',
        'width' => 'Width',
        'length' => 'Length',
        'express_number' => 'Express Number',
        'hs_code' => 'HS Code',
        'factory_price' => 'Factory Price',
        'customer_price' => 'Customer Price',
        'total_amount' => 'Total Amount',
        'cbm' => 'CBM',
        'weight' => 'Weight',
        'custom_design_required' => 'Custom Design',
        'custom_design_note' => 'Custom Design Note',
    ];
}

function draftOrderImportHeaderMap(array $row): array
{
    $aliases = draftOrderImportColumnAliases();
    $map = [];

    foreach ($row as $index => $label) {
        if (!is_int($index)) {
            continue;
        }
        $key = draftOrderImportHeaderKey($label);
        if ($key === '') {
            continue;
        }
        $matched = false;
        foreach ($aliases as $field => $candidates) {
            if (in_array($key, $candidates, true)) {
                if(array_key_exists($field,$map) && !in_array($field,['description','description_en','description_cn','notes'],true))jsonError('Duplicate import column: '.$field,422);
                if (in_array($field, ['description', 'description_en', 'description_cn', 'notes'], true)) {
                    $map['_description_indexes'][] = $index;
                    if (!array_key_exists('description', $map)) {
                        $map['description'] = $index;
                    }
                }
                if (!array_key_exists($field, $map)) {
                    $map[$field] = $index;
                }
                $matched = true;
                break;
            }
        }
        if (!$matched && preg_match('/^(description|desc|productname|productnames|productdescription|itemname|englishitemname|chineseitemname|name|names|notesdescription)[a-z0-9]*$/', $key) === 1) {
            $map['_description_indexes'][] = $index;
            if (!array_key_exists('description', $map)) {
                $map['description'] = $index;
            }
        }
    }

    if (!empty($map['_description_indexes'])) {
        $map['_description_indexes'] = array_values(array_unique(array_map('intval', $map['_description_indexes'])));
    }

    return $map;
}

function draftOrderImportRowMeta(array $row, string $key, $default = null)
{
    return array_key_exists($key, $row) ? $row[$key] : $default;
}

function draftOrderImportFieldList(array $row, array $map, string $field): array
{
    $indexes = [];
    if ($field === 'description' && !empty($map['_description_indexes']) && is_array($map['_description_indexes'])) {
        $indexes = $map['_description_indexes'];
    } elseif (array_key_exists($field, $map)) {
        $indexes = [$map[$field]];
    }

    $values = [];
    foreach ($indexes as $index) {
        if (!is_int($index)) {
            continue;
        }
        $value = draftOrderImportCellString($row[$index] ?? '');
        if ($value !== '' && !in_array($value, $values, true)) {
            $values[] = $value;
        }
    }

    return $values;
}

function draftOrderImportRowHasMappedItemData(array $row, array $map): bool
{
    $fields = [
        'photo',
        'item_no',
        'description',
        'description_en',
        'description_cn',
        'what_brand',
        'brand',
        'materials',
        'copy_normal_goods',
        'code',
        'express_number',
        'size',
        'length',
        'width',
        'height',
        'quantity',
        'cartons',
        'pieces_per_carton',
        'factory_price',
        'customer_price',
        'unit_price',
        'total_amount',
        'cbm',
        'total_cbm',
        'weight',
        'total_weight',
        'hs_code',
        'notes',
        'custom_design_required',
        'custom_design_note',
    ];

    foreach ($fields as $field) {
        foreach (draftOrderImportFieldList($row, $map, $field) as $value) {
            if (draftOrderImportCellString($value) !== '') {
                return true;
            }
        }
    }

    return !empty(draftOrderImportRowPhotoPaths($row, $map));
}

function draftOrderImportRowPhotoPaths(array $row, array $map = []): array
{
    $paths = [];
    $worksheetImages = draftOrderImportRowMeta($row, '__worksheet_images', []);
    if (is_array($worksheetImages) && $worksheetImages) {
        $photoColumn = isset($map['photo']) && is_int($map['photo']) ? $map['photo'] + 1 : null;
        $matched = [];
        if ($photoColumn !== null) {
            foreach ($worksheetImages as $image) {
                if (!is_array($image)) {
                    continue;
                }
                $startCol = (int) ($image['start_col'] ?? 0);
                $endCol = (int) ($image['end_col'] ?? $startCol);
                if ($startCol <= $photoColumn && $photoColumn <= max($startCol, $endCol)) {
                    $matched[] = $image;
                }
            }
        }
        foreach ($matched ?: $worksheetImages as $image) {
            if (is_array($image) && !empty($image['path'])) {
                $paths[] = (string) $image['path'];
            }
        }
    }

    $legacyPaths = draftOrderImportRowMeta($row, '__photo_paths', []);
    if (is_array($legacyPaths)) {
        foreach ($legacyPaths as $path) {
            $paths[] = (string) $path;
        }
    }

    return array_values(array_unique(array_filter(array_map('strval', $paths), static fn(string $path): bool => trim($path) !== '')));
}

function draftOrderImportImageExtensionFromMime(string $mime): string
{
    return [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/bmp' => 'bmp',
        'image/x-ms-bmp' => 'bmp',
        'image/x-bmp' => 'bmp',
    ][strtolower($mime)] ?? 'png';
}

function draftOrderImportImageExtensionFromPath(string $path): string
{
    $ext = strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'jfif', 'png', 'gif', 'webp', 'bmp'], true)
        ? ($ext === 'jpeg' ? 'jpg' : $ext)
        : 'png';
}

function draftOrderImportStoreImageBytes(string $bytes, string $preferredExt = 'png'): ?string
{
    if ($bytes === '' || strlen($bytes)>8388608) {
        return null;
    }

    $imageInfo = @getimagesizefromstring($bytes);
    if (!is_array($imageInfo) || $imageInfo[0] * $imageInfo[1] > 25000000) {
        return null;
    }

    $mime = (string) ($imageInfo['mime'] ?? '');
    $ext = $mime !== '' ? draftOrderImportImageExtensionFromMime($mime) : strtolower($preferredExt);
    $allowed = function_exists('clmsUploadAllowedImageExtensions')
        ? clmsUploadAllowedImageExtensions()
        : ['jpg', 'jpeg', 'png', 'webp', 'jfif', 'gif', 'bmp'];
    if (!in_array($ext, $allowed, true)) {
        return null;
    }

    $uploadDir = dirname(__DIR__, 2) . '/uploads/';
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true)) {
        return null;
    }

    require_once dirname(__DIR__,2).'/services/UploadAccessService.php';
    UploadAccessService::requireSchema(getDb());
    $filename = 'draft_import_' . hash('sha256', (string)getAuthUserId().'|'.$bytes) . '.' . $ext;
    $path = $uploadDir . $filename;
    if(is_file($path)){UploadAccessService::register(getDb(),'uploads/'.$filename,(int)getAuthUserId());return 'uploads/'.$filename;}
    $handle=@fopen($path,'x');
    if(!$handle)return is_file($path)?'uploads/'.$filename:null;
    $written=fwrite($handle,$bytes);fclose($handle);
    $GLOBALS['draftImportNewFiles'][]=$path;
    if ($written !== strlen($bytes)) {
        return null;
    }

    try { UploadAccessService::register(getDb(),'uploads/'.$filename,(int)getAuthUserId()); }
    catch(Throwable $e){@unlink($path);throw $e;}
    return 'uploads/' . $filename;
}

function draftOrderImportStoreWorksheetDrawing($drawing): ?string
{
    try {
        $bytes = null;
        $ext = 'png';
        if ($drawing instanceof SpreadsheetMemoryDrawing) {
            $resource = $drawing->getImageResource();
            if (!$resource) {
                return null;
            }
            $ext = draftOrderImportImageExtensionFromMime((string) $drawing->getMimeType());
            ob_start();
            $renderingFunction = $drawing->getRenderingFunction();
            $renderingFunction($resource);
            $bytes = ob_get_clean();
        } elseif ($drawing instanceof SpreadsheetDrawing) {
            $path = (string) $drawing->getPath();
            if ($path === '') {
                return null;
            }
            $ext = draftOrderImportImageExtensionFromPath($path);
            $bytes = @file_get_contents($path);
        }

        if (!is_string($bytes) || $bytes === '') {
            return null;
        }
        return draftOrderImportStoreImageBytes($bytes, $ext);
    } catch (Throwable $e) {
        return null;
    }
}

function draftOrderImportDrawingRange($drawing): array
{
    $startRow = 0;
    $endRow = 0;
    $startCol = 0;
    $endCol = 0;
    $coordinate = '';
    try {
        $coordinate = (string) $drawing->getCoordinates();
        [$column, $startRow] = SpreadsheetCoordinate::coordinateFromString($coordinate);
        $startCol = SpreadsheetCoordinate::columnIndexFromString($column);
        $endCoordinate = method_exists($drawing, 'getCoordinates2') ? (string) $drawing->getCoordinates2() : '';
        if ($endCoordinate !== '') {
            [$endColumn, $endRow] = SpreadsheetCoordinate::coordinateFromString($endCoordinate);
            $endCol = SpreadsheetCoordinate::columnIndexFromString($endColumn);
        }
    } catch (Throwable $e) {
        return [0, 0, 0, 0, ''];
    }

    $startRow = (int) $startRow;
    $endRow = (int) $endRow;
    $startCol = (int) $startCol;
    $endCol = (int) $endCol;
    if ($endRow < $startRow) {
        $endRow = $startRow;
    }
    if ($endCol < $startCol) {
        $endCol = $startCol;
    }

    if ($endRow === $startRow && method_exists($drawing, 'getHeight')) {
        $height = max(0, (int) $drawing->getHeight());
        if ($height > 0) {
            $estimatedRows = max(1, (int) ceil($height / 90));
            $endRow = $startRow + $estimatedRows - 1;
        }
    }
    if ($endCol === $startCol && method_exists($drawing, 'getWidth')) {
        $width = max(0, (int) $drawing->getWidth());
        if ($width > 0) {
            $estimatedColumns = max(1, (int) ceil($width / 90));
            $endCol = $startCol + $estimatedColumns - 1;
        }
    }

    return [$startRow, $endRow, $startCol, $endCol, $coordinate];
}

function draftOrderImportWorksheetImages($sheet, array &$warnings = []): array
{
    $images = [];
    foreach ($sheet->getDrawingCollection() as $drawing) {
        [$startRow, $endRow, $startCol, $endCol, $coordinate] = draftOrderImportDrawingRange($drawing);
        if ($startRow <= 0) {
            continue;
        }
        $path = draftOrderImportStoreWorksheetDrawing($drawing);
        if (!$path) {
            $warnings[] = 'Image anchored at ' . ($coordinate ?: ('row ' . $startRow)) . ' could not be imported.';
            continue;
        }
        $images[] = [
            'start_row' => $startRow,
            'end_row' => max($startRow, $endRow),
            'start_col' => $startCol,
            'end_col' => max($startCol, $endCol),
            'coordinate' => $coordinate,
            'path' => $path,
        ];
    }

    return $images;
}

function draftOrderImportAttachWorksheetImages(array &$rows, array $images): void
{
    if (!$images) {
        return;
    }

    foreach ($images as $image) {
        $targetIndex = null;
        $fallbackIndex = null;
        foreach ($rows as $index => $row) {
            $rowNumber = (int) draftOrderImportRowMeta($row, '__row_number', 0);
            if ($rowNumber < (int) $image['start_row'] || $rowNumber > (int) $image['end_row']) {
                continue;
            }
            $fallbackIndex ??= $index;
            if (!draftOrderImportRowIsBlank($row)) {
                $targetIndex = $index;
                break;
            }
        }
        $targetIndex ??= $fallbackIndex;
        if ($targetIndex === null) {
            continue;
        }
        $rows[$targetIndex]['__worksheet_images'] = array_values(array_merge(
            $rows[$targetIndex]['__worksheet_images'] ?? [],
            [$image]
        ));
        $rows[$targetIndex]['__photo_paths'] = array_values(array_unique(array_merge(
            $rows[$targetIndex]['__photo_paths'] ?? [],
            [$image['path']]
        )));
    }
}

function draftOrderImportLooksLikeHeader(array $row): bool
{
    $map = draftOrderImportHeaderMap($row);
    $fieldCount = count(array_filter(
        array_keys($map),
        static fn(string $key): bool => $key !== '_description_indexes'
    ));
    $hasItemIdentity = isset($map['description'])
        || isset($map['description_en'])
        || isset($map['description_cn'])
        || isset($map['code'])
        || isset($map['item_no']);

    return $fieldCount >= 2
        && $hasItemIdentity
        && (
            isset($map['item_no'])
            || isset($map['cartons'])
            || isset($map['pieces_per_carton'])
            || isset($map['quantity'])
            || isset($map['supplier_name'])
            || isset($map['supplier_code'])
            || isset($map['photo'])
            || isset($map['code'])
        );
}

function draftOrderImportLooksLikeTranslatedHeader(array $row): bool
{
    $knownLabels = [
        '供应商', '供应商名称', '品牌', '材质', '什么牌子', '货物类型', '编号', '照片',
        '货号', '英文描述', '中文描述', '高度', '宽度', '长度', '总箱数', '每箱数量',
        '总数量', '单位', '单价', '总金额', '总 CBM', '毛重KG', '总毛重', '快递单号',
        '尺寸', 'HS编码', '备注',
    ];
    $matches = 0;
    foreach ($row as $index => $value) {
        if (!is_int($index) || is_array($value) || is_object($value)) {
            continue;
        }
        if (in_array(trim(draftOrderImportCellString($value)), $knownLabels, true)) {
            $matches++;
        }
    }
    return $matches >= 3;
}

function draftOrderImportExcelEnvironmentMessage(string $ext): ?string
{
    if (!class_exists(IOFactory::class)) {
        return 'Excel import is not available because the spreadsheet library is missing on this server.';
    }

    if ($ext === 'xlsx') {
        if (!class_exists('ZipArchive')) {
            return 'XLSX import is not available because the PHP zip extension is disabled on this server. Enable ZipArchive / php-zip, or import the file as CSV.';
        }
        if (!class_exists('XMLReader') || !class_exists('DOMDocument') || !function_exists('simplexml_load_string')) {
            return 'XLSX import is not available because required PHP XML extensions are disabled on this server. Enable xml, xmlreader, dom, and simplexml, or import the file as CSV.';
        }
    }

    return null;
}

function draftOrderImportExcelEnvironmentContext(string $ext): array
{
    return [
        'extension' => $ext,
        'php_version' => PHP_VERSION,
        'ziparchive' => class_exists('ZipArchive'),
        'xmlreader' => class_exists('XMLReader'),
        'domdocument' => class_exists('DOMDocument'),
        'simplexml' => function_exists('simplexml_load_string'),
        'zlib' => extension_loaded('zlib'),
        'spreadsheet_iofactory' => class_exists(IOFactory::class),
    ];
}

function draftOrderImportCreateExcelReader(string $ext, bool $includeImages)
{
    $readerType = $ext === 'xls' ? 'Xls' : 'Xlsx';
    $reader = IOFactory::createReader($readerType);
    if (method_exists($reader, 'setReadDataOnly')) {
        $reader->setReadDataOnly(!$includeImages);
    }
    return $reader;
}

function draftOrderImportCopyUploadForReader(string $tmpName, string $ext): array
{
    $candidates = [
        dirname(__DIR__, 2) . '/cache/imports',
        rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR),
    ];

    foreach ($candidates as $dir) {
        if ($dir === '') {
            continue;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            continue;
        }
        if (!is_writable($dir)) {
            continue;
        }

        $path = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'draft_import_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (@copy($tmpName, $path)) {
            return [$path, true];
        }
    }

    return [$tmpName, false];
}

function draftOrderImportExcelSignatureMessage(string $path, string $ext): ?string
{
    $bytes = @file_get_contents($path, false, null, 0, 12);
    if (!is_string($bytes) || $bytes === '') {
        return 'Could not read the uploaded Excel file from the server temporary folder. Upload it again.';
    }

    $hex = strtoupper(bin2hex($bytes));
    if ($ext === 'xlsx' && strncmp($hex, '504B', 4) !== 0) {
        if (strncmp($hex, '3C21444F4354595045', 18) === 0 || strncmp($hex, '3C68746D6C', 10) === 0) {
            return 'The uploaded .xlsx file is an HTML page, not an Excel workbook. Download the template again and make sure the download page did not save an error page.';
        }
        return 'The uploaded .xlsx file does not look like a valid Excel workbook. Download the template again or save it as CSV.';
    }

    return null;
}

function draftOrderImportZipGet(ZipArchive $zip, string $path): ?string
{
    $path = ltrim(str_replace('\\', '/', $path), '/');
    $index = $zip->locateName($path);
    if ($index === false && defined('ZipArchive::FL_NOCASE')) {
        $index = $zip->locateName($path, ZipArchive::FL_NOCASE);
    }
    if ($index === false) {
        return null;
    }
    $content = $zip->getFromIndex($index);
    return is_string($content) ? $content : null;
}

function draftOrderImportZipHas(ZipArchive $zip, string $path): bool
{
    $path = ltrim(str_replace('\\', '/', $path), '/');
    if ($zip->locateName($path) !== false) {
        return true;
    }
    return defined('ZipArchive::FL_NOCASE') && $zip->locateName($path, ZipArchive::FL_NOCASE) !== false;
}

function draftOrderImportNormalizeZipTarget(string $baseDir, string $target): string
{
    $target = str_replace('\\', '/', trim($target));
    if ($target === '') {
        return '';
    }
    if ($target[0] === '/') {
        $path = ltrim($target, '/');
    } elseif (strpos($target, 'xl/') === 0) {
        $path = $target;
    } else {
        $path = trim($baseDir, '/') . '/' . $target;
    }

    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }

    return implode('/', $parts);
}

function draftOrderImportXml(string $xml): ?SimpleXMLElement
{
    $flags = LIBXML_NONET;
    if (defined('LIBXML_COMPACT')) {
        $flags |= LIBXML_COMPACT;
    }
    $parsed = @simplexml_load_string($xml, SimpleXMLElement::class, $flags);
    return $parsed instanceof SimpleXMLElement ? $parsed : null;
}

function draftOrderImportXmlTexts(SimpleXMLElement $xml, string $localName): array
{
    $nodes = $xml->xpath('.//*[local-name()="' . $localName . '"]');
    if (!is_array($nodes)) {
        return [];
    }

    return array_map(static fn($node): string => (string) $node, $nodes);
}

function draftOrderImportXlsxSharedStrings(ZipArchive $zip): array
{
    $xml = draftOrderImportZipGet($zip, 'xl/sharedStrings.xml');
    if ($xml === null || $xml === '') {
        return [];
    }

    $strings = [];
    $reader = new XMLReader();
    if (!@$reader->XML($xml, null, LIBXML_NONET)) {
        return [];
    }

    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'si') {
            continue;
        }
        $outer = $reader->readOuterXML();
        if (!is_string($outer) || $outer === '') {
            $strings[] = '';
            continue;
        }
        $si = draftOrderImportXml($outer);
        if (!$si) {
            $strings[] = '';
            continue;
        }
        $strings[] = implode('', draftOrderImportXmlTexts($si, 't'));
    }
    $reader->close();

    return $strings;
}

function draftOrderImportXlsxRelationships(ZipArchive $zip, string $relsPath): array
{
    $xml = draftOrderImportZipGet($zip, $relsPath);
    if ($xml === null || $xml === '') {
        return [];
    }

    $rels = draftOrderImportXml($xml);
    if (!$rels) {
        return [];
    }

    $baseDir = str_replace('\\', '/', preg_replace('#/_rels/[^/]+\.rels$#', '', $relsPath) ?? $relsPath);

    $items = [];
    foreach ($rels->children() as $rel) {
        $id = (string) ($rel['Id'] ?? '');
        if ($id === '') {
            continue;
        }
        $target = (string) ($rel['Target'] ?? '');
        $items[$id] = [
            'type' => (string) ($rel['Type'] ?? ''),
            'target' => draftOrderImportNormalizeZipTarget($baseDir, $target),
        ];
    }

    return $items;
}

function draftOrderImportXlsxFirstWorksheetPath(ZipArchive $zip): ?string
{
    if (draftOrderImportZipHas($zip, 'xl/worksheets/sheet1.xml')) {
        return 'xl/worksheets/sheet1.xml';
    }

    $workbookXml = draftOrderImportZipGet($zip, 'xl/workbook.xml');
    if ($workbookXml === null) {
        return null;
    }
    $workbook = draftOrderImportXml($workbookXml);
    if (!$workbook) {
        return null;
    }

    $sheetNodes = $workbook->xpath('//*[local-name()="sheet"]');
    if (!is_array($sheetNodes) || !$sheetNodes) {
        return null;
    }
    $sheet = $sheetNodes[0];
    $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
    $relId = (string) ($attrs['id'] ?? '');
    $rels = draftOrderImportXlsxRelationships($zip, 'xl/_rels/workbook.xml.rels');
    if ($relId !== '' && !empty($rels[$relId]['target'])) {
        return $rels[$relId]['target'];
    }

    foreach ($rels as $rel) {
        if (strpos((string) ($rel['type'] ?? ''), '/worksheet') !== false && !empty($rel['target'])) {
            return $rel['target'];
        }
    }

    return null;
}

function draftOrderImportColumnIndexFromRef(string $ref): int
{
    if (!preg_match('/^([A-Z]+)/i', $ref, $matches)) {
        return 0;
    }

    $letters = strtoupper($matches[1]);
    $index = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }

    return $index;
}

function draftOrderImportXlsxCellValue(SimpleXMLElement $cell, array $sharedStrings): string
{
    $type = (string) ($cell['t'] ?? '');
    $texts = draftOrderImportXmlTexts($cell, 't');
    $values = draftOrderImportXmlTexts($cell, 'v');
    $raw = $values[0] ?? '';
    if($cell->xpath('./*[local-name()="f"]') && !$values)throw new InvalidArgumentException('A workbook formula has no saved result. Recalculate and save the workbook first.');

    if ($type === 's') {
        $index = is_numeric($raw) ? (int) $raw : -1;
        return $sharedStrings[$index] ?? '';
    }
    if ($type === 'inlineStr') {
        return implode('', $texts);
    }
    if ($type === 'b') {
        return $raw === '1' ? 'TRUE' : 'FALSE';
    }

    return $raw !== '' ? $raw : implode('', $texts);
}

function draftOrderImportXlsxReadSheetRows(ZipArchive $zip, string $sheetPath, int $maxRows, array $sharedStrings, array &$readMeta): array
{
    $xml = draftOrderImportZipGet($zip, $sheetPath);
    if ($xml === null || $xml === '') {
        throw new RuntimeException('Worksheet XML was not found.');
    }

    $rows = [];
    $maxColumnIndex = 0;
    $reader = new XMLReader();
    if (!@$reader->XML($xml, null, LIBXML_NONET)) {
        throw new RuntimeException('Worksheet XML could not be opened.');
    }

    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
            continue;
        }
        $rowNumber = (int) $reader->getAttribute('r');
        if($rowNumber>$maxRows)throw new InvalidArgumentException('Workbook exceeds the supported row count');
        $outer = $reader->readOuterXML();
        if (!is_string($outer) || $outer === '') {
            continue;
        }
        $rowXml = draftOrderImportXml($outer);
        if (!$rowXml) {
            continue;
        }

        $row = [];
        $nextColumn = 1;
        $cells = $rowXml->xpath('./*[local-name()="c"]');
        if (is_array($cells)) {
            foreach ($cells as $cell) {
                $ref = (string) ($cell['r'] ?? '');
                $columnIndex = draftOrderImportColumnIndexFromRef($ref);
                if ($columnIndex <= 0) {
                    $columnIndex = $nextColumn;
                }
                $nextColumn = $columnIndex + 1;
                $maxColumnIndex = max($maxColumnIndex, $columnIndex);
                if ($columnIndex > 60) {
                    throw new InvalidArgumentException("Workbook exceeds the supported column count");
                }
                $row[$columnIndex - 1] = draftOrderImportCellString(draftOrderImportXlsxCellValue($cell, $sharedStrings));
            }
        }

        $row['__row_number'] = $rowNumber > 0 ? $rowNumber : (count($rows) + 1);
        $rows[] = $row;
        if (count($rows) > $maxRows) {
            $reader->close();
            jsonError('Import file has too many rows. Keep it under ' . $maxRows . ' rows.', 400);
        }
    }
    $reader->close();

    $readMeta['sheet_rows_seen'] = count($rows);
    $readMeta['sheet_columns_seen'] = min(60, $maxColumnIndex);

    return $rows;
}

function draftOrderImportXlsxWorksheetDrawingPaths(ZipArchive $zip, string $sheetPath): array
{
    $sheetDir = dirname($sheetPath);
    $relsPath = $sheetDir . '/_rels/' . basename($sheetPath) . '.rels';
    $rels = draftOrderImportXlsxRelationships($zip, $relsPath);
    $paths = [];
    foreach ($rels as $rel) {
        if (strpos((string) ($rel['type'] ?? ''), '/drawing') !== false && !empty($rel['target'])) {
            $paths[] = $rel['target'];
        }
    }

    return array_values(array_unique($paths));
}

function draftOrderImportXlsxDrawingImageRels(ZipArchive $zip, string $drawingPath): array
{
    $drawingDir = dirname($drawingPath);
    $relsPath = $drawingDir . '/_rels/' . basename($drawingPath) . '.rels';
    $rels = draftOrderImportXlsxRelationships($zip, $relsPath);
    $images = [];
    foreach ($rels as $id => $rel) {
        $type = (string) ($rel['type'] ?? '');
        if ((strpos($type, '/image') !== false || preg_match('/\.(png|jpe?g|jfif|gif|webp|bmp)$/i', (string) ($rel['target'] ?? ''))) && !empty($rel['target'])) {
            $images[$id] = $rel['target'];
        }
    }

    return $images;
}

function draftOrderImportXlsxAnchorNodeText(SimpleXMLElement $anchor, string $parent, string $child): ?int
{
    $nodes = $anchor->xpath('./*[local-name()="' . $parent . '"]/*[local-name()="' . $child . '"]');
    if (!is_array($nodes) || !$nodes) {
        return null;
    }
    $value = (string) $nodes[0];
    return is_numeric($value) ? (int) $value : null;
}

function draftOrderImportXlsxDrawingImages(ZipArchive $zip, string $drawingPath, array &$warnings): array
{
    $xml = draftOrderImportZipGet($zip, $drawingPath);
    if ($xml === null || $xml === '') {
        return [];
    }

    $imageRels = draftOrderImportXlsxDrawingImageRels($zip, $drawingPath);
    if (!$imageRels) {
        return [];
    }

    $anchors = [];
    if (preg_match_all('/<[^>]*(?:twoCellAnchor|oneCellAnchor|absoluteAnchor)\b[\s\S]*?<\/[^>]*(?:twoCellAnchor|oneCellAnchor|absoluteAnchor)>/i', $xml, $matches)) {
        $anchors = $matches[0];
    }

    $images = [];
    foreach ($anchors as $anchor) {
        if (!preg_match('/\b(?:r:)?embed="([^"]+)"/i', $anchor, $relMatch)
            && !preg_match('/\b(?:r:)?link="([^"]+)"/i', $anchor, $relMatch)) {
            continue;
        }
        $relId = (string) ($relMatch[1] ?? '');
        if ($relId === '' || empty($imageRels[$relId])) {
            continue;
        }

        $mediaPath = $imageRels[$relId];
        $bytes = draftOrderImportZipGet($zip, $mediaPath);
        if ($bytes === null || $bytes === '') {
            $warnings[] = 'Embedded image "' . basename($mediaPath) . '" could not be read from the workbook.';
            continue;
        }

        $storedPath = draftOrderImportStoreImageBytes($bytes, draftOrderImportImageExtensionFromPath($mediaPath));
        if (!$storedPath) {
            $warnings[] = 'Embedded image "' . basename($mediaPath) . '" could not be imported.';
            continue;
        }

        $startCol = 1;
        $startRow = 1;
        $endCol = 1;
        $endRow = 1;
        if (preg_match('/<[^>]*from\b[^>]*>[\s\S]*?<[^>]*col\b[^>]*>(\d+)<\/[^>]*col>[\s\S]*?<[^>]*row\b[^>]*>(\d+)<\/[^>]*row>/i', $anchor, $fromMatch)) {
            $startCol = ((int) $fromMatch[1]) + 1;
            $startRow = ((int) $fromMatch[2]) + 1;
        }
        if (preg_match('/<[^>]*to\b[^>]*>[\s\S]*?<[^>]*col\b[^>]*>(\d+)<\/[^>]*col>[\s\S]*?<[^>]*row\b[^>]*>(\d+)<\/[^>]*row>/i', $anchor, $toMatch)) {
            $endCol = ((int) $toMatch[1]) + 1;
            $endRow = ((int) $toMatch[2]) + 1;
        } else {
            $endCol = $startCol;
            $endRow = $startRow;
        }
        $images[] = [
            'start_row' => max(1, $startRow),
            'end_row' => max($startRow, $endRow),
            'start_col' => max(1, $startCol),
            'end_col' => max($startCol, $endCol),
            'coordinate' => 'R' . max(1, $startRow) . 'C' . max(1, $startCol),
            'path' => $storedPath,
        ];
    }

    return $images;
}

function draftOrderImportReadRowsFromXlsxFast(string $path, int $maxRows, array &$readWarnings, array &$readMeta): ?array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return null;
    }

    try {
        $sheetPath = draftOrderImportXlsxFirstWorksheetPath($zip);
        if (!$sheetPath) {
            return null;
        }

        $sharedStrings = draftOrderImportXlsxSharedStrings($zip);
        $rows = draftOrderImportXlsxReadSheetRows($zip, $sheetPath, $maxRows, $sharedStrings, $readMeta);

        $drawingPaths = draftOrderImportXlsxWorksheetDrawingPaths($zip, $sheetPath);
        $readMeta['images_found'] = 0;
        $readMeta['images_imported'] = 0;
        if ($drawingPaths) {
            $images = [];
            foreach ($drawingPaths as $drawingPath) {
                $images = array_merge($images, draftOrderImportXlsxDrawingImages($zip, $drawingPath, $readWarnings));
            }
            $readMeta['images_found'] = count($images);
            $readMeta['images_imported'] = count($images);
            draftOrderImportAttachWorksheetImages($rows, $images);
        }

        $readMeta['reader_mode'] = 'fast_xlsx_xml';
        return $rows;
    } finally {
        $zip->close();
    }
}

function draftOrderImportReadRowsFromSpreadsheet($spreadsheet, int $maxRows, bool $includeImages, array &$readWarnings = [], array &$readMeta = []): array
{
    try {
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestDataRow();
        if($highestRow>$maxRows)throw new InvalidArgumentException('Workbook exceeds the supported row count');
        $highestColumn = $sheet->getHighestDataColumn();
        $highestColumnIndex = SpreadsheetCoordinate::columnIndexFromString($highestColumn);
        if($highestColumnIndex>60)throw new InvalidArgumentException("Workbook exceeds the supported column count");
        $readMeta['sheet_rows_seen'] = $highestRow;
        $readMeta['sheet_columns_seen'] = $highestColumnIndex;

        $rows = [];
        for ($rowNumber = 1; $rowNumber <= $highestRow; $rowNumber++) {
            $row = [];
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $cell=$sheet->getCellByColumnAndRow($col,$rowNumber);
                // Cached results preserve visible values without evaluating workbook formulas.
                $value=$cell->getDataType()==='f'?$cell->getOldCalculatedValue():$cell->getValue();
                if($cell->getDataType()==='f'&&$value===null)throw new InvalidArgumentException('A workbook formula has no saved result. Recalculate and save the workbook first.');
                $row[]=draftOrderImportRawCellString(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::toFormattedString($value,$cell->getStyle()->getNumberFormat()->getFormatCode()));
            }
            $row['__row_number'] = $rowNumber;
            $rows[] = $row;
        }

        if ($includeImages) {
            $images = draftOrderImportWorksheetImages($sheet, $readWarnings);
            $readMeta['images_found'] = count($images);
            $readMeta['images_imported'] = count($images);
            draftOrderImportAttachWorksheetImages($rows, $images);
        }

        return $rows;
    } finally {
        if (is_object($spreadsheet) && method_exists($spreadsheet, 'disconnectWorksheets')) {
            $spreadsheet->disconnectWorksheets();
        }
    }
}

function draftOrderImportLogExcelReadFailure(
    string $requestId,
    string $name,
    array $file,
    string $tmpName,
    string $ext,
    bool $includeImages,
    Throwable $e
): void {
    logClms('draft_order_import_excel_read_failed', array_merge(
        [
            'request_id' => $requestId,
            'filename' => $name,
            'size' => (int) ($file['size'] ?? 0),
            'tmp_readable' => is_readable($tmpName),
            'include_images' => $includeImages,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ],
        draftOrderImportExcelEnvironmentContext($ext)
    ));
}

function draftOrderImportReadRowsFromUpload(): array
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }

    $startedAt = microtime(true);
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

    if (in_array($ext, ['xlsx', 'xls'], true)) {
        $environmentMessage = draftOrderImportExcelEnvironmentMessage($ext);
        if ($environmentMessage !== null) {
            $requestId = bin2hex(random_bytes(8));
            logClms('draft_order_import_excel_environment_missing', array_merge(
                [
                    'request_id' => $requestId,
                    'filename' => $name,
                    'size' => (int) ($file['size'] ?? 0),
                ],
                draftOrderImportExcelEnvironmentContext($ext)
            ));
            jsonError($environmentMessage, 500, [], $requestId);
        }
    }

    $config = require dirname(__DIR__, 2) . '/config/config.php';
    $maxSize = max((int) ($config['upload_max_size'] ?? 0), 25 * 1024 * 1024);
    require_once dirname(__DIR__,2).'/services/WorkbookImportGuard.php';
    try{WorkbookImportGuard::inspect($tmpName,$ext,$maxSize);}catch(InvalidArgumentException $e){jsonError($e->getMessage(),422);}
    if (!empty($file['size']) && (int) $file['size'] > $maxSize) {
        jsonError('Import file is too large. Use a smaller Excel or CSV file.', 413);
    }

    $rows = [];
    $maxRows = 3000;
    $readWarnings = [];
    $readMeta = [
        'source_extension' => $ext,
        'source_size_bytes' => (int) ($file['size'] ?? 0),
        'rows_read' => 0,
        'blank_rows_skipped' => 0,
        'images_found' => 0,
        'images_imported' => 0,
        'read_seconds' => 0.0,
    ];
    if (in_array($ext, ['csv', 'cv'], true)) {
        require_once dirname(__DIR__,2).'/services/CsvTableService.php';
        try{$csvRows=CsvTableService::parse((string)file_get_contents($tmpName),$maxRows,$maxSize,',',true);}catch(InvalidArgumentException $e){jsonError($e->getMessage(),422);}
        $rowNumber = 0;
        foreach ($csvRows as $row) {
            $rowNumber++;
            $cleanRow = array_map('draftOrderImportRawCellString', $row);
            $cleanRow['__row_number'] = $rowNumber;
            $rows[] = $cleanRow;
            if (count($rows) > $maxRows) {
                jsonError('Import file has too many rows. Keep it under ' . $maxRows . ' rows.', 400);
            }
        }
    } else {
        $signatureMessage = draftOrderImportExcelSignatureMessage($tmpName, $ext);
        if ($signatureMessage !== null) {
            $requestId = bin2hex(random_bytes(8));
            logClms('draft_order_import_excel_invalid_signature', [
                'request_id' => $requestId,
                'filename' => $name,
                'size' => (int) ($file['size'] ?? 0),
                'extension' => $ext,
            ]);
            jsonError($signatureMessage, 400, [], $requestId);
        }

        $usedFastReader = false;
        // Use formatted spreadsheet values so item numbers such as ITEM-007
        // retain their visible zero-padding exactly as supplied.
        $preserveFormattedCellValues = true;
        if ($ext === 'xlsx' && !$preserveFormattedCellValues) {
            $fastStarted = microtime(true);
            try {
                $fastRows = draftOrderImportReadRowsFromXlsxFast($tmpName, $maxRows, $readWarnings, $readMeta);
                if (is_array($fastRows)) {
                    $rows = $fastRows;
                    $usedFastReader = true;
                    $readMeta['fast_reader_seconds'] = round(microtime(true) - $fastStarted, 3);
                }
            } catch (Throwable $e) {
                $readMeta['fast_reader_failed'] = get_class($e);
                logClms('draft_order_import_fast_xlsx_failed', [
                    'filename' => $name,
                    'size' => (int) ($file['size'] ?? 0),
                    'extension' => $ext,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if (!$usedFastReader) {
            [$readerPath, $deleteReaderPath] = draftOrderImportCopyUploadForReader($tmpName, $ext);
            try {
                $reader = draftOrderImportCreateExcelReader($ext, true);
                foreach($reader->listWorksheetInfo($readerPath) as $info)if($info['totalRows']>$maxRows || $info['totalColumns']>60)throw new InvalidArgumentException('Workbook exceeds supported row or column limits');
                $spreadsheet = $reader->load($readerPath);
                $rows = draftOrderImportReadRowsFromSpreadsheet($spreadsheet, $maxRows, true, $readWarnings, $readMeta);
                $readMeta['reader_mode'] = 'phpspreadsheet_with_images';
            } catch (Throwable $e) {
                $requestId = bin2hex(random_bytes(8));
                draftOrderImportLogExcelReadFailure($requestId, $name, $file, $tmpName, $ext, true, $e);

                try {
                    $reader = draftOrderImportCreateExcelReader($ext, false);
                    foreach($reader->listWorksheetInfo($readerPath) as $info)if($info['totalRows']>$maxRows || $info['totalColumns']>60)throw new InvalidArgumentException('Workbook exceeds supported row or column limits');
                $spreadsheet = $reader->load($readerPath);
                    $rows = draftOrderImportReadRowsFromSpreadsheet($spreadsheet, $maxRows, false, $readWarnings, $readMeta);
                    $readMeta['reader_mode'] = 'phpspreadsheet_data_only';
                    $readWarnings[] = 'The Excel rows were imported, but embedded photos could not be read on this server. Upload item photos manually after preview if needed.';
                    logClms('draft_order_import_excel_read_without_images', [
                        'request_id' => $requestId,
                        'filename' => $name,
                        'size' => (int) ($file['size'] ?? 0),
                        'extension' => $ext,
                    ]);
                } catch (Throwable $fallbackException) {
                    draftOrderImportLogExcelReadFailure($requestId, $name, $file, $tmpName, $ext, false, $fallbackException);

                    $message = draftOrderImportExcelEnvironmentMessage($ext)
                        ?? 'Could not read the Excel file. Export it again or save it as CSV and retry.';
                    if (function_exists('clmsIsDebugEnabled') && clmsIsDebugEnabled()) {
                        $message .= ' Details: ' . $fallbackException->getMessage();
                    }
                    jsonError($message, 400, [], $requestId);
                }
            } finally {
                if (!empty($deleteReaderPath) && $readerPath !== $tmpName && is_file($readerPath)) {
                    @unlink($readerPath);
                }
            }
        }
    }

    $readMeta['rows_read'] = count($rows);
    $rows = array_values(array_filter($rows, static fn(array $row): bool => !draftOrderImportRowIsBlank($row)));
    $readMeta['blank_rows_skipped'] = max(0, (int) $readMeta['rows_read'] - count($rows));
    $readMeta['read_seconds'] = round(microtime(true) - $startedAt, 3);
    if (!$rows) {
        jsonError('Import file has no readable rows.', 400);
    }

    return [$rows, $name, $readWarnings, $readMeta];
}

function draftOrderImportNumeric($value): ?float
{
    $value = draftOrderImportCellString($value);
    if ($value === '' || $value === '-' || $value === '—') {
        return null;
    }
    if(!preg_match('/^(?:(?:USD|RMB|CNY|[$¥￥])\s*)?([+-]?(?:(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d+)?|\.\d+))\s*(?:USD|RMB|CNY|kg|kgs|cbm|m³|cm|pcs|pieces|ctns|cartons)?$/iu',$value,$match))jsonError('Invalid numeric import value: '.$value,422);
    $number=(float)str_replace(',','',$match[1]);if(!is_finite($number)||abs($number)>99999999.9999)jsonError('Import number exceeds the supported range',422);return $number;
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

function draftOrderImportRawField(array $row, array $map, string $field): string
{
    if (!array_key_exists($field, $map)) return '';
    return draftOrderImportRawCellString($row[$map[$field]] ?? '');
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

function draftOrderImportDescriptionEntries(array $row, array $map): array
{
    $entries = [];
    $usedIndexes = [];

    $rememberIndex = static function (string $field) use (&$usedIndexes, $map): void {
        if (array_key_exists($field, $map) && is_int($map[$field])) {
            $usedIndexes[] = $map[$field];
        }
    };

    $englishName = draftOrderImportField($row, $map, 'description_en');
    $chineseName = draftOrderImportField($row, $map, 'description_cn');
    if ($englishName !== '' || $chineseName !== '') {
        $entries[] = [
            'description_text' => $chineseName,
            'description_translated' => $englishName,
        ];
        $rememberIndex('description_en');
        $rememberIndex('description_cn');
    }

    $genericIndexes = !empty($map['_description_indexes']) && is_array($map['_description_indexes'])
        ? $map['_description_indexes']
        : (array_key_exists('description', $map) ? [$map['description']] : []);
    foreach ($genericIndexes as $index) {
        if (!is_int($index) || in_array($index, $usedIndexes, true)) {
            continue;
        }
        $value = draftOrderImportDescriptionWithoutCartonPrefix(draftOrderImportCellString($row[$index] ?? ''));
        if ($value === '') {
            continue;
        }
        $entries[] = [
            'description_text' => $value,
            'description_translated' => '',
        ];
        $usedIndexes[] = $index;
    }

    $deduped = [];
    $seen = [];
    foreach ($entries as $entry) {
        $text = trim((string) ($entry['description_text'] ?? ''));
        $translated = trim((string) ($entry['description_translated'] ?? ''));
        if ($text === '' && $translated === '') {
            continue;
        }
        $key = strtolower($text . "\n" . $translated);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $deduped[] = [
            'description_text' => $text,
            'description_translated' => $translated,
        ];
    }

    return $deduped;
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

function draftOrderImportResolveSupplierFromFields(PDO $pdo, string $name = '', string $code = '', string $id = ''): array
{
    static $cache = [];
    $cacheKey = spl_object_id($pdo) . ':supplier_fields:' . strtolower(trim($id) . '|' . trim($code) . '|' . trim($name));
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $id = trim($id);
    if ($id !== '' && ctype_digit($id)) {
        try {
            $stmt = $pdo->prepare("SELECT id, name FROM suppliers WHERE id = ? LIMIT 1");
            $stmt->execute([(int) $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $cache[$cacheKey] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
            }
        } catch (Throwable $e) {
        }
    }

    $code = trim($code);
    if ($code !== '') {
        $resolved = draftOrderImportResolveSupplier($pdo, $code);
        if (!empty($resolved['id'])) {
            return $cache[$cacheKey] = $resolved;
        }
    }

    return $cache[$cacheKey] = draftOrderImportResolveSupplier($pdo, $name !== '' ? $name : $code);
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
        $stmt = $pdo->prepare("SELECT $select FROM customers WHERE ($conditions) ORDER BY id LIMIT 1");
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
    if (is_numeric($value)) {
        $serial = (float) $value;
        if ($serial > 20000 && $serial < 80000) {
            $timestamp = (int) round(($serial - 25569) * 86400);
            return gmdate('Y-m-d', $timestamp);
        }
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

function draftOrderImportLooksLikeSummaryRow(array $row): bool
{
    $summaryTokens = [
        'suppliersubtotal',
        'supplierstotal',
        'grandtotal',
        'sectionitemtotal',
        'overalltotal',
    ];

    foreach ($row as $index => $value) {
        if (!is_int($index)) {
            continue;
        }
        if (in_array(draftOrderImportHeaderKey($value), $summaryTokens, true)) {
            return true;
        }
    }

    return false;
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

function draftOrderImportBuildItem(array $row, array $map, ?string &$skipReason = null): ?array
{
    $skipReason = null;
    if (draftOrderImportLooksLikeSummaryRow($row)) {
        $skipReason = '__ignore_summary_row';
        return null;
    }

    $itemNo = draftOrderImportRawField($row, $map, 'item_no');
    $brand = draftOrderNormalizeItemText(draftOrderImportField($row, $map, 'brand'), 150);
    $whatBrand = draftOrderNormalizeItemText(draftOrderImportField($row, $map, 'what_brand'), 150);
    if ($whatBrand === null && $brand !== null) {
        $whatBrand = $brand;
    }
    if ($brand === null && $whatBrand !== null) {
        $brand = $whatBrand;
    }
    $materials = draftOrderNormalizeItemText(draftOrderImportField($row, $map, 'materials'), 1000);
    $copyNormalGoods = draftOrderNormalizeCopyNormalGoods(draftOrderImportField($row, $map, 'copy_normal_goods'));
    $code = draftOrderNormalizeItemText(draftOrderImportField($row, $map, 'code'), 100);
    $expressNumber = draftOrderNormalizeItemText(draftOrderImportField($row, $map, 'express_number'), 150);
    $size = draftOrderNormalizeItemText(draftOrderImportField($row, $map, 'size'), 150);
    $length = draftOrderImportFieldNumber($row, $map, 'length');
    $width = draftOrderImportFieldNumber($row, $map, 'width');
    $height = draftOrderImportFieldNumber($row, $map, 'height');
    $descriptionEntries = draftOrderImportDescriptionEntries($row, $map);
    $description = '';
    foreach ($descriptionEntries as $entry) {
        $description = trim((string) (($entry['description_text'] ?? '') ?: ($entry['description_translated'] ?? '')));
        if ($description !== '') {
            break;
        }
    }
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
    $unit = draftOrderNormalizeUnit(draftOrderImportField($row, $map, 'unit'));
    $notes = trim(draftOrderImportField($row, $map, 'notes'));
    $customDesignNote = draftOrderNormalizeItemText(draftOrderImportField($row, $map, 'custom_design_note'), 1000);
    $photoPaths = draftOrderImportRowPhotoPaths($row, $map);

    if (!$descriptionEntries && $code !== null) {
        $descriptionEntries[] = [
            'description_text' => $code,
            'description_translated' => '',
        ];
        $description = $code;
    }

    if (
        $itemNo === ''
        && $description === ''
        && $code === null
        && $brand === null
        && $materials === null
        && $expressNumber === null
        && !$photoPaths
        && $cartons === null
        && $quantity === null
        && $totalAmount === null
        && $totalCbm === null
        && $totalWeight === null
    ) {
        return null;
    }

    $descriptionToken = draftOrderImportHeaderKey($description);
    if (in_array($descriptionToken, ['suppliersubtotal', 'grandtotal', 'sectionitemtotal', 'overalltotal'], true)) {
        $skipReason = '__ignore_summary_row';
        return null;
    }

    foreach ([
        'Length' => $length,
        'Width' => $width,
        'Height' => $height,
        'Cartons' => $cartons,
        'Pieces/Carton' => $piecesPerCarton,
        'Quantity' => $quantity,
        'Factory Price' => $factoryPrice,
        'Customer Price' => $customerPrice,
        'Unit Price' => $plainUnitPrice,
        'Total Amount' => $totalAmount,
        'CBM' => $rawCbm,
        'Total CBM' => $totalCbm,
        'Weight' => $rawWeight,
        'Total Weight' => $totalWeight,
    ] as $label => $number) {
        if ($number !== null && $number < 0) {
            $skipReason = $label . ' cannot be negative.';
            return null;
        }
    }

    if ($cartons !== null && $cartons <= 0 && (($quantity !== null && $quantity > 0) || ($piecesPerCarton !== null && $piecesPerCarton > 0))) {
        $cartons = null;
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
    if ($cartons === null && $quantity === null && $piecesPerCarton !== null && $piecesPerCarton > 0) {
        $cartons = 1.0;
        $quantity = $piecesPerCarton;
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
    return [
        'product_id' => null,
        'item_no' => $itemNo,
        'item_number' => clmsPackingListItemNumber(draftOrderImportRawField($row, $map, 'item_number')),
        'item_no_manual' => $itemNo !== '' ? 1 : 0,
        'item_no_source' => 'imported',
        'import_row_number' => (int) draftOrderImportRowMeta($row, '__row_number', 0),
        'shipping_code' => null,
        'what_brand' => $whatBrand,
        'brand' => $brand,
        'materials' => $materials,
        'copy_normal_goods' => $copyNormalGoods,
        'code' => $code,
        'express_number' => $expressNumber,
        'size' => $size,
        'description_entries' => $descriptionEntries,
        'pieces_per_carton' => draftOrderImportNumberForForm($piecesPerCarton, 4),
        'cartons' => $cartons !== null ? draftOrderImportNumberForForm($cartons, 0) : '',
        'quantity' => draftOrderImportNumberForForm($quantity, 4),
        'unit' => $unit,
        'unit_price' => draftOrderImportNumberForForm($unitPrice, 4),
        'sell_price' => draftOrderImportNumberForForm($sellPrice, 4),
        'total_amount' => draftOrderImportNumberForForm($totalAmount, 4),
        'cbm_mode' => ($cbm['value'] === null && $length !== null && $width !== null && $height !== null) ? 'dimensions' : 'direct',
        'cbm' => draftOrderImportNumberForForm($cbm['value'], 6),
        'item_length' => draftOrderImportNumberForForm($length, 4),
        'item_width' => draftOrderImportNumberForForm($width, 4),
        'item_height' => draftOrderImportNumberForForm($height, 4),
        'length' => draftOrderImportNumberForForm($length, 4),
        'width' => draftOrderImportNumberForForm($width, 4),
        'height' => draftOrderImportNumberForForm($height, 4),
        'weight' => draftOrderImportNumberForForm($weight['value'], 4),
        'dimensions_scope' => $scope,
        'hs_code' => draftOrderNormalizeHsCode($hsCode) ?: '',
        'notes' => $notes ?: null,
        'photo_paths' => $photoPaths,
        'custom_design_required' => draftOrderImportIsTruthy($customRaw) ? 1 : 0,
        'custom_design_note' => $customDesignNote,
        'custom_design_paths' => [],
        'shared_carton_enabled' => 0,
        'shared_carton_code' => null,
        'shared_carton_contents' => [],
    ];
}

function draftOrderImportBuildSharedContent(PDO $pdo, array $row, array $map, array $fallbackSupplier, ?string &$skipReason = null): ?array
{
    $item = draftOrderImportBuildItem($row, $map, $skipReason);
    if (!$item) {
        return null;
    }
    $supplierName = draftOrderImportField($row, $map, 'supplier_name');
    $supplierCode = draftOrderImportField($row, $map, 'supplier_code');
    $supplierId = draftOrderImportField($row, $map, 'supplier_id');
    $supplier = ($supplierName !== '' || $supplierCode !== '' || $supplierId !== '')
        ? draftOrderImportResolveSupplierFromFields($pdo, $supplierName, $supplierCode, $supplierId)
        : draftOrderImportResolveSupplier($pdo, (string) ($fallbackSupplier['name'] ?? ''));

    return [
        'supplier_id' => $supplier['id'],
        'supplier_name' => $supplier['name'],
        'product_id' => null,
        'item_no' => $item['item_no'],
        'item_number' => $item['item_number'] ?? null,
        'item_no_manual' => $item['item_no'] !== '' ? 1 : 0,
        'item_no_source' => 'imported',
        'import_row_number' => $item['import_row_number'] ?? 0,
        'shipping_code' => null,
        'quantity_per_carton' => $item['pieces_per_carton'],
        'unit_price' => $item['unit_price'],
        'sell_price' => $item['sell_price'],
        'what_brand' => $item['what_brand'],
        'brand' => $item['brand'],
        'materials' => $item['materials'],
        'copy_normal_goods' => $item['copy_normal_goods'],
        'code' => $item['code'],
        'express_number' => $item['express_number'],
        'size' => $item['size'],
        'length' => $item['length'],
        'width' => $item['width'],
        'height' => $item['height'],
        'hs_code' => $item['hs_code'],
        'description_entries' => $item['description_entries'],
        'description_cn' => '',
        'description_en' => '',
        'image_paths' => $item['photo_paths'] ?? [],
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

function draftOrderImportSupplierContextKey(array $supplier): string
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

function draftOrderImportApplySectionExpressNumber(array &$item, ?string &$currentSectionExpressNumber): void
{
    $rowExpress = draftOrderNormalizeItemText($item['express_number'] ?? null, 150);
    if ($rowExpress !== null) {
        $currentSectionExpressNumber = $rowExpress;
        $item['express_number'] = $rowExpress;
        return;
    }

    if ($currentSectionExpressNumber !== null) {
        $item['express_number'] = $currentSectionExpressNumber;
    }
}

function draftOrderImportBuildPayload(PDO $pdo, array $rows, string $filename, array $readWarnings = [], array $readMeta = []): array
{
    $buildStartedAt = microtime(true);
    $warnings = $readWarnings;
    $meta = [
        'customer_name' => '',
        'destination_country_name' => '',
        'expected_ready_date' => null,
        'currency' => 'RMB',
    ];
    $sections = [];
    $currentSupplier = ['id' => null, 'name' => ''];
    $currentSupplierContextKey = draftOrderImportSupplierContextKey($currentSupplier);
    $currentSectionExpressNumber = null;
    $currentHeader = null;
    $pendingShared = null;
    $importedRows = 0;
    $sawImportHeader = false;
    $sawMappedItemData = false;
    $skippedRows = [];
    $headerFieldsSeen = [];

    foreach ($rows as $row) {
        if (draftOrderImportLooksLikeHeader($row)) {
            draftOrderImportFinalizeShared($sections, $pendingShared);
            $currentHeader = draftOrderImportHeaderMap($row);
            foreach ($currentHeader as $field => $index) {
                if ($field === '_description_indexes') {
                    continue;
                }
                $headerFieldsSeen[$field] = true;
            }
            $sawImportHeader = true;
            continue;
        }
        if (draftOrderImportLooksLikeTranslatedHeader($row)) {
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
            $currentSupplierContextKey = draftOrderImportSupplierContextKey($currentSupplier);
            $currentSectionExpressNumber = null;
            continue;
        }
        if (draftOrderImportCellString($row[0] ?? '') === '@@' || draftOrderImportHeaderKey($row[1] ?? '') === 'suppliernameandinfo') {
            draftOrderImportFinalizeShared($sections, $pendingShared);
            $currentSupplier = draftOrderImportResolveSupplier($pdo, draftOrderImportCellString($row[2] ?? ''));
            $currentSupplierContextKey = draftOrderImportSupplierContextKey($currentSupplier);
            $currentSectionExpressNumber = null;
            continue;
        }
        if (draftOrderImportLooksLikeSummaryRow($row)) {
            draftOrderImportFinalizeShared($sections, $pendingShared);
            continue;
        }
        if (!$currentHeader) {
            continue;
        }
        $hasMappedItemData = draftOrderImportRowHasMappedItemData($row, $currentHeader);
        if ($hasMappedItemData) {
            $sawMappedItemData = true;
        } else {
            $skippedRows[] = [
                'row' => (int) draftOrderImportRowMeta($row, '__row_number', 0),
                'reason' => 'No importable item data found in mapped columns.',
            ];
            continue;
        }

        $rowSupplierName = draftOrderImportField($row, $currentHeader, 'supplier_name');
        $rowSupplierCode = draftOrderImportField($row, $currentHeader, 'supplier_code');
        $rowSupplierId = draftOrderImportField($row, $currentHeader, 'supplier_id');
        $rowSupplier = ($rowSupplierName !== '' || $rowSupplierCode !== '' || $rowSupplierId !== '')
            ? draftOrderImportResolveSupplierFromFields($pdo, $rowSupplierName, $rowSupplierCode, $rowSupplierId)
            : $currentSupplier;
        $rowSupplierContextKey = draftOrderImportSupplierContextKey($rowSupplier);
        if ($rowSupplierContextKey !== $currentSupplierContextKey) {
            draftOrderImportFinalizeShared($sections, $pendingShared);
            $currentSupplierContextKey = $rowSupplierContextKey;
            $currentSectionExpressNumber = null;
        }
        $itemNo = draftOrderImportField($row, $currentHeader, 'item_no');
        $description = draftOrderImportField($row, $currentHeader, 'description');
        $descriptionToken = draftOrderImportHeaderKey($description);

        if ($descriptionToken === 'sharedcartonmultipleitems') {
            draftOrderImportFinalizeShared($sections, $pendingShared);
            $skipReason = null;
            $summary = draftOrderImportBuildItem($row, $currentHeader, $skipReason);
            if ($summary) {
                draftOrderImportApplySectionExpressNumber($summary, $currentSectionExpressNumber);
                $summary['shared_carton_enabled'] = 1;
                $summary['shared_carton_code'] = $summary['item_no'] ?: null;
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
            } elseif ($skipReason) {
                if ($skipReason === '__ignore_summary_row') {
                    continue;
                }
                $skippedRows[] = [
                    'row' => (int) draftOrderImportRowMeta($row, '__row_number', 0),
                    'reason' => $skipReason,
                ];
            }
            continue;
        }

        if ($pendingShared !== null && draftOrderImportLooksLikeSharedContent($itemNo)) {
            $skipReason = null;
            $content = draftOrderImportBuildSharedContent($pdo, $row, $currentHeader, $pendingShared['supplier'], $skipReason);
            if ($content) {
                draftOrderImportApplySectionExpressNumber($content, $currentSectionExpressNumber);
                $pendingShared['contents'][] = $content;
                $importedRows++;
            } elseif ($skipReason) {
                if ($skipReason === '__ignore_summary_row') {
                    continue;
                }
                $skippedRows[] = [
                    'row' => (int) draftOrderImportRowMeta($row, '__row_number', 0),
                    'reason' => $skipReason,
                ];
            }
            continue;
        }

        draftOrderImportFinalizeShared($sections, $pendingShared);
        $skipReason = null;
        $item = draftOrderImportBuildItem($row, $currentHeader, $skipReason);
        if (!$item) {
            if ($skipReason === '__ignore_summary_row') {
                continue;
            }
            $skippedRows[] = [
                'row' => (int) draftOrderImportRowMeta($row, '__row_number', 0),
                'reason' => $skipReason ?: 'The row did not contain a usable item name, code, item number, quantity, price, CBM, weight, or photo.',
            ];
            continue;
        }
        draftOrderImportApplySectionExpressNumber($item, $currentSectionExpressNumber);
        draftOrderImportAddItemToSections($sections, $rowSupplier, $item);
        $importedRows++;
    }
    draftOrderImportFinalizeShared($sections, $pendingShared);

    if (!$importedRows || !$sections) {
        $rowErrorDetails = $skippedRows ? [
            'skipped_rows' => array_slice(array_values(array_filter(
                $skippedRows,
                static fn(array $row): bool => !empty($row['row'])
            )), 0, 25),
        ] : [];
        if ($sawImportHeader && !$sawMappedItemData) {
            jsonError(
                'The import template was recognized, but no item rows were filled in. Add at least one product row below the header, then import again.',
                400,
                $rowErrorDetails
            );
        }
        if ($sawImportHeader) {
            jsonError(
                'The import header was recognized, but no filled item rows could be imported. Each item row needs an item name or description, item number, quantity, price, CBM, or weight.',
                400,
                $rowErrorDetails
            );
        }
        jsonError('No draft-order item rows matched the import column names. Use headers like "Item No", "English Item Name", "Chinese Item Name", "Product / Names", or "Description".', 400);
    }

    $missingOptionalColumns = [];
    foreach (draftOrderImportOptionalColumnLabels() as $field => $label) {
        if (empty($headerFieldsSeen[$field])) {
            $missingOptionalColumns[] = $label;
        }
    }

    $itemNumberWarnings = [];
    $seenImportedNumbers = [];
    foreach ($sections as $section) {
        foreach (($section['items'] ?? []) as $item) {
            $candidates = [];
            if (!empty($item['shared_carton_enabled'])) {
                $candidates = $item['shared_carton_contents'] ?? [];
            } else {
                $candidates = [$item];
            }
            foreach ($candidates as $candidate) {
                $display = (string) ($candidate['item_no'] ?? '');
                $normalized = ItemNumberReservationService::normalize($display);
                if ($normalized === '') continue;
                $rowNumber = (int) ($candidate['import_row_number'] ?? $item['import_row_number'] ?? 0);
                if (!preg_match('/\d+[^\d]*$/u', $display)) {
                    $warning='Row '.($rowNumber?:'?').' item number "'.$display.'" has no final numeric sequence. It was preserved and will not drive automatic suggestions.';
                    $warnings[]=$warning;$itemNumberWarnings[]=['row'=>$rowNumber,'item_no'=>$display,'warning'=>$warning];
                }
                if (isset($seenImportedNumbers[$normalized])) {
                    $warning = 'Rows ' . $seenImportedNumbers[$normalized] . ' and ' . ($rowNumber ?: '?') . ' use duplicate item number "' . $display . '". The imported values were preserved.';
                    $warnings[] = $warning;
                    $itemNumberWarnings[] = ['row' => $rowNumber, 'item_no' => $display, 'warning' => $warning];
                } else {
                    $seenImportedNumbers[$normalized] = $rowNumber ?: '?';
                }
            }
        }
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
            'rows_skipped' => count($skippedRows),
            'skipped_rows' => array_slice(array_values(array_filter(
                $skippedRows,
                static fn(array $row): bool => !empty($row['row'])
            )), 0, 25),
            'blank_rows_skipped' => (int) ($readMeta['blank_rows_skipped'] ?? 0),
            'rows_read' => (int) ($readMeta['rows_read'] ?? count($rows)),
            'total_item_rows' => $importedRows + count($skippedRows),
            'missing_optional_columns' => $missingOptionalColumns,
            'images_found' => (int) ($readMeta['images_found'] ?? 0),
            'images_imported' => (int) ($readMeta['images_imported'] ?? 0),
            'reader_mode' => (string) ($readMeta['reader_mode'] ?? ''),
            'fast_reader_seconds' => isset($readMeta['fast_reader_seconds']) ? (float) $readMeta['fast_reader_seconds'] : null,
            'read_seconds' => (float) ($readMeta['read_seconds'] ?? 0),
            'import_seconds' => round(microtime(true) - $buildStartedAt, 3),
            'total_seconds' => round((float) ($readMeta['read_seconds'] ?? 0) + (microtime(true) - $buildStartedAt), 3),
            'warnings' => array_values(array_unique($warnings)),
            'item_number_warnings' => $itemNumberWarnings,
        ],
    ];
}

function draftOrderDeleteExistingItems(PDO $pdo, int $orderId): void
{
    $reservationIds=[];
    try{
        $stmt=$pdo->prepare('SELECT reservation_id FROM item_number_references WHERE order_id=?');$stmt->execute([$orderId]);$reservationIds=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
        $pdo->prepare('DELETE FROM item_number_references WHERE order_id=?')->execute([$orderId]);
    }catch(Throwable $e){$reservationIds=[];}
    $stmt = $pdo->prepare("SELECT id FROM order_items WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $itemIds = array_map(static fn($id) => (int) $id, $stmt->fetchAll(PDO::FETCH_COLUMN));
    if ($itemIds) {
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $delDesign = $pdo->prepare("DELETE FROM design_attachments WHERE entity_type = 'order_item' AND entity_id IN ($placeholders)");
        $delDesign->execute($itemIds);
        if (draftOrderTableHasColumn($pdo, 'item_classifications', 'item_type_code')) {
            $delClassifications = $pdo->prepare("DELETE FROM item_classifications WHERE entity_type = 'order_item' AND entity_id IN ($placeholders)");
            $delClassifications->execute($itemIds);
        }
    }
    $pdo->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$orderId]);
    if($reservationIds){
        $placeholders=implode(',',array_fill(0,count($reservationIds),'?'));
        $pdo->prepare("DELETE r FROM item_number_reservations r LEFT JOIN item_number_references ref ON ref.reservation_id=r.id WHERE r.id IN ($placeholders) AND ref.id IS NULL")->execute($reservationIds);
    }
}

return function (string $method, ?string $id, ?string $action, array $input) {
    require_once __DIR__ . '/../authorization.php';
    clmsAuthorizeApiRequest('draft-orders', $method, $id, $action);
    $pdo = getDb();
    if ($method === 'GET') { require_once dirname(__DIR__, 2) . '/services/QueryFilterService.php'; QueryFilterService::validate($_GET, 'draft-orders'); }
    if(($method==='GET'&&($action==='export'||$id==='export'))||($method==='POST'&&$id==='bulk-export'))clmsBeginExportSnapshot($pdo);
    $userId = getAuthUserId();
    if(array_key_exists('supplier_sections',$input)&&!is_array($input['supplier_sections']))jsonError('supplier_sections must be an array',422);

    if (!$userId) {
        jsonError('Unauthorized', 401);
    }
    if ($method === 'GET' && $id !== null && ctype_digit($id) && $action === 'export') {
        clmsRequirePermission('orders.read', [], $pdo, $userId);
    } else {
        clmsRequirePermission('page:procurement_drafts', ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'ContainersStaff', 'FieldStaff', 'SuperAdmin'], $pdo, $userId);
    }

    if ($method === 'GET' && $id === 'numbering-history') {
        $customerId = (int) ($_GET['customer_id'] ?? 0);
        if ($customerId <= 0) jsonError('customer_id is required.', 400);
        jsonResponse(['data' => OrderItemNumberingService::fetchNumberingHistory($pdo, $customerId)]);
    }

    if ($method === 'GET' && $id === 'export-filtered') {
        [$rows] = draftOrderListQuery($pdo, draftOrderRequestFilters(), false);
        if (!$rows) jsonError('No draft orders match the active filters.', 404);
        $entries = array_map(static fn(array $row): array => draftOrderBuildExcelEntry($pdo, (int) $row['id']), $rows);
        (new OrderBulkExcelService($pdo))->output($entries, 'filtered_draft_orders');
    }

    if ($method === 'POST' && $id === 'import' && $action === null) {
        MasterDataImportService::lock($pdo,'draft_preview_'.(int)$userId);
        $GLOBALS['draftImportNewFiles']=[];$GLOBALS['draftImportRetainedFiles']=[];
        register_shutdown_function(static function(){foreach($GLOBALS['draftImportNewFiles']??[] as $path)if(!in_array($path,$GLOBALS['draftImportRetainedFiles']??[],true))@unlink($path);});
        [$rows, $filename, $readWarnings, $readMeta] = draftOrderImportReadRowsFromUpload();
        $payload=draftOrderImportBuildPayload($pdo, $rows, $filename, $readWarnings, $readMeta);
        $serialized=json_encode($payload,JSON_UNESCAPED_SLASHES);
        foreach($GLOBALS['draftImportNewFiles'] as $path)if(str_contains($serialized,'uploads/'.basename($path)))$GLOBALS['draftImportRetainedFiles'][]=$path;
        jsonResponse(['data'=>$payload]);
    }

    if ($method === 'POST' && $id === 'legacy' && $action && preg_match('/^(\d+)\/migrate$/', $action, $matches)) {
        $legacyId = (int) $matches[1];
        $stmt = $pdo->prepare("SELECT * FROM procurement_drafts WHERE deleted_at IS NULL AND id = ?");
        $stmt->execute([$legacyId]);
        $legacy = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$legacy) {
            jsonError('Legacy procurement draft not found', 404);
        }
        if (!empty($legacy['converted_order_id'])) {
            jsonResponse(['data' => ['already_migrated' => true, 'converted_order_id'=>(int)$legacy['converted_order_id'], 'order' => draftOrderFetchOrderPayload($pdo, (int) $legacy['converted_order_id'])]]);
        }

        $customerId = (int) ($input['customer_id'] ?? 0);
        $expectedDate = draftOrderNormalizeExpectedReadyDate($input['expected_ready_date'] ?? null);
        $currency = strtoupper(trim((string) ($input['currency'] ?? 'USD'))) ?: 'USD';
        if ($customerId <= 0) {
            jsonError('customer_id is required for legacy migration.', 400, ['customer_id' => 'Customer is required.']);
        }
        if (!in_array($currency, ['USD', 'RMB'], true)) {
            jsonError('Currency must be USD or RMB',422);
        }
        $destinationCountryId = null;
        if (draftOrderTableHasColumn($pdo, 'orders', 'destination_country_id')) {
            $destinationCountryId = draftOrderResolveDestinationCountryId($pdo, $customerId, $input);
        }

        $itemsStmt = $pdo->prepare(
            "SELECT pdi.*, p.description_cn, p.description_en, p.cbm, p.weight, p.unit_price, p.hs_code, p.dimensions_scope, p.pieces_per_carton
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
            draftOrderLockCustomerNumbering($pdo, $customerId);
            $legacyLock=$pdo->prepare('SELECT * FROM procurement_drafts WHERE deleted_at IS NULL AND id=? FOR UPDATE');$legacyLock->execute([$legacyId]);$legacy=$legacyLock->fetch(PDO::FETCH_ASSOC);
            if(!$legacy)jsonError('Legacy procurement draft not found',404);
            if(!empty($legacy['converted_order_id'])){$pdo->commit();jsonResponse(['data'=>['already_migrated'=>true,'converted_order_id'=>(int)$legacy['converted_order_id'],'order'=>draftOrderFetchOrderPayload($pdo,(int)$legacy['converted_order_id'])]]);}
            if(!in_array($legacy['status'],['draft','pending_review','sent_to_supplier'],true))jsonError('Legacy draft cannot be converted in its current state',409);
            $supplierId=!empty($legacy['supplier_id'])?(int)$legacy['supplier_id']:null;
            $itemsStmt->execute([$legacyId]);$legacyItems=$itemsStmt->fetchAll(PDO::FETCH_ASSOC);

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
                $legacyItem = LegacyProcurementMetricsService::normalize($legacyItem);
                $qty = (float) ($legacyItem['quantity'] ?? 0);
                OrderWriteService::validateRawNumbers($legacyItem);
                if ($qty <= 0) jsonError('Legacy item quantity must be positive',422);
                $description = draftOrderBuildDescriptionStrings($pdo, [[
                    'description_text' => $legacyItem['description_cn'] ?? $legacyItem['notes'] ?? '',
                    'description_translated' => $legacyItem['description_en'] ?? '',
                ]]);
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
                    'declared_cbm' => $legacyItem['declared_cbm'],
                    'declared_weight' => $legacyItem['declared_weight'],
                    'item_length' => null,
                    'item_width' => null,
                    'item_height' => null,
                    'hs_code' => draftOrderNormalizeHsCode($legacyItem['hs_code'] ?? null),
                    'description_entries' => $description['entries'],
                    'description_cn' => $description['description_cn'],
                    'description_en' => $description['description_en'],
                    'notes' => trim((string) ($legacyItem['notes'] ?? '')) ?: null,
                    'photo_paths' => [],
                    'custom_design_required' => 0,
                    'custom_design_note' => null,
                    'custom_design_paths' => [],
                ];
            }
            if (!$normalizedItems) {
                jsonError('Legacy procurement draft has no valid items to migrate.', 400);
            }

            $normalizedItems=OrderWriteService::standardItems($pdo,$normalizedItems,$supplierId);
            $normalizedItems = draftOrderAssignCanonicalItemNumbers($pdo, $customerId, $normalizedItems, $supplierId, $destinationCountryId);
            draftOrderAssertUniqueItemNumbers($pdo, $customerId, $normalizedItems);
            draftOrderInsertItems($pdo, $orderId, $supplierId, $normalizedItems, $userId);
            (new ItemNumberReservationService($pdo))->reservePersistedOrder($orderId,(int)$userId);
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
            jsonResponse(['data' => ['already_migrated' => false, 'converted_order_id'=>$orderId, 'order' => draftOrderFetchOrderPayload($pdo, $orderId)]], 201);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    if ($method === 'GET' && $id && ctype_digit((string) $id) && $action === 'export') {
        $format = clmsExportFormat('csv');
        if ($format === 'xlsx') {
            draftOrderExportXlsx($pdo, (int) $id);
        }
        draftOrderExportCsv($pdo, (int) $id);
    }

    if ($method === 'GET') {
        if ($id === null) {
            $filters = draftOrderRequestFilters();
            [$rows, $meta] = draftOrderListQuery($pdo, $filters, true);
            jsonResponse(['data' => $rows, 'meta' => $meta, 'filter_options' => draftOrderFilterOptions($pdo, $filters)]);
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
        $creationKey = draftOrderIdempotencyKey($input['idempotency_key'] ?? $input['creation_idempotency_key'] ?? null);
        $expectedDate = draftOrderNormalizeExpectedReadyDate($input['expected_ready_date'] ?? null);
        $currency = strtoupper(trim((string) ($input['currency'] ?? 'USD'))) ?: 'USD';
        $highAlertNotes = trim((string) ($input['high_alert_notes'] ?? '')) ?: null;
        if ($customerId <= 0) {
            jsonError('customer_id is required.', 400, ['customer_id' => 'Customer is required.']);
        }
        if($existingId=OrderWriteService::replay($pdo,$creationKey,$input,'draft_procurement',(int)$userId))jsonResponse(['data'=>draftOrderFetchOrderPayload($pdo,$existingId),'idempotent_replay'=>true]);
        if (!in_array($currency, ['USD', 'RMB'], true)) {
            jsonError('Currency must be USD or RMB', 400, ['currency' => 'Currency must be USD or RMB.']);
        }
        $destinationCountryId = draftOrderResolveDestinationCountryId($pdo, $customerId, $input);

        $items = draftOrderFlattenSections($pdo, $input['supplier_sections'] ?? []);
        $supplierIds = array_values(array_unique(array_filter(array_map(
            static fn($item) => (int) ($item['supplier_id'] ?? 0),
            $items
        ))));
        $defaultSupplierId = count($supplierIds) === 1 ? $supplierIds[0] : null;
        $dupWarn = null;

        $pdo->beginTransaction();
        try {
            draftOrderLockCustomerNumbering($pdo, $customerId);
            if($existingId=OrderWriteService::replay($pdo,$creationKey,$input,'draft_procurement',(int)$userId)){$pdo->commit();jsonResponse(['data'=>draftOrderFetchOrderPayload($pdo,$existingId),'idempotent_replay'=>true]);}
            $items = draftOrderAssignCanonicalItemNumbers($pdo, $customerId, $items, $defaultSupplierId, $destinationCountryId);
            $itemNumberWarnings = draftOrderAssertUniqueItemNumbers($pdo, $customerId, $items);
            $dupWarn = draftOrderEnforceDuplicateShippingCodePolicy($pdo, $customerId, 0, $items);
            if (draftOrderTableHasColumn($pdo, 'orders', 'destination_country_id')) {
                $pdo->prepare(
                    "INSERT INTO orders (customer_id, supplier_id, expected_ready_date, currency, status, order_type, high_alert_notes, created_by, destination_country_id, creation_idempotency_key)
                     VALUES (?, ?, ?, ?, 'Draft', 'draft_procurement', ?, ?, ?, ?)"
                )->execute([$customerId, $defaultSupplierId, $expectedDate, $currency, $highAlertNotes, $userId, $destinationCountryId, $creationKey]);
            } else {
                $pdo->prepare(
                    "INSERT INTO orders (customer_id, supplier_id, expected_ready_date, currency, status, order_type, high_alert_notes, created_by, creation_idempotency_key)
                     VALUES (?, ?, ?, ?, 'Draft', 'draft_procurement', ?, ?, ?)"
                )->execute([$customerId, $defaultSupplierId, $expectedDate, $currency, $highAlertNotes, $userId, $creationKey]);
            }
            $orderId = (int) $pdo->lastInsertId();
            draftOrderInsertItems($pdo, $orderId, $defaultSupplierId, $items, $userId);
            (new ItemNumberReservationService($pdo))->reservePersistedOrder($orderId,(int)$userId);
            $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('order', ?, 'create', ?, ?)")
                ->execute([$orderId, json_encode(['status' => 'Draft', 'order_type' => 'draft_procurement','request_hash'=>OrderWriteService::requestHash($input)], JSON_UNESCAPED_UNICODE), $userId]);
            (new NotificationService($pdo))->notifyOrderCreated($orderId, $userId);
            $pdo->commit();
            $warnings=array_values(array_filter(array_merge($itemNumberWarnings,[$dupWarn])));
            jsonResponse(array_filter(['data' => draftOrderFetchOrderPayload($pdo, $orderId), 'warning' => $warnings?implode(' ',array_unique($warnings)):null]), 201);
        } catch (Throwable $e) {
            if($pdo->inTransaction())$pdo->rollBack();
            if($e instanceof PDOException && (string)$e->getCode()==='23000' && $creationKey!==null){
                if($existingId=OrderWriteService::replay($pdo,$creationKey,$input,'draft_procurement',(int)$userId))jsonResponse(['data'=>draftOrderFetchOrderPayload($pdo,$existingId),'idempotent_replay'=>true]);
            }
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
            jsonError('customer_id is required.', 400, ['customer_id' => 'Customer is required.']);
        }
        if (!in_array($currency, ['USD', 'RMB'], true)) {
            jsonError('Currency must be USD or RMB', 400, ['currency' => 'Currency must be USD or RMB.']);
        }
        $destinationCountryId = draftOrderResolveDestinationCountryId($pdo, $customerId, $input, $order);

        $items = draftOrderFlattenSections($pdo, $input['supplier_sections'] ?? []);
        $items = draftOrderPreserveExistingNumbers($pdo, $orderId, $items);
        $supplierIds = array_values(array_unique(array_filter(array_map(
            static fn($item) => (int) ($item['supplier_id'] ?? 0),
            $items
        ))));
        $defaultSupplierId = count($supplierIds) === 1 ? $supplierIds[0] : null;
        $dupWarn = null;

        $pdo->beginTransaction();
        try {
            draftOrderLockCustomerNumbering($pdo, $customerId);
            $lockedRow=OrderWriteService::lockMutableProcurement($pdo,$orderId);
            if (($lockedRow['status']??'') !== 'Draft') throw new RuntimeException('Draft order is no longer editable');
            OrderWriteService::assertFinancialIdentity($pdo,$order,$customerId,$currency);
            $expectedVersion=(int)($input['lock_version']??-1);
            if($expectedVersion<0 || $expectedVersion!==(int)($lockedRow['lock_version']??0))throw new RuntimeException('Draft changed in another request; reload and try again');
            $itemNumberChanges = draftOrderCollectItemNumberAudit($pdo, $orderId, $items);
            $items = draftOrderAssignCanonicalItemNumbers($pdo, $customerId, $items, $defaultSupplierId, $destinationCountryId, $orderId);
            $itemNumberWarnings = draftOrderAssertUniqueItemNumbers($pdo, $customerId, $items, $orderId);
            $dupWarn = draftOrderEnforceDuplicateShippingCodePolicy($pdo, $customerId, $orderId, $items);
            if (draftOrderTableHasColumn($pdo, 'orders', 'destination_country_id')) {
                $pdo->prepare("UPDATE orders SET customer_id = ?, supplier_id = ?, expected_ready_date = ?, currency = ?, high_alert_notes = ?, destination_country_id = ?, updated_at=NOW(), lock_version=lock_version+1 WHERE id = ?")
                    ->execute([$customerId, $defaultSupplierId, $expectedDate, $currency, $highAlertNotes, $destinationCountryId, $orderId]);
            } else {
                $pdo->prepare("UPDATE orders SET customer_id = ?, supplier_id = ?, expected_ready_date = ?, currency = ?, high_alert_notes = ?, updated_at=NOW(), lock_version=lock_version+1 WHERE id = ?")
                    ->execute([$customerId, $defaultSupplierId, $expectedDate, $currency, $highAlertNotes, $orderId]);
            }
            draftOrderDeleteExistingItems($pdo, $orderId);
            $insertedItems = draftOrderInsertItems($pdo, $orderId, $defaultSupplierId, $items, $userId);
            draftOrderWriteItemNumberAudit($pdo,$orderId,$itemNumberChanges,$insertedItems,(int)$userId);
            (new ItemNumberReservationService($pdo))->reservePersistedOrder($orderId,(int)$userId);
            $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('order', ?, 'update', ?, ?)")
                ->execute([$orderId, json_encode(['order_type' => 'draft_procurement'], JSON_UNESCAPED_UNICODE), $userId]);
            $pdo->commit();
            $warnings=array_values(array_filter(array_merge($itemNumberWarnings,[$dupWarn])));
            jsonResponse(array_filter(['data' => draftOrderFetchOrderPayload($pdo, $orderId), 'warning' => $warnings?implode(' ',array_unique($warnings)):null]));
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    jsonError('Method not allowed', 405);
};
