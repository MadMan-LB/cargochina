<?php
require_once dirname(__DIR__) . '/backend/services/ReceivingQuantityService.php';
$items = [['id'=>1,'quantity'=>100,'cartons'=>10,'qty_per_carton'=>10,'unit'=>'pieces'],['id'=>2,'quantity'=>40,'cartons'=>4,'qty_per_carton'=>10,'unit'=>'pieces']];
$row = ['order_item_id'=>1,'actual_cartons'=>3,'actual_quantity'=>30,'actual_cbm'=>.3,'actual_weight'=>30];
$input = ['items'=>[$row]];
$partial = ReceivingQuantityService::normalize($input, $items, []);
assertRule($partial['is_partial'] && $partial['quantity_totals'] === ['ordered'=>140.0,'current'=>30.0,'received'=>30.0,'remaining'=>110.0], 'partial counts every item');
$final = ReceivingQuantityService::normalize(['condition'=>'partial','items'=>[array_merge($row,['actual_cartons'=>7,'actual_quantity'=>70]),array_merge($row,['order_item_id'=>2,'actual_cartons'=>4,'actual_quantity'=>40])]],$items,[1=>['quantity'=>30]]);
assertRule(!$final['is_partial'] && $final['quantity_totals']['remaining']===0.0, 'final completion comes from quantities');
rejectRule(fn()=>ReceivingQuantityService::normalize($input,$items,[1=>['quantity'=>80]]),'over-receipt');
rejectRule(fn()=>ReceivingQuantityService::normalize(['items'=>[$row,$row]],$items,[]),'duplicate item');
rejectRule(fn()=>ReceivingQuantityService::normalize(['items'=>[array_merge($row,['order_item_id'=>9])]],$items,[]),'foreign item');
rejectRule(fn()=>ReceivingQuantityService::normalize(['actual_cartons'=>4,'items'=>[$row]],$items,[]),'header cartons mismatch');
foreach (['NaN','one',INF,-1,1.5,true,[]] as $bad) rejectRule(fn()=>ReceivingQuantityService::normalize(['items'=>[array_merge($row,['actual_cartons'=>$bad])]],$items,[]),'malformed carton');
foreach (['NaN','one',INF,-1,true,[]] as $bad) rejectRule(fn()=>ReceivingQuantityService::normalize(['items'=>[array_merge($row,['actual_weight'=>$bad])]],$items,[]),'malformed weight');
rejectRule(fn()=>ReceivingQuantityService::normalize(['items'=>[array_merge($row,['actual_quantity'=>0])]],$items,[]),'empty goods with stock');
rejectRule(fn()=>ReceivingQuantityService::normalize(['items'=>[array_merge($row,['actual_pieces_per_carton'=>9])]],$items,[]),'packing mismatch');
rejectRule(fn()=>ReceivingQuantityService::normalize($input,$items,[1=>['unknown_quantity'=>1]]),'unknown history');
rejectRule(fn()=>ReceivingQuantityService::normalize([],$items,[]),'ambiguous header-only receipt');
$single = ReceivingQuantityService::normalize(['actual_cartons'=>3,'actual_cbm'=>.3,'actual_weight'=>30],[$items[0]],[]);
assertRule($single['items'][0]['actual_quantity']===30.0,'single-item legacy allocation');
echo "PASS: receiving quantity, completion, over-receipt, packing, numeric and legacy-allocation rules\n";
function assertRule(bool $ok,string $name):void {if(!$ok)throw new RuntimeException($name);}
function rejectRule(callable $fn,string $name):void {try{$fn();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Accepted '.$name);}
