<?php

/**
 * Draft order builder regression tests
 * Run: php tests/draft_order_builder_test.php
 */

$root = dirname(__DIR__);
require_once $root . '/backend/config/database.php';
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

test('draft-orders RBAC is buyer-only', function () {
    $rbac = require dirname(__DIR__) . '/backend/config/rbac.php';
    $expected = ['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin'];
    if (($rbac['draft-orders'] ?? null) !== $expected) {
        throw new Exception('Unexpected draft-orders RBAC mapping');
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
        if (($item['item_no'] ?? null) !== '' || (int) ($item['item_no_manual'] ?? 1) !== 0) {
            throw new Exception('Imported item numbers should be left for the draft-order numbering logic');
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
        if (($item['item_no'] ?? null) !== '' || (int) ($item['item_no_manual'] ?? 1) !== 0) {
            throw new Exception('XLSX item numbers should be left for the draft-order numbering logic');
        }
        if (($item['cartons'] ?? '') !== '1' || ($item['pieces_per_carton'] ?? '') !== '6') {
            throw new Exception('XLSX packaging fields were not imported');
        }
    } finally {
        @unlink($xlsxPath);
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

test('draft-orders handler allows create without expected_ready_date and auto-fills single description', function () use ($pdo, $root) {
    $customerId = (int) $pdo->query("SELECT id FROM customers ORDER BY id LIMIT 1")->fetchColumn();
    $supplierId = (int) $pdo->query("SELECT id FROM suppliers ORDER BY id LIMIT 1")->fetchColumn();
    if ($customerId <= 0 || $supplierId <= 0) {
        throw new Exception('Missing customer or supplier seed data');
    }

    $label = 'Optional draft expected date test ' . bin2hex(random_bytes(4));
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
        if (trim((string) ($row['description_cn'] ?? '')) === '') {
            throw new Exception('Expected Chinese-side description to be auto-filled');
        }
        if (!empty($row['required_design'])) {
            throw new Exception('Auto-created product should not default required_design to on');
        }
    } finally {
        cleanupCreatedOrder($pdo, $orderId, $label);
    }
});

test('translations endpoint returns translated text for zh and en targets', function () use ($root) {
    $out = runHandlerScript($root, 'backend/api/handlers/translations.php', 'POST', null, null, [], [
        'text' => 'draft builder translation test',
        'source_lang' => 'en',
        'target_lang' => 'zh',
    ]);
    $json = json_decode($out, true);
    if (!is_array($json) || trim((string) ($json['data']['translated'] ?? '')) === '') {
        throw new Exception('Expected translated text payload, got: ' . substr($out, 0, 200));
    }
});

test('translate endpoint uses TranslationService target language handling', function () use ($root) {
    $out = runHandlerScript($root, 'backend/api/handlers/translate.php', 'POST', null, null, [], [
        'text' => 'draft builder translate endpoint test',
        'source_lang' => 'en',
        'target_lang' => 'zh',
    ]);
    $json = json_decode($out, true);
    $translated = trim((string) ($json['data']['translated'] ?? ''));
    if (!is_array($json) || $translated === '') {
        throw new Exception('Expected translated text payload, got: ' . substr($out, 0, 200));
    }
    if (strpos($translated, '[ZH]') !== 0) {
        throw new Exception('Expected target-language-aware translation tag, got: ' . $translated);
    }
});

test('draft-orders export endpoint responds for an existing draft order', function () use ($pdo, $root) {
    $orderId = (int) $pdo->query("SELECT id FROM orders WHERE order_type = 'draft_procurement' ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($orderId <= 0) {
        return;
    }

    $out = runHandlerScript($root, 'backend/api/handlers/draft-orders.php', 'GET', (string) $orderId, 'export');
    if (strpos($out, 'Draft Order') === false || strpos($out, 'Supplier') === false) {
        throw new Exception('Expected grouped draft order CSV output');
    }
});

echo "\nTotal: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
