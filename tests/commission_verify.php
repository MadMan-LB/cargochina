<?php

/**
 * Quick verification of commission calculation logic (mirrors financials.php)
 * Run: php tests/commission_verify.php
 */

$items = [
    ['order_id' => 1, 'quantity' => 10, 'buy_price' => 5, 'sell_price' => 8, 'unit_price' => 8, 'eff_supplier_id' => 1, 'commission_rate' => 2.5, 'commission_type' => 'percentage', 'commission_applied_on' => 'buy_value'],
    ['order_id' => 1, 'quantity' => 5, 'buy_price' => 20, 'sell_price' => 25, 'unit_price' => 25, 'eff_supplier_id' => 2, 'commission_rate' => 50, 'commission_type' => 'fixed', 'commission_applied_on' => 'buy_value'],
];

$orderCommissions = [];
foreach ($items as $it) {
    $oid = (int) $it['order_id'];
    $sid = $it['eff_supplier_id'] ? (int) $it['eff_supplier_id'] : null;
    $rate = (float) ($it['commission_rate'] ?? 0);
    $type = $it['commission_type'] ?? 'percentage';
    $appliedOn = $it['commission_applied_on'] ?? 'buy_value';
    if (!$rate || !$sid) continue;
    $buyVal = (float) ($it['quantity'] ?? 0) * (float) ($it['buy_price'] ?? $it['unit_price'] ?? 0);
    $sellVal = (float) ($it['quantity'] ?? 0) * (float) ($it['sell_price'] ?? $it['unit_price'] ?? 0);
    $base = $appliedOn === 'sell_value' ? $sellVal : $buyVal;
    if ($type === 'fixed') {
        if (!isset($orderCommissions[$oid])) $orderCommissions[$oid] = ['pct' => 0, 'fixed' => []];
        if (!isset($orderCommissions[$oid]['fixed'][$sid])) $orderCommissions[$oid]['fixed'][$sid] = $rate;
    } else {
        if (!isset($orderCommissions[$oid])) $orderCommissions[$oid] = ['pct' => 0, 'fixed' => []];
        $orderCommissions[$oid]['pct'] += $base * $rate / 100;
    }
}

$comm = ($orderCommissions[1]['pct'] ?? 0) + array_sum($orderCommissions[1]['fixed'] ?? []);
$expected = 1.25 + 50; // 10*5*0.025 + 50
$ok = abs($comm - $expected) < 0.01;
echo "Order 1 commission: $comm (expected: $expected) - " . ($ok ? "PASS" : "FAIL") . "\n";

// Multi-supplier percentage
$items2 = [
    ['order_id' => 2, 'quantity' => 100, 'buy_price' => 10, 'sell_price' => 12, 'eff_supplier_id' => 1, 'commission_rate' => 3, 'commission_type' => 'percentage', 'commission_applied_on' => 'buy_value'],
    ['order_id' => 2, 'quantity' => 50, 'buy_price' => 20, 'sell_price' => 24, 'eff_supplier_id' => 2, 'commission_rate' => 2, 'commission_type' => 'percentage', 'commission_applied_on' => 'sell_value'],
];
$orderCommissions = [];
foreach ($items2 as $it) {
    $oid = (int) $it['order_id'];
    $sid = $it['eff_supplier_id'] ? (int) $it['eff_supplier_id'] : null;
    $rate = (float) ($it['commission_rate'] ?? 0);
    $type = $it['commission_type'] ?? 'percentage';
    $appliedOn = $it['commission_applied_on'] ?? 'buy_value';
    if (!$rate || !$sid) continue;
    $buyVal = (float) ($it['quantity'] ?? 0) * (float) ($it['buy_price'] ?? $it['unit_price'] ?? 0);
    $sellVal = (float) ($it['quantity'] ?? 0) * (float) ($it['sell_price'] ?? $it['unit_price'] ?? 0);
    $base = $appliedOn === 'sell_value' ? $sellVal : $buyVal;
    if ($type === 'fixed') {
        if (!isset($orderCommissions[$oid])) $orderCommissions[$oid] = ['pct' => 0, 'fixed' => []];
        if (!isset($orderCommissions[$oid]['fixed'][$sid])) $orderCommissions[$oid]['fixed'][$sid] = $rate;
    } else {
        if (!isset($orderCommissions[$oid])) $orderCommissions[$oid] = ['pct' => 0, 'fixed' => []];
        $orderCommissions[$oid]['pct'] += $base * $rate / 100;
    }
}
$comm2 = ($orderCommissions[2]['pct'] ?? 0) + array_sum($orderCommissions[2]['fixed'] ?? []);
$expected2 = 100 * 10 * 0.03 + 50 * 24 * 0.02; // 30 + 24 = 54
$ok2 = abs($comm2 - $expected2) < 0.01;
echo "Order 2 commission: $comm2 (expected: $expected2) - " . ($ok2 ? "PASS" : "FAIL") . "\n";

exit(($ok && $ok2) ? 0 : 1);
