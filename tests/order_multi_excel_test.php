<?php

require_once dirname(__DIR__) . '/backend/services/OrderExcelService.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

function multiExcelAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function createFixtureImage(string $path, array $rgb, string $label): void
{
    $image = imagecreatetruecolor(180, 110);
    if ($image === false) throw new RuntimeException('Could not create workbook test image.');
    $background = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
    $foreground = imagecolorallocate($image, 255, 255, 255);
    imagefilledrectangle($image, 0, 0, 179, 109, $background);
    imagestring($image, 5, 18, 45, $label, $foreground);
    if (!imagepng($image, $path)) {
        imagedestroy($image);
        throw new RuntimeException('Could not save workbook test image.');
    }
    imagedestroy($image);
}

$tempDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'clms_multi_excel_test_' . bin2hex(random_bytes(4));
$backendTempDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'tmp';
$keepArtifact = getenv('CLMS_KEEP_WORKBOOK') === '1';
$artifactDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'output' . DIRECTORY_SEPARATOR . 'verification';
if (!is_dir($tempDir) && !mkdir($tempDir, 0770, true) && !is_dir($tempDir)) {
    throw new RuntimeException('Could not create workbook test directory.');
}
if (!is_dir($backendTempDir) && !mkdir($backendTempDir, 0770, true) && !is_dir($backendTempDir)) {
    throw new RuntimeException('Could not create backend image test directory.');
}
if ($keepArtifact && !is_dir($artifactDir) && !mkdir($artifactDir, 0770, true) && !is_dir($artifactDir)) {
    throw new RuntimeException('Could not create workbook verification directory.');
}

$imageNames = [
    'single' => 'multi_excel_single_' . bin2hex(random_bytes(4)) . '.png',
    'main' => 'multi_excel_main_' . bin2hex(random_bytes(4)) . '.png',
    'alternate' => 'multi_excel_alternate_' . bin2hex(random_bytes(4)) . '.png',
];
createFixtureImage($backendTempDir . DIRECTORY_SEPARATOR . $imageNames['single'], [32, 114, 225], 'SINGLE PHOTO');
createFixtureImage($backendTempDir . DIRECTORY_SEPARATOR . $imageNames['main'], [16, 145, 92], 'MAIN PHOTO');
createFixtureImage($backendTempDir . DIRECTORY_SEPARATOR . $imageNames['alternate'], [208, 76, 65], 'SECOND PHOTO');

$workbookPath = $tempDir . DIRECTORY_SEPARATOR . 'selected_orders.xlsx';
$artifactPath = $artifactDir . DIRECTORY_SEPARATOR . 'combined_orders_visual_verification.xlsx';

$item = static function (string $number, string $english, string $chinese, array $images = []) use ($imageNames): array {
    return [
        'supplier_id' => 8,
        'supplier_name' => '测试 Supplier ' . substr($number, -2),
        'brand' => 'Brand & Co.',
        'materials' => 'Steel / PVC 304#',
        'what_brand' => 'Factory Model ZX-9000',
        'copy_normal_goods' => str_contains($number, '17') ? 'normal' : 'dangerous',
        'code' => 'SKU-' . $number,
        'item_no' => $number,
        'description_en' => $english,
        'description_cn' => $chinese,
        'height' => 12.5,
        'width' => 8.25,
        'length' => 30,
        'cartons' => 2,
        'qty_per_carton' => 5,
        'quantity' => 10,
        'unit' => 'pieces',
        'sell_price' => 3.5,
        'declared_cbm' => 0.25,
        'declared_weight' => 15,
        'express_number' => 'EXP-' . $number,
        'hs_code' => '1234.56',
        'notes' => 'QC: 100% / batch A&B',
        'image_paths' => array_map(static fn(string $key): string => 'tmp/' . $imageNames[$key], $images),
    ];
};

$longEnglish = 'Industrial pressure-control assembly ZX-9000, 220V/50Hz, stainless steel 304, tolerance +/-0.05 mm; handle carefully & keep dry during transit.';
$longChinese = '工业压力控制组件 ZX-9000，220V/50Hz，304 不锈钢，公差 +/-0.05 毫米；运输过程中请小心搬运并保持干燥。';
$singlePhotoWithFallback = $item('ITEM-16-A', 'Single-photo safety valve', '单图安全阀', ['single']);
array_unshift($singlePhotoWithFallback['image_paths'], 'tmp/missing-primary-image.png');
$singlePhotoWithFallback['image_paths'][1] = '/cargochina/backend/' . $singlePhotoWithFallback['image_paths'][1];
$multiPhoto = $item('ITEM-16-B', 'Multi-photo pump assembly', '多图泵组件', []);
$multiPhoto['image_paths'] = [
    ['file_path' => 'tmp/' . $imageNames['main']],
    ['url' => 'tmp/' . $imageNames['alternate']],
];
$thumbnailUrlItem = $item('ITEM-18-A', 'Technical enclosure IP67 (R&D sample #42)', 'IP67 技术外壳（研发样品 #42）', []);
$thumbnailUrlItem['image_paths'] = [
    'http://localhost/cargochina/backend/thumb.php?path=' . rawurlencode('tmp/' . $imageNames['main']) . '&w=96&h=96',
];
$entries = [
    [
        'order' => ['id' => 16, 'customer_name' => 'English Customer', 'destination_country_name' => 'Lebanon', 'expected_ready_date' => '2026-07-20', 'currency' => 'USD', 'status' => 'Approved'],
        'items' => [
            $singlePhotoWithFallback,
            $multiPhoto,
            $item('ITEM-16-C', 'Item intentionally supplied without a photo', '此商品有意不提供照片'),
        ],
    ],
    [
        'order' => ['id' => 17, 'customer_name' => '客户十七', 'destination_country_name' => 'China', 'expected_ready_date' => '2026-07-21', 'currency' => 'RMB', 'status' => 'Received'],
        'items' => [
            $item('ITEM-17-A', $longEnglish, $longChinese, ['single']),
            $item('ITEM-17-B', 'Model A-17 / 24V motor, quantity 12', 'A-17 型 / 24V 电机，数量 12'),
        ],
    ],
    [
        'order' => ['id' => 18, 'customer_name' => 'عميل بيروت / Beirut Customer', 'destination_country_name' => 'United Arab Emirates', 'expected_ready_date' => '2026-08-01', 'currency' => 'USD', 'status' => 'Submitted'],
        'items' => [
            $thumbnailUrlItem,
            $item('ITEM-18-B', 'No-photo spare parts: bolts, washers & seals', '无照片备件：螺栓、垫圈和密封件'),
        ],
    ],
];

try {
    $collectItemImages = new ReflectionMethod(OrderExcelService::class, 'collectItemImagePaths');
    $summaryPaths = $collectItemImages->invoke(new OrderExcelService(), [
        $item('SUMMARY-NO-PHOTO', 'No photo first', '首项无图'),
        $singlePhotoWithFallback,
    ]);
    multiExcelAssert(count($summaryPaths) === 2, 'Summary exports did not search beyond the first item for a usable photo.');

    (new OrderExcelService())->saveSelectedOrdersXlsx($entries, $workbookPath);
    multiExcelAssert(is_file($workbookPath) && filesize($workbookPath) > 0, 'Workbook was not created.');

    $spreadsheet = IOFactory::load($workbookPath);
    multiExcelAssert($spreadsheet->getSheetCount() === 1, 'Selected orders must be in one worksheet.');
    $sheet = $spreadsheet->getActiveSheet();
    $titleRows = [];
    $englishHeaderRows = [];
    $chineseHeaderRows = [];
    $itemRows = [];
    for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
        $a = trim((string) $sheet->getCell('A' . $row)->getValue());
        if (preg_match('/Order #(16|17|18) /', $a, $match)) $titleRows[(int) $match[1]] = $row;
        if (trim((string) $sheet->getCell('J' . $row)->getValue()) === 'ENGLISH DESCRIPTION'
            && trim((string) $sheet->getCell('K' . $row)->getValue()) === 'CHINESE DESCRIPTION') {
            $englishHeaderRows[] = $row;
        }
        if (trim((string) $sheet->getCell('J' . $row)->getValue()) === clmsT('ENGLISH DESCRIPTION', [], 'zh-CN')
            && trim((string) $sheet->getCell('K' . $row)->getValue()) === clmsT('CHINESE DESCRIPTION', [], 'zh-CN')) {
            $chineseHeaderRows[] = $row;
        }
        $itemNumber = trim((string) $sheet->getCell('I' . $row)->getValue());
        if (str_starts_with($itemNumber, 'ITEM-')) $itemRows[$itemNumber] = $row;
    }
    multiExcelAssert(count($titleRows) === 3, 'Each selected order needs a repeated complete order header.');
    multiExcelAssert(count($englishHeaderRows) === 3, 'Each selected order needs a repeated English item header.');
    multiExcelAssert(count($chineseHeaderRows) === 3, 'Each selected order needs a repeated Chinese item header.');
    foreach ($englishHeaderRows as $index => $englishHeaderRow) {
        multiExcelAssert(
            ($chineseHeaderRows[$index] ?? 0) === $englishHeaderRow + 1,
            'The Chinese header must be directly below the English header.'
        );
    }
    multiExcelAssert(count($itemRows) === 7, 'Every item from all three orders must be present.');
    foreach ([16 => '2026-07-20', 17 => '2026-07-21', 18 => '2026-08-01'] as $orderId => $expectedDate) {
        $row = $titleRows[$orderId] + 4;
        $actualDate = ExcelDate::excelToDateTimeObject((float) $sheet->getCell('B' . $row)->getValue())->format('Y-m-d');
        multiExcelAssert($actualDate === $expectedDate, "Expected-ready date shifted in Excel at row {$row}: {$actualDate}");
    }
    multiExcelAssert($sheet->getCell('J' . $itemRows['ITEM-17-A'])->getValue() === $longEnglish, 'Long English description is missing.');
    multiExcelAssert($sheet->getCell('K' . $itemRows['ITEM-17-A'])->getValue() === $longChinese, 'Long Chinese description is missing.');
    multiExcelAssert($sheet->getCell('H' . $itemRows['ITEM-16-C'])->getValue() === 'No photo', 'No-photo item must have a clean fallback.');

    $drawings = iterator_to_array($sheet->getDrawingCollection());
    $anchors = array_map(static fn($drawing) => $drawing->getCoordinates(), $drawings);
    $expectedAnchors = [
        'H' . $itemRows['ITEM-16-A'],
        'H' . $itemRows['ITEM-16-B'],
        'H' . $itemRows['ITEM-17-A'],
        'H' . $itemRows['ITEM-18-A'],
    ];
    multiExcelAssert($anchors === $expectedAnchors, 'Images are not anchored to their corresponding PHOTO cells: ' . implode(', ', $anchors));
    multiExcelAssert(count($drawings) === 4, 'The established main-image rule should embed one image per image-bearing item.');
    multiExcelAssert(count($sheet->getRowBreaks()) === 2, 'A page break is required between each order section.');
    $spreadsheet->disconnectWorksheets();

    $zip = new ZipArchive();
    multiExcelAssert($zip->open($workbookPath) === true, 'Generated XLSX archive cannot be opened.');
    $mediaFiles = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
        if (str_starts_with($name, 'xl/media/')) $mediaFiles[] = $name;
    }
    $zip->close();
    multiExcelAssert(count($mediaFiles) >= 2, 'Physical image media is missing from the XLSX archive.');

    if ($keepArtifact) {
        if (!copy($workbookPath, $artifactPath)) throw new RuntimeException('Could not preserve verification workbook.');
        echo 'ARTIFACT: ' . $artifactPath . PHP_EOL;
    }
    echo "PASS: three selected orders use one worksheet with English headers above Chinese headers, bilingual descriptions, page breaks, and correctly anchored images\n";
} finally {
    @unlink($workbookPath);
    foreach ($imageNames as $imageName) @unlink($backendTempDir . DIRECTORY_SEPARATOR . $imageName);
    @rmdir($tempDir);
}
