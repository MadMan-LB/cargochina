<?php

require_once dirname(__DIR__) . '/backend/config/database.php';
require_once dirname(__DIR__) . '/backend/api/handlers/orders.php';
require_once dirname(__DIR__) . '/backend/api/handlers/draft-orders.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

function sharedCartonImageAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function sharedCartonFixtureImage(string $path, array $rgb, string $label): void
{
    $image = imagecreatetruecolor(160, 100);
    if ($image === false) {
        throw new RuntimeException('Could not create shared-carton image fixture.');
    }
    imagefilledrectangle($image, 0, 0, 159, 99, imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]));
    imagestring($image, 4, 12, 42, $label, imagecolorallocate($image, 255, 255, 255));
    if (!imagepng($image, $path)) {
        imagedestroy($image);
        throw new RuntimeException('Could not save shared-carton image fixture.');
    }
    imagedestroy($image);
}

function sharedCartonWorkbookMediaCount(string $path): int
{
    $zip = new ZipArchive();
    sharedCartonImageAssert($zip->open($path) === true, 'Generated shared-carton workbook is not a valid XLSX archive.');
    $count = 0;
    for ($index = 0; $index < $zip->numFiles; $index++) {
        if (str_starts_with(str_replace('\\', '/', (string) $zip->getNameIndex($index)), 'xl/media/')) {
            $count++;
        }
    }
    $zip->close();
    return $count;
}

$pdo = getDb();
$uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'uploads';
$tempDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'clms_shared_carton_excel_' . bin2hex(random_bytes(4));
if (!is_dir($uploadDir) || (!is_dir($tempDir) && !mkdir($tempDir, 0770, true) && !is_dir($tempDir))) {
    throw new RuntimeException('Shared-carton image test directories are unavailable.');
}

$suffix = bin2hex(random_bytes(5));
$productRelative = 'uploads/shared_product_' . $suffix . '.png';
$explicitRelative = 'uploads/shared_explicit_' . $suffix . '.png';
$invalidRelative = 'uploads/shared_invalid_' . $suffix . '.png';
$productAbsolute = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $productRelative);
$explicitAbsolute = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $explicitRelative);
$invalidAbsolute = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $invalidRelative);
$workbookPath = $tempDir . DIRECTORY_SEPARATOR . 'shared_carton_images.xlsx';

sharedCartonFixtureImage($productAbsolute, [31, 99, 214], 'PRODUCT');
sharedCartonFixtureImage($explicitAbsolute, [18, 142, 86], 'EXPLICIT');
file_put_contents($invalidAbsolute, 'not an image');

try {
    $pdo->beginTransaction();
    $supplierCode = 'IMG-' . strtoupper($suffix);
    $supplierStmt = $pdo->prepare('INSERT INTO suppliers (code, name) VALUES (?, ?)');
    $supplierStmt->execute([$supplierCode, 'Shared Carton Image Test']);
    $supplierId = (int) $pdo->lastInsertId();

    $productStmt = $pdo->prepare(
        'INSERT INTO products (supplier_id, cbm, weight, description_cn, description_en, image_paths) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $productStmt->execute([
        $supplierId,
        0.01,
        1.25,
        '共享纸箱测试产品',
        'Shared carton test product',
        json_encode([$productRelative]),
    ]);
    $productId = (int) $pdo->lastInsertId();

    $normalized = draftOrderNormalizeSharedCartonContents($pdo, [[
        'product_id' => $productId,
        'supplier_id' => $supplierId,
        'item_no' => 'SHARED-CHILD-1',
        'item_no_source' => 'manual',
        'quantity_per_carton' => 2,
        'description_cn' => '共享纸箱测试产品',
        'description_en' => 'Shared carton test product',
        'image_paths' => [$explicitRelative, $productRelative],
    ]], $supplierId, 3);
    sharedCartonImageAssert(
        ($normalized[0]['image_paths'] ?? []) === [$explicitRelative, $productRelative],
        'Explicit and product shared-carton images were not preserved in precedence order.'
    );

    $legacyJson = json_encode([[
        'product_id' => $productId,
        'supplier_id' => $supplierId,
        'item_no' => 'SHARED-LEGACY-1',
        'quantity_per_carton' => 2,
        'description_cn' => '旧共享纸箱产品',
        'description_en' => 'Legacy shared-carton product',
    ]], JSON_UNESCAPED_UNICODE);
    $draftLegacy = draftOrderDecodeSharedCartonContents($pdo, [
        'shared_carton_contents' => $legacyJson,
        'cartons' => 3,
    ]);
    $orderLegacy = orderDecodeSharedCartonContents($pdo, [
        'shared_carton_contents' => $legacyJson,
        'cartons' => 3,
    ]);
    sharedCartonImageAssert(
        ($draftLegacy[0]['image_paths'] ?? []) === [$productRelative]
            && ($orderLegacy[0]['image_paths'] ?? []) === [$productRelative]
            && ($draftLegacy[0]['product_image_paths'] ?? []) === [$productRelative]
            && ($orderLegacy[0]['product_image_paths'] ?? []) === [$productRelative],
        'Legacy shared-carton JSON was not hydrated from the linked product image.'
    );
    $summaryItems = normalizeOrderItems($pdo, [[
        'id' => 99101,
        'shared_carton_enabled' => 1,
        'shared_carton_contents' => $legacyJson,
        'cartons' => 3,
        'image_paths' => [],
        'product_image_paths' => [],
        'receipt_image_paths' => ['uploads/receipt_fallback_' . $suffix . '.png'],
    ]]);
    sharedCartonImageAssert(
        ($summaryItems[0]['image_paths'] ?? []) === [$productRelative, 'uploads/receipt_fallback_' . $suffix . '.png'],
        'Shared-carton summary image precedence did not place child product images before receipt evidence.'
    );

    $sections = [[
        'supplier_id' => $supplierId,
        'supplier_name' => 'Shared Carton Image Test',
        'items' => [[
            'id' => 99101,
            'product_id' => null,
            'item_no' => 'SHARED-SUMMARY-1',
            'shared_carton_enabled' => 1,
            'shared_carton_code' => 'SHARED-SUMMARY-1',
            'shared_carton_contents' => $normalized,
            'cartons' => 3,
            'pieces_per_carton' => 2,
            'quantity' => 6,
            'photo_paths' => [],
        ]],
    ]];
    $rows = draftOrderBuildExportRows($sections);
    sharedCartonImageAssert(count($rows) === 2, 'Shared-carton export did not produce summary and child rows.');
    sharedCartonImageAssert(
        ($rows[0]['image_paths'] ?? []) === [$explicitRelative, $productRelative]
            && ($rows[1]['image_paths'] ?? []) === [$explicitRelative, $productRelative],
        'Shared-carton summary or child export row lost its image paths.'
    );

    $rows[0]['qty_per_carton'] = $rows[0]['pieces_per_carton'] ?? 2;
    $rows[1]['qty_per_carton'] = $rows[1]['pieces_per_carton'] ?? 2;
    $rows[0]['image_paths'] = ['uploads/missing_' . $suffix . '.png', $invalidRelative, $productRelative];
    // Simulate a stale/partially deployed export builder that omitted the child
    // photo payload. The shared Excel layer must recover it by trusted product_id.
    $rows[1]['image_paths'] = [];
    (new OrderExcelService($pdo))->saveOrderXlsx([
        'id' => 991,
        'customer_name' => 'Shared Carton Test Customer',
        'status' => 'Draft',
        'currency' => 'RMB',
    ], $rows, $workbookPath);

    $spreadsheet = IOFactory::load($workbookPath);
    $sheet = $spreadsheet->getActiveSheet();
    $itemRows = [];
    for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
        $itemNo = trim((string) $sheet->getCell('I' . $row)->getValue());
        if ($itemNo !== '') {
            $itemRows[$itemNo] = $row;
        }
    }
    $anchors = array_map(
        static fn($drawing): string => $drawing->getCoordinates(),
        iterator_to_array($sheet->getDrawingCollection())
    );
    sharedCartonImageAssert(isset($itemRows['SHARED-SUMMARY-1'], $itemRows['SHARED-CHILD-1']), 'Shared-carton workbook rows are missing.');
    sharedCartonImageAssert($anchors === [
        'H' . $itemRows['SHARED-SUMMARY-1'],
        'H' . $itemRows['SHARED-CHILD-1'],
    ], 'Shared-carton images are not anchored to their own summary and child rows.');
    $spreadsheet->disconnectWorksheets();
    sharedCartonImageAssert(sharedCartonWorkbookMediaCount($workbookPath) >= 1, 'Shared-carton XLSX contains no physical image media.');

    if (getenv('CLMS_KEEP_WORKBOOK') === '1') {
        $verificationDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'output' . DIRECTORY_SEPARATOR . 'verification';
        if (!is_dir($verificationDir) && !mkdir($verificationDir, 0770, true) && !is_dir($verificationDir)) {
            throw new RuntimeException('Could not create the workbook verification directory.');
        }
        $verificationPath = $verificationDir . DIRECTORY_SEPARATOR . 'shared_carton_image_verification.xlsx';
        sharedCartonImageAssert(copy($workbookPath, $verificationPath), 'Could not preserve the shared-carton verification workbook.');
        echo 'ARTIFACT: ' . $verificationPath . "\n";
    }

    echo "PASS: shared-carton photos persist, canonical product fallback repairs an omitted payload, stale candidates fall back, and XLSX anchors remain correct\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    @unlink($workbookPath);
    @unlink($productAbsolute);
    @unlink($explicitAbsolute);
    @unlink($invalidAbsolute);
    @rmdir($tempDir);
}
