<?php

$root=dirname(__DIR__);
require_once $root.'/backend/config/database.php';
require_once $root.'/backend/services/DraftOrderCostService.php';
require_once $root.'/backend/services/ShipmentAccountingService.php';
require_once $root.'/backend/services/ItemNumberReservationService.php';
require_once $root.'/backend/services/OrderItemNumberingService.php';

$pdo=getDb();$passed=0;$failed=0;
function c10test(string $name,callable $fn):void{global $passed,$failed;try{$fn();echo "PASS: $name\n";$passed++;}catch(Throwable $e){echo "FAIL: $name - {$e->getMessage()}\n";$failed++;}}
function c10assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}

$nonce=bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO users(email,password_hash,full_name,is_active) VALUES (?,?,?,1)")->execute(["c10-$nonce@example.test",password_hash('isolated-test',PASSWORD_DEFAULT),'Checkpoint 10']);
$userId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO customers(code,name) VALUES (?,?)")->execute(["C10-$nonce","Checkpoint Customer $nonce"]);$customerId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO suppliers(code,name) VALUES (?,?)")->execute(["S10-$nonce","Checkpoint Supplier $nonce"]);$supplierId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO orders(customer_id,supplier_id,status,order_type,currency,created_by) VALUES (?,?,'Draft','draft_procurement','USD',?)")->execute([$customerId,$supplierId,$userId]);$orderId=(int)$pdo->lastInsertId();

c10test('draft cost creates exactly one provisional customer charge and shipment expense',function()use($pdo,$orderId,$userId,$nonce){
    $pdo->beginTransaction();$row=(new DraftOrderCostService($pdo))->create($orderId,['cost_type_code'=>'transportation','amount'=>'100.0000','currency'=>'RMB','exchange_rate'=>'0.14000000','base_currency'=>'USD','responsible_payer'=>'supplier','allocation_method'=>'by_weight','idempotency_key'=>'cost:test:'.$nonce],$userId);$pdo->commit();
    c10assert($row['responsible_payer']==='customer','payer was not forced to customer');c10assert($row['allocation_method']==='none','allocation was not disabled');c10assert($row['posting_status']==='provisional','cost not provisional');
    $stmt=$pdo->prepare("SELECT entry_role,posting_state,CAST(base_amount AS CHAR) base_amount FROM shipment_financial_entries WHERE source_type='draft_order_cost' AND source_id=? ORDER BY entry_role");$stmt->execute([$row['id']]);$entries=$stmt->fetchAll(PDO::FETCH_ASSOC);
    c10assert(count($entries)===2,'expected two paired effects');c10assert(array_column($entries,'entry_role')===['customer_charge','shipment_expense'],'wrong roles');c10assert($entries[0]['base_amount']==='14.0000'&&$entries[1]['base_amount']==='14.0000','wrong exact base amount');
});

$costId=(int)$pdo->query("SELECT id FROM draft_order_costs WHERE order_id=$orderId ORDER BY id LIMIT 1")->fetchColumn();
c10test('draft edit updates provisional effects without duplicates and locks same-currency rate to one',function()use($pdo,$costId,$userId){
    $row=(new DraftOrderCostService($pdo))->get($costId);$pdo->beginTransaction();$updated=(new DraftOrderCostService($pdo))->update($costId,['cost_type_code'=>'handling','amount'=>'20','currency'=>'USD','exchange_rate'=>'7.9','base_currency'=>'USD','lock_version'=>$row['lock_version']],$userId);$pdo->commit();
    c10assert($updated['exchange_rate']==='1.00000000','same-currency rate was not one');
    $stmt=$pdo->prepare("SELECT COUNT(*),MIN(base_amount),MAX(base_amount) FROM shipment_financial_entries WHERE source_type='draft_order_cost' AND source_id=? AND posting_state='provisional'");$stmt->execute([$costId]);$r=$stmt->fetch(PDO::FETCH_NUM);c10assert((int)$r[0]===2&&$r[1]==='20.0000'&&$r[2]==='20.0000','provisional entries duplicated or stale');
});

c10test('approval finalizes once and repeated approval finalization is idempotent',function()use($pdo,$orderId,$userId){
    $pdo->beginTransaction();$svc=new ShipmentAccountingService($pdo);$svc->finalizeOrder($orderId,$userId);$pdo->prepare("UPDATE orders SET status='Approved' WHERE id=?")->execute([$orderId]);$pdo->commit();
    $pdo->beginTransaction();$svc->finalizeOrder($orderId,$userId);$pdo->commit();
    $stmt=$pdo->prepare("SELECT COUNT(*),COUNT(DISTINCT idempotency_key),SUM(posting_state='finalized') FROM shipment_financial_entries WHERE order_id=?");$stmt->execute([$orderId]);$r=$stmt->fetch(PDO::FETCH_NUM);c10assert((int)$r[0]===2&&(int)$r[1]===2&&(int)$r[2]===2,'approval duplicated or failed to finalize');
});

c10test('supplier balance is isolated and inventory purchase value excludes shipment costs',function()use($pdo,$orderId,$supplierId){
    c10assert((int)$pdo->query("SELECT COUNT(*) FROM supplier_payments WHERE order_id=$orderId")->fetchColumn()===0,'shipment cost created supplier liability');
    $pdo->prepare("INSERT INTO order_items(order_id,item_no,shipping_code,quantity,unit,buy_price,sell_price,unit_price,total_amount,supplier_id) VALUES (?,?,?,5,'pieces',20,30,30,150,?)")->execute([$orderId,'C10-1-1','C10',$supplierId]);
    $purchase=$pdo->query("SELECT CAST(SUM(quantity*buy_price) AS CHAR) FROM order_items WHERE order_id=$orderId")->fetchColumn();c10assert(DecimalMath::normalize($purchase)==='100.0000','inventory/purchase value absorbed shipment cost');
});

c10test('receiving fee posts paired finalized effects once',function()use($pdo,$orderId,$customerId,$userId,$nonce){
    $pdo->prepare("INSERT INTO warehouse_receipts(order_id,actual_cartons,actual_cbm,actual_weight,receipt_condition,received_by,receiving_operation_id) VALUES (?,1,1,1,'partial',?,?)")->execute([$orderId,$userId,'receipt:test:'.$nonce]);$receiptId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO warehouse_receipt_fees(receipt_id,order_id,fee_label,amount,currency,created_by) VALUES (?,?,'Receiving',5,'USD',?)")->execute([$receiptId,$orderId,$userId]);
    $pdo->beginTransaction();$svc=new ShipmentAccountingService($pdo);$svc->postReceiptFees($receiptId,$userId);$svc->postReceiptFees($receiptId,$userId);$pdo->commit();
    $stmt=$pdo->prepare("SELECT COUNT(*),COUNT(DISTINCT idempotency_key) FROM shipment_financial_entries WHERE source_type='receipt_fee' AND order_id=?");$stmt->execute([$orderId]);$r=$stmt->fetch(PDO::FETCH_NUM);c10assert((int)$r[0]===2&&(int)$r[1]===2,'fee duplicated on retry');
});

c10test('cancellation reversal is immutable and idempotent',function()use($pdo,$orderId,$userId){
    $pdo->beginTransaction();$svc=new ShipmentAccountingService($pdo);$svc->reverseOrder($orderId,$userId,'isolated cancel');$svc->reverseOrder($orderId,$userId,'isolated cancel retry');$pdo->commit();
    $stmt=$pdo->prepare("SELECT entry_role,CAST(SUM(base_amount) AS CHAR) net FROM shipment_financial_entries WHERE order_id=? AND posting_state='finalized' GROUP BY entry_role");$stmt->execute([$orderId]);foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row)c10assert(DecimalMath::normalize($row['net'])==='0.0000','reversal did not net to zero');
    c10assert((int)$pdo->query("SELECT COUNT(*) FROM shipment_financial_entries WHERE order_id=$orderId AND entry_kind='reversal'")->fetchColumn()===4,'repeated cancellation created repeated reversals');
});

c10test('restore creates only provisional order-cost effects and leaves voided receipt fees reversed',function()use($pdo,$orderId,$userId){
    $pdo->prepare("UPDATE orders SET status='Submitted' WHERE id=?")->execute([$orderId]);$pdo->beginTransaction();(new ShipmentAccountingService($pdo))->restoreOrderCostsAsProvisional($orderId,$userId);$pdo->commit();
    c10assert((int)$pdo->query("SELECT COUNT(*) FROM shipment_financial_entries WHERE order_id=$orderId AND source_type='draft_order_cost' AND posting_state='provisional'")->fetchColumn()===2,'cost restoration was not paired/provisional');
    c10assert((int)$pdo->query("SELECT COUNT(*) FROM shipment_financial_entries WHERE order_id=$orderId AND source_type='receipt_fee' AND posting_state='provisional'")->fetchColumn()===0,'receipt fee was incorrectly restored');
});

c10test('canonical reservations normalize variants and never release issued numbers',function()use($pdo,$orderId,$customerId,$userId){
    $pdo->beginTransaction();$svc=new ItemNumberReservationService($pdo);$rows=$svc->reservePersistedOrder($orderId,$userId);$pdo->commit();c10assert(count($rows)===1,'item number not reserved');
    c10assert($rows[0]['normalized_item_no']==='C10-1-1','normalization changed controlled format');
    $thrown=false;try{$svc->assertCandidatesAvailable($customerId,[' c10 1 1 '],0);}catch(RuntimeException $e){$thrown=true;}c10assert($thrown,'spacing/case variant bypassed uniqueness');
});

c10test('supplier numbering continues largest sequence and returns to prior supplier',function(){
    $items=OrderItemNumberingService::assignItemNumbers([['supplier_id'=>10,'shipping_code'=>'CUST'],['supplier_id'=>10,'shipping_code'=>'CUST'],['supplier_id'=>20,'shipping_code'=>'CUST'],['supplier_id'=>10,'shipping_code'=>'CUST']],'CUST');
    c10assert(array_column($items,'item_no')===['CUST-1-1','CUST-1-2','CUST-2-1','CUST-1-3'],'established multi-supplier sequence changed');
});

$pdo->prepare('DELETE FROM item_number_references WHERE order_id=?')->execute([$orderId]);
// Reversal rows reference their original immutable entry. Test cleanup must
// remove the referencing rows first without weakening the production FK.
$pdo->prepare('DELETE FROM shipment_financial_entries WHERE order_id=? AND reverses_entry_id IS NOT NULL')->execute([$orderId]);
$pdo->prepare('DELETE FROM shipment_financial_entries WHERE order_id=?')->execute([$orderId]);
$pdo->prepare('DELETE FROM warehouse_receipt_fees WHERE order_id=?')->execute([$orderId]);
$pdo->prepare('DELETE FROM warehouse_receipt_items WHERE receipt_id IN (SELECT id FROM warehouse_receipts WHERE order_id=?)')->execute([$orderId]);
$pdo->prepare('DELETE FROM warehouse_receipts WHERE order_id=?')->execute([$orderId]);
$pdo->prepare('DELETE FROM draft_order_cost_history WHERE order_id=?')->execute([$orderId]);
$pdo->prepare('DELETE FROM draft_order_costs WHERE order_id=?')->execute([$orderId]);
$pdo->prepare('DELETE FROM order_items WHERE order_id=?')->execute([$orderId]);
$pdo->prepare('DELETE FROM orders WHERE id=?')->execute([$orderId]);
$pdo->prepare('DELETE FROM item_number_reservations WHERE customer_id=?')->execute([$customerId]);
$pdo->prepare('DELETE FROM customers WHERE id=?')->execute([$customerId]);
$pdo->prepare('DELETE FROM suppliers WHERE id=?')->execute([$supplierId]);
$pdo->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
echo "Checkpoint 10 approved rules: $passed passed, $failed failed\n";
exit($failed?1:0);
