<?php

require_once dirname(__DIR__) . '/backend/config/database.php';
require_once dirname(__DIR__) . '/backend/api/handlers/orders.php';
require_once dirname(__DIR__) . '/backend/api/handlers/draft-orders.php';

function imageDataAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function imageDataMediaCount(string $workbook): int
{
    $zip = new ZipArchive();
    imageDataAssert($zip->open($workbook) === true, "Could not open generated workbook {$workbook}.");
    $count = 0;
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = str_replace('\\', '/', (string) $zip->getNameIndex($index));
        if (str_starts_with($name, 'xl/media/')) $count++;
    }
    $zip->close();
    return $count;
}

$pdo = getDb();
$tempDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'clms_excel_image_data_' . bin2hex(random_bytes(4));
if (!mkdir($tempDir, 0770, true) && !is_dir($tempDir)) {
    throw new RuntimeException('Could not create Excel image integration directory.');
}

$tested = [];
$verificationSource = null;
try {
    $orderId = (int) ($pdo->query(
        "SELECT DISTINCT o.id
         FROM orders o
         JOIN order_items oi ON oi.order_id=o.id
         LEFT JOIN products p ON p.id=oi.product_id
         WHERE (oi.image_paths IS NOT NULL AND oi.image_paths NOT IN ('', '[]'))
            OR (p.image_paths IS NOT NULL AND p.image_paths NOT IN ('', '[]'))
         ORDER BY o.id DESC LIMIT 1"
    )->fetchColumn() ?: 0);
    if ($orderId > 0) {
        $entry = orderBuildExcelEntry($pdo, $orderId);
        imageDataAssert(is_array($entry), "Order {$orderId} could not be prepared for Excel.");
        $path = $tempDir . DIRECTORY_SEPARATOR . "order_{$orderId}.xlsx";
        (new OrderExcelService($pdo))->saveOrderXlsx($entry['order'], $entry['items'], $path);
        imageDataAssert(imageDataMediaCount($path) > 0, "Order {$orderId} workbook contains no embedded image media.");
        $verificationSource = $path;

        $omittedPayloadItems = $entry['items'];
        foreach ($omittedPayloadItems as &$omittedPayloadItem) {
            $omittedPayloadItem['image_paths'] = [];
        }
        unset($omittedPayloadItem);
        $fallbackPath = $tempDir . DIRECTORY_SEPARATOR . "order_{$orderId}_canonical_fallback.xlsx";
        (new OrderExcelService($pdo))->saveOrderXlsx($entry['order'], $omittedPayloadItems, $fallbackPath);
        imageDataAssert(
            imageDataMediaCount($fallbackPath) > 0,
            "Order {$orderId} workbook did not recover canonical images when its payload omitted image paths."
        );
        $diagnosticItem = null;
        foreach ($entry['items'] as $candidateItem) {
            if (clmsNormalizeImagePathList($candidateItem['image_paths'] ?? [])) {
                $diagnosticItem = $candidateItem;
                break;
            }
        }
        imageDataAssert(is_array($diagnosticItem), "Order {$orderId} has no diagnostic image item.");
        $diagnostic = (new OrderExcelService($pdo))->diagnoseImageCandidates([], [
            'order_id' => $orderId,
            'order_item_id' => (int) ($diagnosticItem['id'] ?? 0),
            'product_id' => (int) ($diagnosticItem['product_id'] ?? 0),
        ]);
        imageDataAssert(
            ($diagnostic['status'] ?? '') === 'ready'
                && (int) ($diagnostic['canonical_candidate_count'] ?? 0) > 0
                && (int) ($diagnostic['embeddable_candidate_count'] ?? 0) > 0,
            "Order {$orderId} canonical image diagnostic did not report an embeddable fallback."
        );
        if (!function_exists('mime_content_type')) {
            imageDataAssert(
                array_sum($diagnostic['runtime_fallback_counts'] ?? []) > 0,
                "Order {$orderId} did not report the Fileinfo-independent memory drawing fallback."
            );
        }
        $tested[] = "order #{$orderId}";
    }

    $draftIdStmt = $pdo->query(
        "SELECT DISTINCT o.id
         FROM orders o
         JOIN order_items oi ON oi.order_id=o.id
         LEFT JOIN products p ON p.id=oi.product_id
         WHERE o.order_type='draft_procurement'
           AND ((oi.image_paths IS NOT NULL AND oi.image_paths NOT IN ('', '[]'))
             OR (p.image_paths IS NOT NULL AND p.image_paths NOT IN ('', '[]')))
         ORDER BY o.id DESC LIMIT 1"
    );
    $draftId = (int) ($draftIdStmt->fetchColumn() ?: 0);
    if ($draftId > 0) {
        $entry = draftOrderBuildExcelEntry($pdo, $draftId);
        $path = $tempDir . DIRECTORY_SEPARATOR . "draft_{$draftId}.xlsx";
        (new OrderExcelService($pdo))->saveOrderXlsx($entry['order'], $entry['items'], $path);
        imageDataAssert(imageDataMediaCount($path) > 0, "Draft order {$draftId} workbook contains no embedded image media.");
        $tested[] = "draft order #{$draftId}";
    }

    $receiptOrderId = (int) ($pdo->query(
        "SELECT wr.order_id
         FROM warehouse_receipts wr
         LEFT JOIN warehouse_receipt_photos wrp ON wrp.receipt_id=wr.id
         LEFT JOIN warehouse_receipt_items wri ON wri.receipt_id=wr.id
         LEFT JOIN warehouse_receipt_item_photos wrip ON wrip.receipt_item_id=wri.id
         WHERE wrp.id IS NOT NULL OR wrip.id IS NOT NULL
         ORDER BY wr.id DESC LIMIT 1"
    )->fetchColumn() ?: 0);
    if ($receiptOrderId > 0) {
        $entry = orderBuildExcelEntry($pdo, $receiptOrderId);
        imageDataAssert(is_array($entry), "Received order {$receiptOrderId} could not be prepared for Excel.");
        $path = $tempDir . DIRECTORY_SEPARATOR . "receiving_{$receiptOrderId}.xlsx";
        (new OrderExcelService($pdo))->saveOrderXlsx($entry['order'], $entry['items'], $path);
        imageDataAssert(imageDataMediaCount($path) > 0, "Receiving workbook for order {$receiptOrderId} contains no embedded receipt image media.");
        $tested[] = "receiving order #{$receiptOrderId}";
    }

    imageDataAssert($tested !== [], 'No image-bearing local records were available for Excel integration testing.');
    if (getenv('CLMS_KEEP_WORKBOOK') === '1' && is_string($verificationSource) && is_file($verificationSource)) {
        $verificationDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'output' . DIRECTORY_SEPARATOR . 'verification';
        if (!is_dir($verificationDir) && !mkdir($verificationDir, 0770, true) && !is_dir($verificationDir)) {
            throw new RuntimeException('Could not create the workbook verification directory.');
        }
        $verificationPath = $verificationDir . DIRECTORY_SEPARATOR . 'excel_image_fileinfo_fallback_verification.xlsx';
        imageDataAssert(copy($verificationSource, $verificationPath), 'Could not preserve the Fileinfo fallback workbook.');
        echo 'ARTIFACT: ' . $verificationPath . PHP_EOL;
    }
    echo 'PASS: embedded image media verified from live local data for ' . implode(', ', $tested) . PHP_EOL;
} finally {
    foreach (glob($tempDir . DIRECTORY_SEPARATOR . '*.xlsx') ?: [] as $file) @unlink($file);
    @rmdir($tempDir);
}
