<?php

require_once dirname(__DIR__) . '/backend/services/OrderExcelService.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

function multiExcelAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$tempDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'clms_multi_excel_test_' . bin2hex(random_bytes(4));
$backendTempDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'tmp';
if (!is_dir($tempDir) && !mkdir($tempDir, 0770, true) && !is_dir($tempDir)) {
    throw new RuntimeException('Could not create workbook test directory.');
}
if (!is_dir($backendTempDir) && !mkdir($backendTempDir, 0770, true) && !is_dir($backendTempDir)) {
    throw new RuntimeException('Could not create backend image test directory.');
}

$imageName = 'multi_excel_' . bin2hex(random_bytes(4)) . '.png';
$imagePath = $backendTempDir . DIRECTORY_SEPARATOR . $imageName;
$workbookPath = $tempDir . DIRECTORY_SEPARATOR . 'selected_orders.xlsx';
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZlD8AAAAASUVORK5CYII=', true);
if ($png === false || file_put_contents($imagePath, $png) === false) {
    throw new RuntimeException('Could not create workbook test image.');
}

$item = static function (string $number, string $english, string $chinese) use ($imageName): array {
    return [
        'supplier_id' => 8,
        'supplier_name' => '测试 Supplier',
        'brand' => 'Brand',
        'materials' => 'Steel',
        'what_brand' => 'Factory Brand',
        'copy_normal_goods' => 'dangerous',
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
        'image_paths' => ['tmp/' . $imageName],
    ];
};

$entries = [
    [
        'order' => ['id' => 16, 'customer_name' => 'English Customer', 'destination_country_name' => 'Lebanon', 'expected_ready_date' => '2026-07-20', 'currency' => 'USD', 'status' => 'Approved'],
        'items' => [$item('ITEM-16', 'English description sixteen', '中文描述十六')],
    ],
    [
        'order' => ['id' => 17, 'customer_name' => '客户十七', 'destination_country_name' => 'China', 'expected_ready_date' => '2026-07-21', 'currency' => 'RMB', 'status' => 'Received'],
        'items' => [$item('ITEM-17', 'English description seventeen', '中文描述十七')],
    ],
];

try {
    (new OrderExcelService())->saveSelectedOrdersXlsx($entries, $workbookPath);
    multiExcelAssert(is_file($workbookPath) && filesize($workbookPath) > 0, 'Workbook was not created.');

    $spreadsheet = IOFactory::load($workbookPath);
    multiExcelAssert($spreadsheet->getSheetCount() === 1, 'Selected orders must be in one worksheet.');
    $sheet = $spreadsheet->getActiveSheet();
    $titles = [];
    $headerRows = [];
    for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
        $a = trim((string) $sheet->getCell('A' . $row)->getValue());
        if (str_contains($a, 'Order #16') || str_contains($a, 'Order #17')) $titles[] = $a;
        if (trim((string) $sheet->getCell('J' . $row)->getValue()) === 'ENGLISH DESCRIPTION'
            && trim((string) $sheet->getCell('K' . $row)->getValue()) === 'CHINESE DESCRIPTION') {
            $headerRows[] = $row;
        }
    }
    multiExcelAssert(count($titles) === 2, 'Each selected order needs a repeated complete order header.');
    multiExcelAssert(count($headerRows) === 2, 'Each selected order needs repeated bilingual item headers.');
    multiExcelAssert($sheet->getCell('J10')->getValue() === 'English description sixteen', 'First English description is missing.');
    multiExcelAssert($sheet->getCell('K10')->getValue() === '中文描述十六', 'First Chinese description is missing.');
    multiExcelAssert(count($sheet->getDrawingCollection()) === 2, 'Both item images must be embedded as drawings.');
    $drawings = iterator_to_array($sheet->getDrawingCollection());
    $anchors = array_map(static fn($drawing) => $drawing->getCoordinates(), $drawings);
    multiExcelAssert($anchors === ['H10', 'H22'], 'Images are not anchored to their corresponding PHOTO cells: ' . implode(', ', $anchors));
    $spreadsheet->disconnectWorksheets();

    $zip = new ZipArchive();
    multiExcelAssert($zip->open($workbookPath) === true, 'Generated XLSX archive cannot be opened.');
    $mediaFiles = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (str_starts_with($name, 'xl/media/')) $mediaFiles[] = $name;
    }
    $zip->close();
    multiExcelAssert(count($mediaFiles) >= 1, 'No physical image media exists inside the XLSX archive.');

    echo "PASS: selected orders use one worksheet with repeated headers, bilingual descriptions, and embedded images\n";
} finally {
    @unlink($workbookPath);
    @unlink($imagePath);
    @rmdir($tempDir);
}
