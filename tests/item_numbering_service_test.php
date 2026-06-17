<?php

/**
 * Order item numbering regression tests
 * Run: php tests/item_numbering_service_test.php
 */

$root = dirname(__DIR__);
require_once $root . '/backend/services/OrderItemNumberingService.php';

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

function assertItemNo(array $items, int $index, string $expected): void
{
    $actual = (string) ($items[$index]['item_no'] ?? '');
    if ($actual !== $expected) {
        throw new Exception("Expected item #$index to be $expected, got $actual");
    }
}

test('continues item sequence from previous saved order for same supplier and prefix', function () {
    $items = [[
        'supplier_id' => 10,
        'shipping_code' => 'SC',
    ]];
    $history = [[
        'supplier_id' => 10,
        'item_no' => 'SC-1-7',
    ]];

    $numbered = OrderItemNumberingService::assignItemNumbers($items, 'SC', null, $history);
    assertItemNo($numbered, 0, 'SC-1-8');
});

test('allocates new suppliers after the highest historical supplier sequence', function () {
    $items = [[
        'supplier_id' => 30,
        'shipping_code' => 'SC',
    ]];
    $history = [
        ['supplier_id' => 10, 'item_no' => 'SC-1-4'],
        ['supplier_id' => 20, 'item_no' => 'SC-5-1'],
    ];

    $numbered = OrderItemNumberingService::assignItemNumbers($items, 'SC', null, $history);
    assertItemNo($numbered, 0, 'SC-6-1');
});

test('manual override seeds the following automatic rows in the same order', function () {
    $items = [
        [
            'supplier_id' => 10,
            'shipping_code' => 'SC',
            'item_no' => 'SC-5-20',
            'item_no_manual' => 1,
        ],
        [
            'supplier_id' => 10,
            'shipping_code' => 'SC',
        ],
        [
            'supplier_id' => 30,
            'shipping_code' => 'SC',
        ],
    ];

    $numbered = OrderItemNumberingService::assignItemNumbers($items, 'SC');
    assertItemNo($numbered, 0, 'SC-5-20');
    assertItemNo($numbered, 1, 'SC-5-21');
    assertItemNo($numbered, 2, 'SC-6-1');
});

test('history for another prefix does not change the active shipping code sequence', function () {
    $items = [[
        'supplier_id' => 10,
        'shipping_code' => 'SC',
    ]];
    $history = [[
        'supplier_id' => 10,
        'item_no' => 'OTHER-9-99',
    ]];

    $numbered = OrderItemNumberingService::assignItemNumbers($items, 'SC', null, $history);
    assertItemNo($numbered, 0, 'SC-1-1');
});

echo "\nTotal: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
