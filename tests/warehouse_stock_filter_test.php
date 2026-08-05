<?php

$handler = require dirname(__DIR__) . '/backend/api/handlers/warehouse-stock.php';

function warehouseFilterAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

warehouseFilterAssert(is_callable($handler), 'Warehouse stock handler did not load.');
warehouseFilterAssert(
    warehouseStockNormalizeStateFilters(['InWarehouse', 'InTransit']) === ['InWarehouse', 'InTransit'],
    'The two warehouse state filters were not preserved.'
);
warehouseFilterAssert(
    warehouseStockNormalizeStateFilters(['WarehouseReceived', 'Confirmed']) === ['InWarehouse'],
    'Legacy warehouse statuses were not normalized to InWarehouse.'
);
warehouseFilterAssert(
    warehouseStockNormalizeStateFilters('InTransitToWarehouse') === ['InTransit'],
    'Legacy in-transit status was not normalized.'
);
warehouseFilterAssert(
    array_keys(warehouseStockStatusGroups()) === ['InTransit', 'InWarehouse'],
    'Warehouse stock exposes more than the two requested operational filters.'
);

$pdo = getDb();
$searchExpressions = warehouseStockSearchExpressions($pdo);
foreach (['oi.description_en', 'oi.description_cn', 'p.description_en', 'p.description_cn'] as $requiredExpression) {
    warehouseFilterAssert(
        in_array($requiredExpression, $searchExpressions, true),
        "Warehouse description search is missing {$requiredExpression}."
    );
}

$page = file_get_contents(dirname(__DIR__) . '/warehouse_stock.php');
$script = file_get_contents(dirname(__DIR__) . '/frontend/js/warehouse_stock.js');
warehouseFilterAssert(substr_count($page, 'class="form-check-input stock-status-filter"') === 1, 'Warehouse status filter template changed unexpectedly.');
warehouseFilterAssert(str_contains($page, "'InWarehouse' => clmsT('In warehouse')"), 'In warehouse filter is missing.');
warehouseFilterAssert(str_contains($page, "'InTransit' => clmsT('In transit')"), 'In transit filter is missing.');
warehouseFilterAssert(!str_contains($page, 'filterStatusMode'), 'The old include/exclude status mode is still visible.');
warehouseFilterAssert(str_contains($script, 'stockSearchTimer'), 'Description search is not debounced.');

echo "PASS: warehouse stock has two canonical state filters and searches item/product descriptions\n";
