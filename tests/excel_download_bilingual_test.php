<?php

require_once dirname(__DIR__) . '/backend/services/OrderExcelService.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;

function bilingualExcelAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$app = file_get_contents($root . '/frontend/js/app.js');
$bulk = file_get_contents($root . '/frontend/js/bulk_excel_download.js');
$orders = file_get_contents($root . '/frontend/js/orders.js');
$receiving = file_get_contents($root . '/frontend/js/receiving.js');
$warehouse = file_get_contents($root . '/frontend/js/warehouse_stock.js');
$drafts = file_get_contents($root . '/frontend/js/procurement_drafts.js');
$excelService = file_get_contents($root . '/backend/services/OrderExcelService.php');

bilingualExcelAssert(
    !str_contains($app, 'Choose Excel language') && !str_contains($app, 'ClmsExcelDownload'),
    'The removed Excel language chooser is still active.'
);
bilingualExcelAssert(
    !str_contains($bulk, 'export_language'),
    'Bulk exports still wait for a selected workbook language.'
);
bilingualExcelAssert(
    str_contains($excelService, "trForLocale(\$label, 'en')"),
    'The shared exporter does not write the English header row explicitly.'
);
bilingualExcelAssert(
    str_contains($excelService, "trForLocale(\$label, 'zh-CN')"),
    'The shared exporter does not write the Chinese header row explicitly.'
);
bilingualExcelAssert(
    str_contains($excelService, '$chineseRow = $row + 1;'),
    'The Chinese header is not placed directly below the English header.'
);
bilingualExcelAssert(
    str_contains($excelService, '$chineseHeaderRow = $headerRow + 1;'),
    'Filtered/list workbooks do not use the same bilingual header order.'
);

foreach ([
    'orders' => $orders,
    'receiving' => $receiving,
    'warehouse stock' => $warehouse,
    'procurement drafts' => $drafts,
] as $page => $source) {
    bilingualExcelAssert(
        !str_contains($source, 'ClmsExcelDownload'),
        ucfirst($page) . ' still invokes the removed language chooser.'
    );
}

$service = new OrderExcelService();
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$standardHeaders = new ReflectionMethod(OrderExcelService::class, 'writeStandardColumnHeaders');
$nextRow = $standardHeaders->invoke($service, $sheet, 8);
bilingualExcelAssert($nextRow === 10, 'Standard item data does not start after both header rows.');
bilingualExcelAssert($sheet->getCell('A8')->getValue() === 'SUPPLIER', 'The English Supplier header is not first.');
bilingualExcelAssert(
    $sheet->getCell('A9')->getValue() === clmsT('SUPPLIER', [], 'zh-CN'),
    'The Chinese Supplier header is not directly below English.'
);
bilingualExcelAssert($sheet->getCell('J8')->getValue() === 'ENGLISH DESCRIPTION', 'The English description header is missing.');
bilingualExcelAssert(
    $sheet->getCell('J9')->getValue() === clmsT('ENGLISH DESCRIPTION', [], 'zh-CN'),
    'The translated description header is missing.'
);

$containerSheet = $spreadsheet->createSheet();
$containerHeaders = new ReflectionMethod(OrderExcelService::class, 'writeContainerColumnHeaders');
$containerNextRow = $containerHeaders->invoke($service, $containerSheet, 8);
bilingualExcelAssert($containerNextRow === 10, 'Container data does not start after both header rows.');
bilingualExcelAssert($containerSheet->getCell('B8')->getValue() === 'WHAT BRAND', 'Container English headers are not first.');
bilingualExcelAssert(
    $containerSheet->getCell('B9')->getValue() === clmsT('WHAT BRAND', [], 'zh-CN'),
    'Container Chinese headers are not second.'
);
$spreadsheet->disconnectWorksheets();

echo "PASS: Excel downloads start directly and shared workbooks use English headers above Chinese headers\n";
