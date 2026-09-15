<?php
// Pure regression tests; no database writes or handler execution.
require_once dirname(__DIR__) . '/backend/api/handlers/draft-orders.php';
$failures = 0;
function checkNumber(bool $condition, string $name): void {
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $name . PHP_EOL;
    if (!$condition) $failures++;
}
checkNumber(draftOrderNormalizeItemNumberSource(['item_no_source'=>'generated','item_no_manual'=>1]) === 'manual', 'manual flag overrides stale generated source');
foreach (['TEST-001', '0012', 'A-001', '0', 'Mixed_Case-009'] as $number) {
    $saved = ['item_no'=>'QA-1-1', 'item_no_source'=>'generated'];
    $request = ['item_no'=>$number, 'item_no_source'=>'generated'];
    for ($i=0; $i<3; $i++) {
        $request = draftOrderPreserveItemNumber($request, $saved);
        $saved = OrderItemNumberingService::assignItemNumbers([$request], 'QA', 1)[0];
        checkNumber($saved['item_no'] === $number && $saved['item_no_source'] === 'manual', "$number stays exact after save cycle $i");
        // Simulate a legacy reload that omits provenance.
        $request = ['item_no'=>$saved['item_no'], 'item_no_source'=>'generated'];
    }
}
$old = ['item_no'=>'QA-1-007', 'item_no_source'=>'generated'];
$preserved = draftOrderPreserveItemNumber($old, $old);
$assigned = OrderItemNumberingService::assignItemNumbers([$preserved, ['supplier_id'=>1]], 'QA', 1);
checkNumber($assigned[0]['item_no'] === 'QA-1-007', 'saved automatic number is stable');
checkNumber($assigned[1]['item_no'] === 'QA-1-008', 'new automatic number still increments with padding');
foreach (['Approved', 'Received', 'Confirmed', 'ReadyForConsolidation', 'AssignedToContainer', 'Finalized'] as $status) {
    $result = OrderItemNumberingService::prepareItemsForPersistence([['item_no'=>'TEST-001','item_no_source'=>'manual']], $status, 'OTHER', 2);
    checkNumber($result[0]['item_no'] === 'TEST-001', "downstream $status does not renumber");
}
exit($failures ? 1 : 0);
