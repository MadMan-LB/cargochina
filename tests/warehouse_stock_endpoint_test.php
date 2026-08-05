<?php

if (($argv[1] ?? '') === '--probe') {
    session_start();
    $_SESSION['user_id'] = 1;
    $_SESSION['user_roles'] = ['SuperAdmin'];
    $decoded = base64_decode((string) ($argv[2] ?? ''), true);
    $_GET = is_string($decoded) ? (json_decode($decoded, true) ?: []) : [];
    $handler = require dirname(__DIR__) . '/backend/api/handlers/warehouse-stock.php';
    $handler('GET', null, null, []);
    exit;
}

require_once dirname(__DIR__) . '/backend/config/database.php';

function warehouseEndpointAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function warehouseEndpointRequest(array $query): array
{
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__FILE__)
        . ' --probe ' . escapeshellarg(base64_encode(json_encode($query, JSON_UNESCAPED_UNICODE)));
    $lines = [];
    $exitCode = 0;
    exec($command, $lines, $exitCode);
    warehouseEndpointAssert($exitCode === 0, 'Warehouse endpoint probe failed: ' . implode("\n", $lines));
    $payload = json_decode((string) end($lines), true);
    warehouseEndpointAssert(is_array($payload), 'Warehouse endpoint did not return JSON: ' . implode("\n", $lines));
    return $payload;
}

$pdo = getDb();
$candidate = $pdo->query(
    "SELECT oi.id AS item_id, COALESCE(NULLIF(TRIM(p.description_en), ''), NULLIF(TRIM(p.description_cn), '')) AS description
     FROM orders o
     JOIN order_items oi ON oi.order_id=o.id
     JOIN products p ON p.id=oi.product_id
     WHERE o.status IN ('InTransitToWarehouse','ReceivedAtWarehouse','AwaitingCustomerConfirmation','Confirmed','ReadyForConsolidation')
       AND (TRIM(COALESCE(p.description_en, '')) <> '' OR TRIM(COALESCE(p.description_cn, '')) <> '')
     ORDER BY o.id DESC, oi.id DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
warehouseEndpointAssert(is_array($candidate), 'No stock item with a product description exists for endpoint testing.');

$searchResult = warehouseEndpointRequest(['q' => (string) $candidate['description'], 'limit' => 200]);
$matchedDescription = array_filter($searchResult['data'] ?? [], static function (array $row) use ($candidate): bool {
    $needle = mb_strtolower(trim((string) $candidate['description']), 'UTF-8');
    foreach (['description_en', 'description_cn', 'product_desc_en', 'product_desc_cn'] as $field) {
        if (mb_strtolower(trim((string) ($row[$field] ?? '')), 'UTF-8') === $needle) return true;
    }
    return false;
});
warehouseEndpointAssert(
    $matchedDescription !== [],
    'Searching the displayed product description did not return its warehouse item. Candidate: '
        . json_encode($candidate, JSON_UNESCAPED_UNICODE)
        . '; response: ' . json_encode($searchResult, JSON_UNESCAPED_UNICODE)
);

foreach (['InTransit', 'InWarehouse'] as $state) {
    $result = warehouseEndpointRequest(['status' => [$state], 'limit' => 200]);
    foreach (($result['data'] ?? []) as $row) {
        warehouseEndpointAssert(
            ($row['warehouse_state'] ?? '') === $state,
            "{$state} filter returned a row classified as " . ($row['warehouse_state'] ?? 'unknown')
        );
    }
}

echo "PASS: live warehouse endpoint searches displayed product descriptions and enforces both state filters\n";
