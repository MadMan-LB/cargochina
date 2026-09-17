<?php
// Pure text/numbering regressions: no database changes.
require_once dirname(__DIR__) . '/backend/services/PackingListItemNumber.php';
require_once dirname(__DIR__) . '/backend/services/OrderItemNumberingService.php';
$checks = 0;
function packingAssert(bool $ok, string $name): void {
    global $checks;
    if (!$ok) throw new RuntimeException($name);
    $checks++;
}
foreach ([null, '', 'HZ', '00125', 'PL-004', 'A/22', ' Mixed Case ', '=1+1', str_repeat('货', 150)] as $value) {
    packingAssert(clmsPackingListItemNumber($value) === $value, 'Exact reference preservation');
}
foreach ([125, ['HZ'], str_repeat('x', 151), "PL\n001", "A\0B"] as $value) {
    try { clmsPackingListItemNumber($value); throw new RuntimeException('Invalid value accepted'); }
    catch (InvalidArgumentException $e) { $checks++; }
}
$items = [
    ['supplier_id'=>1, 'item_number'=>'HZ'],
    ['supplier_id'=>1, 'item_number'=>'HZ'],
    ['supplier_id'=>2, 'item_number'=>'00125'],
];
$numbered = OrderItemNumberingService::assignItemNumbers($items, 'HZ', 1);
packingAssert(array_column($numbered, 'item_number') === ['HZ','HZ','00125'], 'Internal numbering leaves duplicate packing references alone');
packingAssert(count(array_unique(array_column($numbered, 'item_no'))) === 3, 'Distinct automatic internal identifiers');
foreach (['Draft','Approved','Received','Confirmed','ReadyForConsolidation','AssignedToContainer','Finalized'] as $status) {
    $prepared = OrderItemNumberingService::prepareItemsForPersistence($numbered, $status, 'OTHER', 2);
    packingAssert(array_column($prepared, 'item_number') === ['HZ','HZ','00125'], 'Lifecycle keeps packing references: ' . $status);
}
packingAssert(str_contains(clmsSharedCartonIdentifierSearch('oi.shared_carton_contents', 'item_number'), 'JSON_SEARCH'), 'Search decoded references, including slashes');
packingAssert(str_contains(clmsSharedCartonIdentifierSearch('oi.shared_carton_contents', 'item_no'), 'JSON_VALID'), 'Malformed historical JSON cannot break identifier searches');
packingAssert(str_contains(clmsSharedCartonIdentifierSearch('oi2.shared_carton_contents', 'item_number'), 'JSON_SEARCH'), 'Container search aliases support digits');
try { clmsSharedCartonIdentifierSearch('unsafe); SELECT', 'item_number'); throw new RuntimeException('Unsafe SQL expression accepted'); }
catch (InvalidArgumentException $e) { $checks++; }
echo "PASS: $checks packing-list identifier assertions\n";
