<?php

/**
 * Draft order builder regression tests
 * Run: php tests/draft_order_builder_test.php
 */

$root = dirname(__DIR__);
require_once $root . '/backend/config/database.php';
require_once $root . '/backend/services/ReceivingExcelImportService.php';
require_once $root . '/backend/services/DraftOrderCostService.php';
require_once $root . '/backend/services/TranslationService.php';
require_once $root . '/vendor/autoload.php';

try {
    $pdo = getDb();
} catch (Throwable $e) {
    echo "SKIP: Database unavailable (" . $e->getMessage() . ")\n";
    exit(0);
}

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "PASS: $name\n";
        $passed++;
    } catch (Throwable $e) {
        echo "FAIL: $name - " . $e->getMessage() . "\n";
        $failed++;
    }
}

function runHandlerScript(string $root, string $handlerPath, string $method, ?string $id, ?string $action, array $query = [], array $body = []): string
{
    $rootEsc = addslashes(str_replace('\\', '/', $root));
    $handlerEsc = addslashes($handlerPath);
    $queryCode = var_export($query, true);
    $bodyCode = var_export($body, true);
    $idCode = $id === null ? 'null' : var_export($id, true);
    $actionCode = $action === null ? 'null' : var_export($action, true);
    $methodCode = var_export($method, true);
    $code = "<?php\nsession_start();\n\$_SESSION['user_id'] = 1;\n\$_SESSION['user_roles'] = ['ChinaAdmin'];\n\$_GET = $queryCode;\nrequire '$rootEsc/backend/config/database.php';\nrequire '$rootEsc/backend/api/helpers.php';\n\$h = require '$rootEsc/$handlerEsc';\n\$h($methodCode, $idCode, $actionCode, $bodyCode);\n";
    $tmp = tempnam(sys_get_temp_dir(), 'draft_builder_test_');
    file_put_contents($tmp . '.php', $code);
    $php = (defined('PHP_BINARY') && file_exists(PHP_BINARY)) ? PHP_BINARY : 'php';
    $out = shell_exec(escapeshellcmd($php) . ' ' . escapeshellarg($tmp . '.php') . ' 2>&1');
    @unlink($tmp . '.php');
    @unlink($tmp);
    return $out ?? '';
}

function runHandlerScriptWithUploadedFile(string $root, string $handlerPath, string $method, ?string $id, ?string $action, string $uploadedFilePath, string $uploadedFileName, array $query = [], array $body = []): string
{
    $rootEsc = addslashes(str_replace('\\', '/', $root));
    $handlerEsc = addslashes($handlerPath);
    $queryCode = var_export($query, true);
    $bodyCode = var_export($body, true);
    $fileCode = var_export([
        'name' => $uploadedFileName,
        'type' => 'text/csv',
        'tmp_name' => $uploadedFilePath,
        'error' => UPLOAD_ERR_OK,
        'size' => is_file($uploadedFilePath) ? filesize($uploadedFilePath) : 0,
    ], true);
    $idCode = $id === null ? 'null' : var_export($id, true);
    $actionCode = $action === null ? 'null' : var_export($action, true);
    $methodCode = var_export($method, true);
    $code = "<?php\nsession_start();\n\$_SESSION['user_id'] = 1;\n\$_SESSION['user_roles'] = ['ChinaAdmin'];\n\$_GET = $queryCode;\n\$_FILES = ['file' => $fileCode];\nrequire '$rootEsc/backend/config/database.php';\nrequire '$rootEsc/backend/api/helpers.php';\n\$h = require '$rootEsc/$handlerEsc';\n\$h($methodCode, $idCode, $actionCode, $bodyCode);\n";
    $tmp = tempnam(sys_get_temp_dir(), 'draft_builder_test_');
    file_put_contents($tmp . '.php', $code);
    $php = (defined('PHP_BINARY') && file_exists(PHP_BINARY)) ? PHP_BINARY : 'php';
    $out = shell_exec(escapeshellcmd($php) . ' ' . escapeshellarg($tmp . '.php') . ' 2>&1');
    @unlink($tmp . '.php');
    @unlink($tmp);
    return $out ?? '';
}

function cleanupCreatedOrder(PDO $pdo, int $orderId, ?string $createdProductDescription = null): void
{
    $pdo->prepare("DELETE FROM shipment_financial_entries WHERE order_id = ?")->execute([$orderId]);
    $pdo->prepare("DELETE FROM item_number_references WHERE order_id = ?")->execute([$orderId]);
    $pdo->exec("DELETE r FROM item_number_reservations r LEFT JOIN item_number_references ref ON ref.reservation_id=r.id WHERE ref.id IS NULL");
    $itemStmt = $pdo->prepare("SELECT id FROM order_items WHERE order_id = ?");
    $itemStmt->execute([$orderId]);
    $itemIds = array_map('intval', $itemStmt->fetchAll(PDO::FETCH_COLUMN));
    foreach ($itemIds as $itemId) {
        $pdo->prepare("DELETE FROM design_attachments WHERE entity_type = 'order_item' AND entity_id = ?")->execute([$itemId]);
    }

    $pdo->prepare("DELETE FROM notifications WHERE title = ?")->execute(['Order #' . $orderId . ' created']);
    $pdo->prepare("DELETE FROM audit_log WHERE entity_type = 'order' AND entity_id = ?")->execute([$orderId]);
    $pdo->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$orderId]);
    $pdo->prepare("DELETE FROM orders WHERE id = ?")->execute([$orderId]);

    if ($createdProductDescription !== null) {
        $productIdsStmt = $pdo->prepare("SELECT id FROM products WHERE description_cn = ? OR description_en = ?");
        $productIdsStmt->execute([$createdProductDescription, $createdProductDescription]);
        $productIds = array_map('intval', $productIdsStmt->fetchAll(PDO::FETCH_COLUMN));
        foreach ($productIds as $productId) {
            $pdo->prepare("DELETE FROM product_description_entries WHERE product_id = ?")->execute([$productId]);
            $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$productId]);
        }
    }
}

function bilingualDescriptionEntry(string $english): array
{
    return [
        'description_text' => '测试 ' . $english,
        'description_translated' => $english,
    ];
}

function startDraftCreateProcess(string $root, array $body): array
{
    $rootEsc=addslashes(str_replace('\\','/',$root));
    $bodyCode=var_export($body,true);
    $code="<?php\nsession_start();\n\$_SESSION['user_id']=1;\n\$_SESSION['user_roles']=['ChinaAdmin'];\nrequire '$rootEsc/backend/config/database.php';\nrequire '$rootEsc/backend/api/helpers.php';\n\$h=require '$rootEsc/backend/api/handlers/draft-orders.php';\n\$h('POST',null,null,$bodyCode);\n";
    $tmp=tempnam(sys_get_temp_dir(),'draft_concurrency_').'.php';
    file_put_contents($tmp,$code);
    $php=(defined('PHP_BINARY')&&file_exists(PHP_BINARY))?PHP_BINARY:'php';
    $pipes=[];
    $process=proc_open([$php,$tmp],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process)){@unlink($tmp);throw new RuntimeException('Could not start concurrent draft worker');}
    fclose($pipes[0]);
    return ['process'=>$process,'pipes'=>$pipes,'tmp'=>$tmp];
}

function finishDraftCreateProcess(array $worker): string
{
    $stdout=stream_get_contents($worker['pipes'][1]);
    $stderr=stream_get_contents($worker['pipes'][2]);
    fclose($worker['pipes'][1]); fclose($worker['pipes'][2]);
    $exit=proc_close($worker['process']);
    @unlink($worker['tmp']);
    if($exit!==0) throw new RuntimeException('Concurrent draft worker failed: '.trim($stderr));
    return (string)$stdout;
}

function createProcurementImportTemplateFixture(?array $itemRow = null, array $metadata = [], ?string $photoPath = null): string
{
    $path = tempnam(sys_get_temp_dir(), 'procurement_template_');
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Procurement Import');
    $sheet->setCellValue('A1', 'Procurement Import Template');
    $sheet->mergeCells('A1:S1');

    $metadataRows = [
        ['Customer', $metadata['customer'] ?? ''],
        ['Destination Country', $metadata['destination_country'] ?? ''],
        ['Expected Ready', $metadata['expected_ready'] ?? ''],
        ['Currency', $metadata['currency'] ?? ''],
    ];
    $row = 3;
    foreach ($metadataRows as $entry) {
        $sheet->fromArray($entry, null, 'A' . $row);
        $row++;
    }

    $row++;
    $headers = [
        'Photo',
        'Item No',
        'English Item Name',
        'Chinese Item Name',
        'SKU / Item Code',
        'Quantity',
        'Unit',
        'Pieces/Carton',
        'Cartons',
        'Factory Price',
        'Customer Price',
        'Total Amount',
        'CBM/Unit',
        'Total CBM',
        'Weight/Unit',
        'Total Weight',
        'Supplier',
        'HS Code',
        'Notes / Description',
        'Custom Design',
    ];
    $sheet->fromArray($headers, null, 'A' . $row);
    if ($itemRow !== null) {
        $sheet->fromArray($itemRow, null, 'A' . ($row + 1));
        $sheet->getRowDimension($row + 1)->setRowHeight(72);
    }
    if ($photoPath !== null && is_file($photoPath)) {
        $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
        $drawing->setPath($photoPath);
        $drawing->setCoordinates('A' . ($row + 1));
        $drawing->setResizeProportional(false);
        $drawing->setWidth(90);
        $drawing->setHeight(90);
        $drawing->setWorksheet($sheet);
    }

    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();

    return $path;
}

test('order_items draft builder columns exist', function () use ($pdo) {
    $cols = $pdo->query("SHOW COLUMNS FROM order_items WHERE Field IN ('hs_code','custom_design_required','custom_design_note')")->fetchAll(PDO::FETCH_COLUMN);
    if (count($cols) !== 3) {
        throw new Exception('Missing draft builder order_items columns');
    }
});

test('orders.expected_ready_date is nullable', function () use ($pdo) {
    $row = $pdo->query("SHOW COLUMNS FROM orders WHERE Field = 'expected_ready_date'")->fetch(PDO::FETCH_ASSOC);
    if (!$row || strtoupper((string) ($row['Null'] ?? 'NO')) !== 'YES') {
        throw new Exception('expected_ready_date is still NOT NULL');
    }
});

test('draft-orders RBAC is operational', function () {
    $rbac = require dirname(__DIR__) . '/backend/config/rbac.php';
    $expected = ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'ContainersStaff', 'FieldStaff', 'SuperAdmin'];
    if (($rbac['draft-orders'] ?? null) !== $expected) {
        throw new Exception('Unexpected draft-orders RBAC mapping');
    }
    if (($rbac['translations']['write'] ?? null) !== $expected) {
        throw new Exception('Translation endpoint is not limited to operational roles');
    }
});

test('draft-orders list endpoint returns JSON array', function () use ($root) {
    $out = runHandlerScript($root, 'backend/api/handlers/draft-orders.php', 'GET', null, null);
    $json = json_decode($out, true);
    if (!is_array($json) || !array_key_exists('data', $json) || !is_array($json['data'])) {
        throw new Exception('Expected {data: []}, got: ' . substr($out, 0, 200));
    }
});

test('draft-orders import endpoint previews exported CSV without saving', function () use ($pdo, $root) {
    $customer = $pdo->query("SELECT id, name FROM customers ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $supplier = $pdo->query("SELECT id, name FROM suppliers ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$customer || !$supplier) {
        throw new Exception('Missing customer or supplier seed data');
    }

    $ordersBefore = (int) $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    $csvPath = tempnam(sys_get_temp_dir(), 'draft_import_');
    $fh = fopen($csvPath, 'w');
    fputcsv($fh, ['Draft Order', '#999']);
    fputcsv($fh, ['Customer', $customer['name']]);
    fputcsv($fh, ['Expected Ready', '2026-05-01']);
    fputcsv($fh, ['Currency', 'RMB']);
    fputcsv($fh, ['']);
    fputcsv($fh, ['Supplier:', $supplier['name']]);
    fputcsv($fh, ['Item No', 'Product / Names', 'HS Code', 'Pieces/Carton', 'Cartons', 'Quantity', 'Factory Price', 'Customer Price', 'Total Amount', 'CBM/Unit', 'Total CBM', 'Weight/Unit', 'Total Weight', 'Custom Design']);
    fputcsv($fh, ['IMP-1-1', 'Imported preview item', '9503.00', '12', '2', '24', '3.5', '4', '96', '0.05', '0.1', '1.2', '2.4', 'No']);
    fclose($fh);

    try {
        $out = runHandlerScriptWithUploadedFile(
            $root,
            'backend/api/handlers/draft-orders.php',
            'POST',
            'import',
            null,
            $csvPath,
            'draft_import.csv'
        );
        $json = json_decode($out, true);
        if (!is_array($json) || empty($json['data']['supplier_sections'][0]['items'][0])) {
            throw new Exception('Expected imported preview payload, got: ' . substr($out, 0, 300));
        }
        if ((int) ($json['data']['customer']['id'] ?? 0) !== (int) $customer['id']) {
            throw new Exception('Customer was not resolved from export metadata');
        }
        if ((int) ($json['data']['supplier_sections'][0]['supplier_id'] ?? 0) !== (int) $supplier['id']) {
            throw new Exception('Supplier was not resolved from export section');
        }
        $item = $json['data']['supplier_sections'][0]['items'][0];
        if (($item['item_no'] ?? '') !== 'IMP-1-1' || (int) ($item['item_no_manual'] ?? 0) !== 1) {
            throw new Exception('Imported item numbers should be preserved from the file');
        }
        if (($item['cartons'] ?? '') !== '2' || ($item['pieces_per_carton'] ?? '') !== '12') {
            throw new Exception('Packaging fields were not imported');
        }
        if (($item['cbm'] ?? '') !== '0.05' || ($item['weight'] ?? '') !== '1.2') {
            throw new Exception('Per-unit CBM/weight were not imported');
        }
        $ordersAfter = (int) $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
        if ($ordersAfter !== $ordersBefore) {
            throw new Exception('Import preview created an order unexpectedly');
        }
    } finally {
        @unlink($csvPath);
    }
});

test('draft-orders import endpoint previews exported XLSX unit price as customer price', function () use ($pdo, $root) {
    $supplier = $pdo->query("SELECT id, name FROM suppliers ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$supplier) {
        throw new Exception('Missing supplier seed data');
    }

    $xlsxPath = tempnam(sys_get_temp_dir(), 'draft_xlsx_import_');
    $pngPath = tempnam(sys_get_temp_dir(), 'draft_xlsx_img_') . '.png';
    file_put_contents($pngPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(
        ['PHOTO', 'ITEM NO', 'SUPPLIER', 'DESCRIPTION', 'TOTAL CTNS', 'QTY/CTN', 'TOTAL QTY', 'UNIT PRICE', 'TOTAL AMOUNT', 'CBM', 'TOTAL CBM', 'GWKG', 'TOTAL GW'],
        null,
        'B1'
    );
    $sheet->fromArray(
        ['', 'XLS-1', $supplier['name'], 'Imported xlsx item', 1, 6, 6, 9.5, 57, 0.2, 0.2, 1, 1],
        null,
        'B2'
    );
    $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
    $drawing->setPath($pngPath);
    $drawing->setCoordinates('B2');
    $drawing->setResizeProportional(false);
    $drawing->setWidth(90);
    $drawing->setHeight(270);
    $drawing->setWorksheet($sheet);
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($xlsxPath);
    $spreadsheet->disconnectWorksheets();

    try {
        $out = runHandlerScriptWithUploadedFile(
            $root,
            'backend/api/handlers/draft-orders.php',
            'POST',
            'import',
            null,
            $xlsxPath,
            'draft_import.xlsx'
        );
        $json = json_decode($out, true);
        $item = $json['data']['supplier_sections'][0]['items'][0] ?? null;
        if (!$item) {
            throw new Exception('Expected imported xlsx item, got: ' . substr($out, 0, 300));
        }
        if (($item['unit_price'] ?? 'not-empty') !== '' || ($item['sell_price'] ?? '') !== '9.5') {
            throw new Exception('XLSX UNIT PRICE should import as customer price while factory price stays empty');
        }
        if (($item['item_no'] ?? '') !== 'XLS-1' || (int) ($item['item_no_manual'] ?? 0) !== 1) {
            throw new Exception('XLSX item numbers should be preserved from the file');
        }
        if (($item['cartons'] ?? '') !== '1' || ($item['pieces_per_carton'] ?? '') !== '6') {
            throw new Exception('XLSX packaging fields were not imported');
        }
        $photoPaths = $item['photo_paths'] ?? [];
        if (count($photoPaths) !== 1 || !is_file($root . '/backend/' . $photoPaths[0])) {
            throw new Exception('Embedded XLSX image was not imported into the item photo paths');
        }
        @unlink($root . '/backend/' . $photoPaths[0]);
    } finally {
        @unlink($xlsxPath);
        @unlink($pngPath);
    }
});

test('draft-orders import endpoint explains blank official template', function () use ($root) {
    $xlsxPath = createProcurementImportTemplateFixture();

    try {
        $out = runHandlerScriptWithUploadedFile(
            $root,
            'backend/api/handlers/draft-orders.php',
            'POST',
            'import',
            null,
            $xlsxPath,
            'procurement_import_template.xlsx'
        );
        $json = json_decode($out, true);
        if (!is_array($json) || empty($json['error'])) {
            throw new Exception('Expected blank template import to return a clear error, got: ' . substr($out, 0, 300));
        }
        if (strpos((string) ($json['message'] ?? ''), 'no item rows were filled in') === false) {
            throw new Exception('Expected blank template guidance, got: ' . substr($out, 0, 300));
        }
    } finally {
        @unlink($xlsxPath);
    }
});

test('draft-orders import endpoint previews filled official template', function () use ($pdo, $root) {
    $customer = $pdo->query("SELECT id, name FROM customers ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $supplier = $pdo->query("SELECT id, name FROM suppliers ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$customer || !$supplier) {
        throw new Exception('Missing customer or supplier seed data');
    }

    $ordersBefore = (int) $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    $pngPath = tempnam(sys_get_temp_dir(), 'official_template_photo_') . '.png';
    file_put_contents($pngPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));
    $xlsxPath = createProcurementImportTemplateFixture(
        [
            '',
            'TPL-1',
            'Template English item',
            '模板中文商品',
            'SKU-TPL-1',
            24,
            'pieces',
            12,
            2,
            3.5,
            4.25,
            102,
            0.05,
            0.1,
            1.2,
            2.4,
            $supplier['name'],
            '9503.00',
            'Template row note',
            'No',
        ],
        [
            'customer' => $customer['name'],
            'expected_ready' => '2026-05-01',
            'currency' => 'RMB',
        ],
        $pngPath
    );

    try {
        $out = runHandlerScriptWithUploadedFile(
            $root,
            'backend/api/handlers/draft-orders.php',
            'POST',
            'import',
            null,
            $xlsxPath,
            'procurement_import_template.xlsx'
        );
        $json = json_decode($out, true);
        $item = $json['data']['supplier_sections'][0]['items'][0] ?? null;
        if (!$item) {
            throw new Exception('Expected filled template item, got: ' . substr($out, 0, 300));
        }
        if ((int) ($json['data']['customer']['id'] ?? 0) !== (int) $customer['id']) {
            throw new Exception('Customer metadata was not resolved from the official template layout');
        }
        if ((int) ($json['data']['supplier_sections'][0]['supplier_id'] ?? 0) !== (int) $supplier['id']) {
            throw new Exception('Supplier column was not resolved from the official template layout');
        }
        if (($item['item_no'] ?? '') !== 'TPL-1' || ($item['quantity'] ?? '') !== '24' || ($item['cartons'] ?? '') !== '2') {
            throw new Exception('Official template item values were not imported correctly');
        }
        if (($item['unit_price'] ?? '') !== '3.5' || ($item['sell_price'] ?? '') !== '4.25') {
            throw new Exception('Official template prices were not imported correctly');
        }
        if (count($item['description_entries'] ?? []) < 1) {
            throw new Exception('Official template descriptions were not imported');
        }
        $photoPaths = $item['photo_paths'] ?? [];
        if (count($photoPaths) !== 1 || !is_file($root . '/backend/' . $photoPaths[0])) {
            throw new Exception('Official template embedded photo was not imported into item photo paths');
        }
        @unlink($root . '/backend/' . $photoPaths[0]);
        $ordersAfter = (int) $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
        if ($ordersAfter !== $ordersBefore) {
            throw new Exception('Official template preview created an order unexpectedly');
        }
    } finally {
        @unlink($xlsxPath);
        @unlink($pngPath);
    }
});

test('draft-orders import endpoint keeps missing exported fields empty', function () use ($pdo, $root) {
    $supplierName = (string) $pdo->query("SELECT name FROM suppliers ORDER BY id LIMIT 1")->fetchColumn();
    if ($supplierName === '') {
        throw new Exception('Missing supplier seed data');
    }

    $csvPath = tempnam(sys_get_temp_dir(), 'draft_sparse_import_');
    $fh = fopen($csvPath, 'w');
    fputcsv($fh, ['Supplier:', $supplierName]);
    fputcsv($fh, ['Item No', 'Product / Names', 'Cartons']);
    fputcsv($fh, ['', 'Sparse imported item', '']);
    fclose($fh);

    try {
        $out = runHandlerScriptWithUploadedFile(
            $root,
            'backend/api/handlers/draft-orders.php',
            'POST',
            'import',
            null,
            $csvPath,
            'draft_sparse_import.csv'
        );
        $json = json_decode($out, true);
        $item = $json['data']['supplier_sections'][0]['items'][0] ?? null;
        if (!$item) {
            throw new Exception('Expected imported sparse item, got: ' . substr($out, 0, 300));
        }
        if (($item['cartons'] ?? 'not-empty') !== '' || ($item['cbm'] ?? 'not-empty') !== '' || ($item['weight'] ?? 'not-empty') !== '') {
            throw new Exception('Missing spreadsheet values should remain empty in the preview payload');
        }
    } finally {
        @unlink($csvPath);
    }
});

test('draft-orders import carries express number within supplier sections', function () use ($root) {
    $csvPath = tempnam(sys_get_temp_dir(), 'draft_express_carry_');
    $fh = fopen($csvPath, 'w');
    fputcsv($fh, ['Supplier:', 'Express Carry Supplier A']);
    fputcsv($fh, ['Item No', 'Product / Names', 'Express Number', 'Cartons']);
    fputcsv($fh, ['EC-A1', 'Express carry first item', 'EXP-A-001', '1']);
    fputcsv($fh, ['EC-A2', 'Express carry second item', '', '1']);
    fputcsv($fh, ['Supplier:', 'Express Carry Supplier B']);
    fputcsv($fh, ['EC-B1', 'Express carry reset item', '', '1']);
    fputcsv($fh, ['EC-B2', 'Express carry new item', 'EXP-B-001', '1']);
    fputcsv($fh, ['EC-B3', 'Express carry inherited item', '', '1']);
    fclose($fh);

    try {
        $out = runHandlerScriptWithUploadedFile(
            $root,
            'backend/api/handlers/draft-orders.php',
            'POST',
            'import',
            null,
            $csvPath,
            'draft_express_carry.csv'
        );
        $json = json_decode($out, true);
        $sections = $json['data']['supplier_sections'] ?? [];
        if (count($sections) !== 2) {
            throw new Exception('Expected two supplier sections, got: ' . substr($out, 0, 300));
        }

        $firstItems = $sections[0]['items'] ?? [];
        $secondItems = $sections[1]['items'] ?? [];
        if (($firstItems[0]['express_number'] ?? '') !== 'EXP-A-001' || ($firstItems[1]['express_number'] ?? '') !== 'EXP-A-001') {
            throw new Exception('Express number should carry down inside the first supplier section');
        }
        if (($secondItems[0]['express_number'] ?? '') !== '') {
            throw new Exception('Express number should reset at a new supplier section');
        }
        if (($secondItems[1]['express_number'] ?? '') !== 'EXP-B-001' || ($secondItems[2]['express_number'] ?? '') !== 'EXP-B-001') {
            throw new Exception('Express number should restart and carry down inside the second supplier section');
        }
    } finally {
        @unlink($csvPath);
    }
});

test('receiving import carries express number within supplier sections', function () use ($pdo) {
    $csvPath = tempnam(sys_get_temp_dir(), 'receiving_express_carry_');
    $fh = fopen($csvPath, 'w');
    fputcsv($fh, ['Supplier:', 'Receiving Carry Supplier A']);
    fputcsv($fh, ['Item No', 'Product / Names', 'Express Number', 'Cartons', 'Total CBM', 'Total Weight']);
    fputcsv($fh, ['REC-A1', 'Receiving carry first item', 'RX-A-001', '1', '0.1', '2']);
    fputcsv($fh, ['REC-A2', 'Receiving carry second item', '', '1', '0.2', '3']);
    fputcsv($fh, ['Supplier:', 'Receiving Carry Supplier B']);
    fputcsv($fh, ['REC-B1', 'Receiving carry reset item', '', '1', '0.3', '4']);
    fputcsv($fh, ['REC-B2', 'Receiving carry new item', 'RX-B-001', '1', '0.4', '5']);
    fputcsv($fh, ['REC-B3', 'Receiving carry inherited item', '', '1', '0.5', '6']);
    fclose($fh);

    try {
        $service = new ReceivingExcelImportService();
        $preview = $service->previewFromUploadedFile($pdo, [
            'name' => 'receiving_express_carry.csv',
            'type' => 'text/csv',
            'tmp_name' => $csvPath,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($csvPath),
        ]);
        $rawRows = $preview['raw_rows'] ?? [];
        if (count($rawRows) !== 5) {
            throw new Exception('Expected five receiving raw rows, got ' . count($rawRows));
        }
        if (($rawRows[0]['express_number'] ?? '') !== 'RX-A-001' || ($rawRows[1]['express_number'] ?? '') !== 'RX-A-001') {
            throw new Exception('Receiving express number should carry down inside the first supplier section');
        }
        if (($rawRows[2]['express_number'] ?? '') !== '') {
            throw new Exception('Receiving express number should reset at a new supplier section');
        }
        if (($rawRows[3]['express_number'] ?? '') !== 'RX-B-001' || ($rawRows[4]['express_number'] ?? '') !== 'RX-B-001') {
            throw new Exception('Receiving express number should restart and carry down inside the second supplier section');
        }
    } finally {
        @unlink($csvPath);
    }
});

test('draft and receiving imports skip the Chinese workbook header row', function () use ($pdo, $root) {
    $draftCsvPath = tempnam(sys_get_temp_dir(), 'draft_bilingual_header_');
    $receivingCsvPath = tempnam(sys_get_temp_dir(), 'receiving_bilingual_header_');

    $draftFile = fopen($draftCsvPath, 'w');
    fputcsv($draftFile, ['ITEM NO', 'ENGLISH DESCRIPTION', 'CHINESE DESCRIPTION', 'TOTAL CTNS']);
    fputcsv($draftFile, ['货号', '英文描述', '中文描述', '总箱数']);
    fputcsv($draftFile, ['BI-1', 'Bilingual header item', '双语表头商品', '2']);
    fclose($draftFile);

    $receivingFile = fopen($receivingCsvPath, 'w');
    fputcsv($receivingFile, ['ITEM NO', 'ENGLISH DESCRIPTION', 'CHINESE DESCRIPTION', 'TOTAL CTNS', 'TOTAL CBM', 'TOTAL GW']);
    fputcsv($receivingFile, ['货号', '英文描述', '中文描述', '总箱数', '总 CBM', '总毛重']);
    fputcsv($receivingFile, ['BI-R1', 'Bilingual receiving item', '双语收货商品', '2', '0.4', '8']);
    fclose($receivingFile);

    try {
        $out = runHandlerScriptWithUploadedFile(
            $root,
            'backend/api/handlers/draft-orders.php',
            'POST',
            'import',
            null,
            $draftCsvPath,
            'draft_bilingual_header.csv'
        );
        $json = json_decode($out, true);
        $draftItems = $json['data']['supplier_sections'][0]['items'] ?? [];
        if (count($draftItems) !== 1 || ($draftItems[0]['item_no'] ?? '') !== 'BI-1') {
            throw new Exception('Draft import treated the Chinese header as an item row');
        }

        $preview = (new ReceivingExcelImportService())->previewFromUploadedFile($pdo, [
            'name' => 'receiving_bilingual_header.csv',
            'type' => 'text/csv',
            'tmp_name' => $receivingCsvPath,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($receivingCsvPath),
        ]);
        $rawRows = $preview['raw_rows'] ?? [];
        if (count($rawRows) !== 1 || ($rawRows[0]['item_no'] ?? '') !== 'BI-R1') {
            throw new Exception('Receiving import treated the Chinese header as an item row');
        }
    } finally {
        @unlink($draftCsvPath);
        @unlink($receivingCsvPath);
    }
});

test('draft-orders import endpoint preserves repeated descriptions and zero-carton pieces', function () use ($pdo, $root) {
    $supplierName = (string) $pdo->query("SELECT name FROM suppliers ORDER BY id LIMIT 1")->fetchColumn();
    if ($supplierName === '') {
        throw new Exception('Missing supplier seed data');
    }

    $csvPath = tempnam(sys_get_temp_dir(), 'draft_multi_desc_import_');
    $fh = fopen($csvPath, 'w');
    fputcsv($fh, ['Supplier:', $supplierName]);
    fputcsv($fh, ['Item No', 'Description', 'Description 2', 'Description 3', 'Pieces/Carton', 'Cartons']);
    fputcsv($fh, ['MD-1', 'First description', 'Second description', 'Third description', '24', '0']);
    fclose($fh);

    try {
        $out = runHandlerScriptWithUploadedFile(
            $root,
            'backend/api/handlers/draft-orders.php',
            'POST',
            'import',
            null,
            $csvPath,
            'draft_multi_desc_import.csv'
        );
        $json = json_decode($out, true);
        $item = $json['data']['supplier_sections'][0]['items'][0] ?? null;
        if (!$item) {
            throw new Exception('Expected imported multi-description item, got: ' . substr($out, 0, 300));
        }
        if (count($item['description_entries'] ?? []) !== 3) {
            throw new Exception('Repeated description columns were not preserved as multiple descriptions');
        }
        if (($item['cartons'] ?? '') !== '1' || ($item['pieces_per_carton'] ?? '') !== '24') {
            throw new Exception('Zero cartons with a positive pieces value should keep the pieces by autofilling one carton');
        }
    } finally {
        @unlink($csvPath);
    }
});

test('orders list accepts order_type filter', function () use ($root) {
    $out = runHandlerScript(
        $root,
        'backend/api/handlers/orders.php',
        'GET',
        null,
        null,
        ['order_type' => 'draft_procurement']
    );
    $json = json_decode($out, true);
    if (!is_array($json) || !array_key_exists('data', $json) || !is_array($json['data'])) {
        throw new Exception('Expected {data: []}, got: ' . substr($out, 0, 200));
    }
});

test('orders handler allows create without expected_ready_date', function () use ($pdo, $root) {
    $customerId = (int) $pdo->query("SELECT id FROM customers ORDER BY id LIMIT 1")->fetchColumn();
    $supplierId = (int) $pdo->query("SELECT id FROM suppliers ORDER BY id LIMIT 1")->fetchColumn();
    if ($customerId <= 0 || $supplierId <= 0) {
        throw new Exception('Missing customer or supplier seed data');
    }

    $out = runHandlerScript($root, 'backend/api/handlers/orders.php', 'POST', null, null, [], [
        'customer_id' => $customerId,
        'supplier_id' => $supplierId,
        'expected_ready_date' => null,
        'currency' => 'USD',
        'items' => [[
            'description_cn' => 'Optional expected date order test',
            'description_en' => 'Optional expected date order test',
            'quantity' => 1,
            'unit' => 'pieces',
            'declared_cbm' => 0.1,
            'declared_weight' => 1,
            'unit_price' => 2.5,
            'total_amount' => 2.5,
        ]],
    ]);
    $json = json_decode($out, true);
    if (!is_array($json) || empty($json['data']['id'])) {
        throw new Exception('Order create failed: ' . substr($out, 0, 200));
    }

    $orderId = (int) $json['data']['id'];
    try {
        if (array_key_exists('expected_ready_date', $json['data']) && $json['data']['expected_ready_date'] !== null) {
            throw new Exception('Expected ready date should be null when omitted');
        }
    } finally {
        cleanupCreatedOrder($pdo, $orderId);
    }
});

test('draft-orders handler allows create without expected_ready_date and auto-fills a known single-language description', function () use ($pdo, $root) {
    $customerId = (int) $pdo->query("SELECT id FROM customers ORDER BY id LIMIT 1")->fetchColumn();
    $supplierId = (int) $pdo->query("SELECT id FROM suppliers ORDER BY id LIMIT 1")->fetchColumn();
    if ($customerId <= 0 || $supplierId <= 0) {
        throw new Exception('Missing customer or supplier seed data');
    }

    $label = 'Optional draft expected date test ' . bin2hex(random_bytes(4));
    $translatedLabel = '自动翻译测试 ' . bin2hex(random_bytes(3));
    (new TranslationService($pdo))->saveManualCorrection($label, 'en', 'zh', $translatedLabel, 1);
    $out = runHandlerScript($root, 'backend/api/handlers/draft-orders.php', 'POST', null, null, [], [
        'customer_id' => $customerId,
        'expected_ready_date' => null,
        'currency' => 'USD',
        'supplier_sections' => [[
            'supplier_id' => $supplierId,
            'items' => [[
                'description_entries' => [[
                    'description_text' => $label,
                ]],
                'pieces_per_carton' => 1,
                'cartons' => 1,
                'unit_price' => 3.25,
                'cbm_mode' => 'direct',
                'cbm' => 0.12,
                'weight' => 1.4,
                'photo_paths' => [],
                'custom_design_required' => 0,
                'custom_design_paths' => [],
                'dimensions_scope' => 'carton',
            ]],
        ]],
    ]);
    $json = json_decode($out, true);
    if (!is_array($json) || empty($json['data']['id'])) {
        throw new Exception('Draft-order create failed: ' . substr($out, 0, 200));
    }

    $orderId = (int) $json['data']['id'];
    try {
        if (array_key_exists('expected_ready_date', $json['data']) && $json['data']['expected_ready_date'] !== null) {
            throw new Exception('Draft-order expected ready date should be null when omitted');
        }
        $rowStmt = $pdo->prepare(
            "SELECT oi.description_cn, oi.description_en, p.required_design
             FROM order_items oi
             LEFT JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id = ?
             LIMIT 1"
        );
        $rowStmt->execute([$orderId]);
        $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new Exception('Draft-order item row not created');
        }
        if (trim((string) ($row['description_en'] ?? '')) !== $label) {
            throw new Exception('Expected English-side description to keep the source text');
        }
        if (trim((string) ($row['description_cn'] ?? '')) !== $translatedLabel) {
            throw new Exception('Expected the server to populate the known Chinese translation');
        }
        if (!empty($row['required_design'])) {
            throw new Exception('Auto-created product should not default required_design to on');
        }
    } finally {
        cleanupCreatedOrder($pdo, $orderId, $label);
    }
});

test('draft-orders handler auto-fills English from a known Chinese-only description', function () use ($pdo, $root) {
    $customerId = (int) $pdo->query("SELECT id FROM customers ORDER BY id LIMIT 1")->fetchColumn();
    $supplierId = (int) $pdo->query("SELECT id FROM suppliers ORDER BY id LIMIT 1")->fetchColumn();
    if ($customerId <= 0 || $supplierId <= 0) throw new Exception('Missing customer or supplier seed data');

    $chinese = '中文单向翻译 ' . bin2hex(random_bytes(3));
    $english = 'Chinese source translation ' . bin2hex(random_bytes(4));
    (new TranslationService($pdo))->saveManualCorrection($chinese, 'zh', 'en', $english, 1);
    $orderId = 0;
    try {
        $out = runHandlerScript($root, 'backend/api/handlers/draft-orders.php', 'POST', null, null, [], [
            'customer_id' => $customerId,
            'currency' => 'USD',
            'supplier_sections' => [[
                'supplier_id' => $supplierId,
                'items' => [[
                    'description_entries' => [['description_text' => $chinese]],
                    'pieces_per_carton' => 1,
                    'cartons' => 1,
                    'unit_price' => 1,
                    'cbm_mode' => 'direct',
                    'cbm' => 0.01,
                    'weight' => 0.1,
                    'photo_paths' => [],
                    'custom_design_required' => 0,
                    'custom_design_paths' => [],
                    'dimensions_scope' => 'carton',
                ]],
            ]],
        ]);
        $json = json_decode($out, true);
        $orderId = (int) ($json['data']['id'] ?? 0);
        if ($orderId <= 0) throw new Exception('Chinese-only draft save failed: ' . substr($out, 0, 200));
        $stmt = $pdo->prepare('SELECT description_en, description_cn FROM order_items WHERE order_id=? LIMIT 1');
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (trim((string) ($row['description_cn'] ?? '')) !== $chinese
            || trim((string) ($row['description_en'] ?? '')) !== $english) {
            throw new Exception('Chinese-to-English server completion did not persist both languages');
        }
    } finally {
        if ($orderId > 0) cleanupCreatedOrder($pdo, $orderId, $chinese);
    }
});

test('standard order creation key replays the first committed result', function () use ($pdo, $root) {
    $customerId = (int) $pdo->query("SELECT id FROM customers ORDER BY id LIMIT 1")->fetchColumn();
    $supplierId = (int) $pdo->query("SELECT id FROM suppliers ORDER BY id LIMIT 1")->fetchColumn();
    if ($customerId <= 0 || $supplierId <= 0) throw new Exception('Missing customer or supplier seed data');
    $key = 'standard-order-test:' . bin2hex(random_bytes(8));
    $label = 'Standard idempotency ' . bin2hex(random_bytes(4));
    $payload = [
        'idempotency_key' => $key,
        'customer_id' => $customerId,
        'supplier_id' => $supplierId,
        'currency' => 'USD',
        'items' => [[
            'description_cn' => $label,
            'description_en' => $label,
            'quantity' => 1,
            'unit' => 'pieces',
            'declared_cbm' => 0.01,
            'declared_weight' => 0.1,
            'unit_price' => 1,
            'total_amount' => 1,
        ]],
    ];
    $first = json_decode(runHandlerScript($root, 'backend/api/handlers/orders.php', 'POST', null, null, [], $payload), true);
    $orderId = (int) ($first['data']['id'] ?? 0);
    if ($orderId <= 0) throw new Exception('First standard create failed');
    try {
        $second = json_decode(runHandlerScript($root, 'backend/api/handlers/orders.php', 'POST', null, null, [], $payload), true);
        if ((int) ($second['data']['id'] ?? 0) !== $orderId || empty($second['idempotent_replay'])) {
            throw new Exception('Retry did not return the first committed order');
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE creation_idempotency_key=?');
        $stmt->execute([$key]);
        if ((int) $stmt->fetchColumn() !== 1) throw new Exception('Creation key produced duplicate orders');
    } finally {
        cleanupCreatedOrder($pdo, $orderId, $label);
        $pdo->prepare("DELETE FROM translation_manual_corrections WHERE source_hash=? AND source_lang='en' AND target_lang='zh'")
            ->execute([hash('sha256', $label)]);
    }
});

test('draft-order create continues item numbers from previous saved order', function () use ($pdo, $root) {
    $customer = $pdo->query(
        "SELECT c.id, ccs.country_id
         FROM customers c
         LEFT JOIN customer_country_shipping ccs
           ON ccs.customer_id = c.id
          AND TRIM(COALESCE(ccs.shipping_code, '')) <> ''
         WHERE TRIM(COALESCE(c.default_shipping_code, '')) <> ''
            OR ccs.country_id IS NOT NULL
         ORDER BY c.id
         LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    $supplierId = (int) $pdo->query("SELECT id FROM suppliers ORDER BY id LIMIT 1")->fetchColumn();
    if (!$customer || $supplierId <= 0) {
        return;
    }

    $created = [];
    $makePayload = static function (string $label) use ($customer, $supplierId): array {
        return [
            'customer_id' => (int) $customer['id'],
            'destination_country_id' => !empty($customer['country_id']) ? (int) $customer['country_id'] : null,
            'expected_ready_date' => null,
            'currency' => 'USD',
            'supplier_sections' => [[
                'supplier_id' => $supplierId,
                'items' => [[
                    'description_entries' => [[
                        'description_text' => '测试 ' . $label,
                        'description_translated' => $label,
                    ]],
                    'pieces_per_carton' => 1,
                    'cartons' => 1,
                    'unit_price' => 1.5,
                    'cbm_mode' => 'direct',
                    'cbm' => 0.05,
                    'weight' => 0.5,
                    'photo_paths' => [],
                    'custom_design_required' => 0,
                    'custom_design_paths' => [],
                    'dimensions_scope' => 'carton',
                ]],
            ]],
        ];
    };

    try {
        foreach ([1, 2] as $run) {
            $label = 'Numbering continuation test ' . $run . ' ' . bin2hex(random_bytes(4));
            $out = runHandlerScript($root, 'backend/api/handlers/draft-orders.php', 'POST', null, null, [], $makePayload($label));
            $json = json_decode($out, true);
            if (!is_array($json) || empty($json['data']['id'])) {
                throw new Exception('Draft-order create failed: ' . substr($out, 0, 200));
            }
            $created[] = ['id' => (int) $json['data']['id'], 'label' => $label];
        }

        $itemNos = [];
        foreach ($created as $row) {
            $stmt = $pdo->prepare("SELECT item_no FROM order_items WHERE order_id = ? ORDER BY id LIMIT 1");
            $stmt->execute([$row['id']]);
            $itemNos[] = trim((string) $stmt->fetchColumn());
        }

        if (!preg_match('/^(.+)-(\d+)-(\d+)$/', $itemNos[0], $first)
            || !preg_match('/^(.+)-(\d+)-(\d+)$/', $itemNos[1], $second)
        ) {
            throw new Exception('Expected structured item numbers, got: ' . implode(', ', $itemNos));
        }
        if ($second[1] !== $first[1] || (int) $second[2] !== (int) $first[2] || (int) $second[3] !== (int) $first[3] + 1) {
            throw new Exception('Expected second draft item number to continue from first, got: ' . implode(', ', $itemNos));
        }
    } finally {
        foreach (array_reverse($created) as $row) {
            cleanupCreatedOrder($pdo, (int) $row['id'], $row['label']);
        }
    }
});

test('two concurrent draft creates serialize item-number allocation', function () use ($pdo,$root) {
    $customer=$pdo->query("SELECT c.id,ccs.country_id FROM customers c LEFT JOIN customer_country_shipping ccs ON ccs.customer_id=c.id AND TRIM(COALESCE(ccs.shipping_code,''))<>'' WHERE TRIM(COALESCE(c.default_shipping_code,''))<>'' OR ccs.country_id IS NOT NULL ORDER BY c.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $supplierId=(int)$pdo->query('SELECT id FROM suppliers ORDER BY id LIMIT 1')->fetchColumn();
    if(!$customer||$supplierId<=0)return;
    $labels=['Concurrent numbering A '.bin2hex(random_bytes(4)),'Concurrent numbering B '.bin2hex(random_bytes(4))];
    $payload=function(string $label)use($customer,$supplierId){return ['customer_id'=>(int)$customer['id'],'destination_country_id'=>!empty($customer['country_id'])?(int)$customer['country_id']:null,'currency'=>'USD','supplier_sections'=>[['supplier_id'=>$supplierId,'items'=>[['description_entries'=>[bilingualDescriptionEntry($label)],'pieces_per_carton'=>1,'cartons'=>1,'unit_price'=>1,'cbm_mode'=>'direct','cbm'=>0.01,'weight'=>0.1,'photo_paths'=>[],'custom_design_required'=>0,'custom_design_paths'=>[],'dimensions_scope'=>'carton']]]]];};
    $created=[];
    try {
        $workers=[startDraftCreateProcess($root,$payload($labels[0])),startDraftCreateProcess($root,$payload($labels[1]))];
        foreach($workers as $worker){$json=json_decode(finishDraftCreateProcess($worker),true);if(empty($json['data']['id']))throw new Exception('Concurrent create returned invalid payload');$created[]=(int)$json['data']['id'];}
        $ph=implode(',',array_fill(0,count($created),'?'));$stmt=$pdo->prepare("SELECT order_id,item_no FROM order_items WHERE order_id IN ($ph) ORDER BY order_id");$stmt->execute($created);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)!==2)throw new Exception('Concurrent drafts did not each create exactly one item');
        $numbers=array_column($rows,'item_no');
        if(count(array_unique($numbers))!==2)throw new Exception('Concurrent drafts generated a duplicate item number: '.implode(',',$numbers));
        preg_match('/^(.*)-(\d+)-(\d+)$/',$numbers[0],$a);preg_match('/^(.*)-(\d+)-(\d+)$/',$numbers[1],$b);
        if(!$a||!$b||$a[1]!==$b[1]||$a[2]!==$b[2]||abs((int)$a[3]-(int)$b[3])!==1)throw new Exception('Concurrent item numbers were not consecutive: '.implode(',',$numbers));
    } finally {
        foreach($created as $index=>$orderId)cleanupCreatedOrder($pdo,$orderId,$labels[$index]??null);
    }
});

test('translations endpoint translates or safely queues unavailable provider work', function () use ($root) {
    $out = runHandlerScript($root, 'backend/api/handlers/translations.php', 'POST', null, null, [], [
        'text' => 'draft builder translation test',
        'source_lang' => 'en',
        'target_lang' => 'zh',
    ]);
    $json = json_decode($out, true);
    $status = (string) ($json['data']['status'] ?? '');
    if (!is_array($json) || !in_array($status, ['translated', 'manual', 'pending', 'failed'], true)) {
        throw new Exception('Expected translated or queued status payload, got: ' . substr($out, 0, 200));
    }
    if (in_array($status, ['pending', 'failed'], true) && trim((string) ($json['data']['translated'] ?? '')) !== '') {
        throw new Exception('Unavailable provider must not fabricate a translation');
    }
});

test('translate endpoint reports target language and never returns placeholder text', function () use ($root) {
    $out = runHandlerScript($root, 'backend/api/handlers/translate.php', 'POST', null, null, [], [
        'text' => 'draft builder translate endpoint test',
        'source_lang' => 'en',
        'target_lang' => 'zh',
    ]);
    $json = json_decode($out, true);
    $translated = trim((string) ($json['data']['translated'] ?? ''));
    if (!is_array($json) || ($json['data']['target_lang'] ?? '') !== 'zh') {
        throw new Exception('Expected target-language status payload, got: ' . substr($out, 0, 200));
    }
    if (preg_match('/^\[(?:EN|ZH)\]\s/', $translated)) {
        throw new Exception('Placeholder translation leaked from the service: ' . $translated);
    }
});

test('draft and confirmed-order exports preserve shipment charges', function () use ($pdo, $root) {
    $orderId = (int) $pdo->query("SELECT id FROM orders WHERE order_type = 'draft_procurement' AND status='Draft' ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($orderId <= 0) {
        return;
    }

    $userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
    $originalStatus = (string) $pdo->query("SELECT status FROM orders WHERE id = $orderId")->fetchColumn();
    $costId = 0;
    try {
        $cost = (new DraftOrderCostService($pdo))->create($orderId, [
            'cost_type_code' => 'handling',
            'description_en' => 'Export preservation check',
            'amount' => '12.3400',
            'currency' => 'USD',
            'exchange_rate' => '1.00000000',
            'base_currency' => 'USD',
            'responsible_payer' => 'company',
            'allocation_method' => 'none',
        ], $userId);
        $costId = (int) $cost['id'];

        $draftOut = runHandlerScript($root, 'backend/api/handlers/draft-orders.php', 'GET', (string) $orderId, 'export', ['format' => 'csv']);
        if (strpos($draftOut, 'Draft Order') === false || strpos($draftOut, 'Export preservation check') === false || strpos($draftOut, '12.3400') === false) {
            throw new Exception('Draft CSV did not contain its shipment charge');
        }

        $pdo->prepare("UPDATE orders SET status='Approved' WHERE id=?")->execute([$orderId]);
        $orderOut = runHandlerScript($root, 'backend/api/handlers/orders.php', 'GET', (string) $orderId, 'export', ['format' => 'csv']);
        if (strpos($orderOut, 'Shipment Charges') === false || strpos($orderOut, 'Export preservation check') === false || strpos($orderOut, '12.3400') === false) {
            throw new Exception('Confirmed-order CSV did not preserve its shipment charge');
        }
    } finally {
        $pdo->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$originalStatus, $orderId]);
        if ($costId > 0) {
            $pdo->prepare("DELETE FROM shipment_financial_entries WHERE source_type='draft_order_cost' AND source_id=?")->execute([$costId]);
            $pdo->prepare('DELETE FROM draft_order_cost_history WHERE cost_id=?')->execute([$costId]);
            $pdo->prepare('DELETE FROM draft_order_costs WHERE id=?')->execute([$costId]);
        }
    }
});

test('manual draft item numbers persist, drive the next value, and create audit history', function () use ($pdo,$root) {
    $customerId=(int)$pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn();
    $supplierId=(int)$pdo->query('SELECT id FROM suppliers ORDER BY id LIMIT 1')->fetchColumn();
    if($customerId<=0||$supplierId<=0)throw new Exception('Missing fixtures');
    $label='Manual item number '.bin2hex(random_bytes(4));$orderId=0;
    $itemPayload=static fn(string $description,$number,string $source,?int $existingId=null)=>[
        'existing_item_id'=>$existingId,'item_no'=>$number,'item_no_source'=>$source,'item_no_manual'=>$source!=='generated'?1:0,
        'description_entries'=>[bilingualDescriptionEntry($description)],'pieces_per_carton'=>1,'cartons'=>1,'unit_price'=>1,
        'cbm_mode'=>'direct','cbm'=>0.01,'weight'=>0.1,'photo_paths'=>[],'custom_design_required'=>0,'custom_design_paths'=>[],'dimensions_scope'=>'carton'
    ];
    try{
        $create=['customer_id'=>$customerId,'currency'=>'USD','supplier_sections'=>[['supplier_id'=>$supplierId,'items'=>[$itemPayload($label,'ITEM-008','manual')]]]];
        $json=json_decode(runHandlerScript($root,'backend/api/handlers/draft-orders.php','POST',null,null,[],$create),true);$orderId=(int)($json['data']['id']??0);
        if($orderId<=0)throw new Exception('Manual create failed');
        $first=$pdo->query("SELECT id,item_no,item_no_source FROM order_items WHERE order_id=$orderId ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if(($first['item_no']??'')!=='ITEM-008'||($first['item_no_source']??'')!=='manual')throw new Exception('Manual value/provenance did not persist');
        $update=['customer_id'=>$customerId,'currency'=>'USD','lock_version'=>(int)($json['data']['lock_version']??0),'supplier_sections'=>[['supplier_id'=>$supplierId,'items'=>[
            $itemPayload($label,'ITEM-015','manual',(int)$first['id']),$itemPayload($label.' next','', 'generated')
        ]]]];
        $updated=json_decode(runHandlerScript($root,'backend/api/handlers/draft-orders.php','PUT',(string)$orderId,null,[],$update),true);
        if(empty($updated['data']['id']))throw new Exception('Manual update failed: '.json_encode($updated));
        $numbers=$pdo->query("SELECT item_no,item_no_source FROM order_items WHERE order_id=$orderId ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        if(array_column($numbers,'item_no')!==['ITEM-015','ITEM-016'])throw new Exception('Manual 15 did not drive next 16: '.json_encode($numbers));
        $audit=$pdo->query("SELECT old_value,new_value,user_id,created_at FROM audit_log WHERE action='item_number_changed' AND JSON_EXTRACT(new_value,'$.draft_id')=$orderId ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if(!$audit||strpos((string)$audit['old_value'],'ITEM-008')===false||strpos((string)$audit['new_value'],'ITEM-015')===false||empty($audit['user_id'])||empty($audit['created_at']))throw new Exception('Manual change audit is incomplete');
        $submit=json_decode(runHandlerScript($root,'backend/api/handlers/orders.php','POST',(string)$orderId,'submit',[],[]),true);
        if(($submit['data']['status']??'')!=='Submitted')throw new Exception('Draft submit failed: '.json_encode($submit));
        $approve=json_decode(runHandlerScript($root,'backend/api/handlers/orders.php','POST',(string)$orderId,'approve',[],[]),true);
        if(($approve['data']['status']??'')!=='Approved')throw new Exception('Draft approval failed: '.json_encode($approve));
        $afterApproval=$pdo->query("SELECT GROUP_CONCAT(item_no ORDER BY id SEPARATOR ',') FROM order_items WHERE order_id=$orderId")->fetchColumn();
        if($afterApproval!=='ITEM-015,ITEM-016')throw new Exception('Approval changed item numbers: '.$afterApproval);
    }finally{
        if($orderId>0){$pdo->prepare("DELETE FROM audit_log WHERE action='item_number_changed' AND JSON_EXTRACT(new_value,'$.draft_id')=?")->execute([$orderId]);$pdo->prepare('DELETE FROM notifications WHERE target_type=\'order\' AND target_id=?')->execute([$orderId]);cleanupCreatedOrder($pdo,$orderId);}
        $pdo->prepare('DELETE FROM product_description_entries WHERE product_id IN (SELECT id FROM products WHERE description_en IN (?,?))')->execute([$label,$label.' next']);
        $pdo->prepare('DELETE FROM products WHERE description_en IN (?,?)')->execute([$label,$label.' next']);
    }
});

test('duplicate imported item numbers retain exact display values and provenance with a warning', function () use ($pdo,$root) {
    $customerId=(int)$pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn();$supplierId=(int)$pdo->query('SELECT id FROM suppliers ORDER BY id LIMIT 1')->fetchColumn();
    $label='Imported duplicate '.bin2hex(random_bytes(4));$orderId=0;$value=" MiXeD-ITEM-007\xC2\xA0";
    $item=static fn(string $description)=>['item_no'=>$value,'item_no_source'=>'imported','item_no_manual'=>1,'description_entries'=>[bilingualDescriptionEntry($description)],'pieces_per_carton'=>1,'cartons'=>1,'unit_price'=>1,'cbm_mode'=>'direct','cbm'=>0.01,'weight'=>0.1,'photo_paths'=>[],'custom_design_required'=>0,'custom_design_paths'=>[],'dimensions_scope'=>'carton'];
    try{
        $json=json_decode(runHandlerScript($root,'backend/api/handlers/draft-orders.php','POST',null,null,[],['customer_id'=>$customerId,'currency'=>'USD','supplier_sections'=>[['supplier_id'=>$supplierId,'items'=>[$item($label.' A'),$item($label.' B')]]]]),true);$orderId=(int)($json['data']['id']??0);
        if($orderId<=0)throw new Exception('Imported duplicate save failed: '.json_encode($json));
        $rows=$pdo->query("SELECT item_no,item_no_source FROM order_items WHERE order_id=$orderId ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        if(array_column($rows,'item_no')!==[$value,$value]||array_unique(array_column($rows,'item_no_source'))!==['imported'])throw new Exception('Imported display/provenance changed');
        if(stripos((string)($json['warning']??''),'preserved')===false)throw new Exception('Duplicate import warning missing');
    }finally{if($orderId>0)cleanupCreatedOrder($pdo,$orderId);$pdo->prepare('DELETE FROM product_description_entries WHERE product_id IN (SELECT id FROM products WHERE description_en IN (?,?))')->execute([$label.' A',$label.' B']);$pdo->prepare('DELETE FROM products WHERE description_en IN (?,?)')->execute([$label.' A',$label.' B']);}
});

test('draft search and advanced filters share exact bilingual, status, party, item and date predicates', function () use ($pdo, $root) {
    $customerId = (int) $pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn();
    $supplierId = (int) $pdo->query('SELECT id FROM suppliers ORDER BY id LIMIT 1')->fetchColumn();
    if ($customerId <= 0 || $supplierId <= 0) throw new Exception('Missing search filter fixtures');
    $token = bin2hex(random_bytes(5));
    $english = 'Bilingual filter ' . $token;
    $chinese = '双语筛选' . $token;
    $brand = 'FilterBrand-' . $token;
    $code = 'FILTER-SKU-' . $token;
    $orderId = 0;
    try {
        $payload = [
            'customer_id' => $customerId,
            'expected_ready_date' => date('Y-m-d'),
            'currency' => 'USD',
            'supplier_sections' => [[
                'supplier_id' => $supplierId,
                'items' => [[
                    'description_entries' => [['description_text' => $chinese, 'description_translated' => $english]],
                    'brand' => $brand,
                    'materials' => 'Filter material ' . $token,
                    'copy_normal_goods' => 'dangerous',
                    'code' => $code,
                    'pieces_per_carton' => 1,
                    'cartons' => 1,
                    'unit_price' => 1,
                    'cbm_mode' => 'direct',
                    'cbm' => 0.01,
                    'weight' => 0.1,
                    'photo_paths' => [],
                    'custom_design_required' => 0,
                    'custom_design_paths' => [],
                    'dimensions_scope' => 'carton',
                ]],
            ]],
        ];
        $created = json_decode(runHandlerScript($root, 'backend/api/handlers/draft-orders.php', 'POST', null, null, [], $payload), true);
        $orderId = (int) ($created['data']['id'] ?? 0);
        if ($orderId <= 0) throw new Exception('Could not create bilingual filter fixture: ' . json_encode($created));

        foreach ([$english, $chinese, $code] as $query) {
            $result = json_decode(runHandlerScript($root, 'backend/api/handlers/draft-orders.php', 'GET', null, null, ['q' => $query, 'limit' => 10]), true);
            $ids = array_map('intval', array_column($result['data'] ?? [], 'id'));
            if (!in_array($orderId, $ids, true)) throw new Exception('Search did not find the draft for query: ' . $query);
        }

        $goodsTypeResult = json_decode(runHandlerScript($root, 'backend/api/handlers/draft-orders.php', 'GET', null, null, [
            'goods_type' => 'dangerous',
            'q' => 'dangerous',
            'limit' => 10,
        ]), true);
        if (!in_array($orderId, array_map('intval', array_column($goodsTypeResult['data'] ?? [], 'id')), true)) {
            throw new Exception('Canonical goods-type search/filter did not find the draft');
        }

        $combined = json_decode(runHandlerScript($root, 'backend/api/handlers/draft-orders.php', 'GET', null, null, [
            'status' => ['Draft'],
            'customer_id' => $customerId,
            'supplier_id' => $supplierId,
            'goods_type' => 'dangerous',
            'brand' => $brand,
            'created_from' => date('Y-m-d'),
            'created_to' => date('Y-m-d'),
            'expected_from' => date('Y-m-d'),
            'expected_to' => date('Y-m-d'),
            'page' => 1,
            'limit' => 10,
        ]), true);
        $rows = $combined['data'] ?? [];
        if (count($rows) !== 1 || (int) ($rows[0]['id'] ?? 0) !== $orderId) {
            throw new Exception('Combined filters returned duplicate or incorrect drafts: ' . json_encode(array_column($rows, 'id')));
        }
        if ((int) ($combined['meta']['total'] ?? 0) !== 1 || (int) ($combined['meta']['page'] ?? 0) !== 1) {
            throw new Exception('Filtered count or pagination metadata does not match the result');
        }
        if ((int) ($combined['filter_options']['selected']['customer_id']['id'] ?? 0) !== $customerId
            || (int) ($combined['filter_options']['selected']['supplier_id']['id'] ?? 0) !== $supplierId) {
            throw new Exception('Selected customer/supplier filter labels were not retained');
        }
    } finally {
        if ($orderId > 0) cleanupCreatedOrder($pdo, $orderId, $english);
    }
});

echo "\nTotal: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
