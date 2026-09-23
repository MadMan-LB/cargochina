<?php
require_once dirname(__DIR__).'/backend/config/database.php';
require_once dirname(__DIR__).'/backend/services/ShipmentAssignmentService.php';
$pdo=getDb();
function assignmentAssert($ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function assignmentFixture(PDO $pdo):array{
    $customer=(int)$pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn();$supplier=(int)$pdo->query('SELECT id FROM suppliers ORDER BY id LIMIT 1')->fetchColumn();$orders=[];$items=[];$receipts=[];$containers=[];$drafts=[];
    for($i=0;$i<3;$i++){
        $pdo->prepare("INSERT INTO orders(customer_id,supplier_id,status,created_by) VALUES (?,?,'ReadyForConsolidation',1)")->execute([$customer,$supplier]);$order=(int)$pdo->lastInsertId();$orders[]=$order;
        $pdo->prepare("INSERT INTO order_items(order_id,quantity,cartons,qty_per_carton,unit,declared_cbm,declared_weight,description_en,shipping_code) VALUES (?,10,2,5,'pieces',.2,20,'Bamboo serving trays','ASSIGNMENT-QA')")->execute([$order]);$item=(int)$pdo->lastInsertId();$items[]=$item;
        $pdo->prepare("INSERT INTO warehouse_receipts(order_id,actual_cartons,actual_cbm,actual_weight,receipt_condition,received_by) VALUES (?,2,.2,20,'good',1)")->execute([$order]);$receipt=(int)$pdo->lastInsertId();$receipts[]=$receipt;
        $pdo->prepare("INSERT INTO warehouse_receipt_items(receipt_id,order_item_id,actual_cartons,actual_quantity,actual_cbm,actual_weight,receipt_condition) VALUES (?,?,2,10,.2,20,'good')")->execute([$receipt,$item]);
    }
    for($i=0;$i<2;$i++){
        $pdo->prepare("INSERT INTO containers(code,max_cbm,max_weight,status) VALUES (?,2,200,'planning')")->execute(['SG-ASSIGNMENT-'.bin2hex(random_bytes(5))]);$containers[]=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO shipment_drafts(status,container_id) VALUES ('draft',?)")->execute([$containers[$i]]);$drafts[]=(int)$pdo->lastInsertId();
    }
    $pdo->prepare('INSERT INTO shipment_draft_orders(shipment_draft_id,order_id) VALUES (?,?)')->execute([$drafts[0],$orders[0]]);
    $pdo->prepare("UPDATE orders SET status='AssignedToContainer' WHERE id=?")->execute([$orders[0]]);
    return compact('orders','items','receipts','containers','drafts');
}
if(defined('CLMS_ASSIGNMENT_FIXTURES_ONLY'))return;
if(($argv[1]??'')==='--audit'){
    $rows=$pdo->query('SELECT order_id,COUNT(*) AS memberships FROM shipment_draft_orders GROUP BY order_id HAVING COUNT(*)>1')->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['duplicate_reservations'=>$rows],JSON_THROW_ON_ERROR);exit;
}
if(($argv[1]??'')==='--ui'){
    assignmentAssert($pdo->query('SELECT DATABASE()')->fetchColumn()==='clms_hardening_20260919','UI fixtures require isolated database');$f=assignmentFixture($pdo);
    foreach($f['containers'] as $id)$pdo->prepare('UPDATE containers SET code=? WHERE id=?')->execute(['SG-BEY-RESERVATION-'.$id,$id]);echo json_encode($f);exit;
}
if(in_array($argv[1]??'',['--probe','--worker'],true)){
    if(session_status()===PHP_SESSION_NONE)session_start();$_SESSION=['user_id'=>1,'user_roles'=>['SuperAdmin']];
    if($argv[1]==='--probe'){$pdo->beginTransaction();$f=assignmentFixture($pdo);}else{$f=json_decode($argv[3],true);}
    $case=$argv[2];$containers=require dirname(__DIR__).'/backend/api/handlers/containers.php';$drafts=require dirname(__DIR__).'/backend/api/handlers/shipment-drafts.php';
    if($argv[1]==='--probe')register_shutdown_function(static function()use($pdo,$f){
        $state=['first_status'=>$pdo->query('SELECT status FROM orders WHERE id='.$f['orders'][0])->fetchColumn(),'first_members'=>(int)$pdo->query('SELECT COUNT(*) FROM shipment_draft_orders WHERE order_id='.$f['orders'][0])->fetchColumn(),'first_draft_container'=>(int)$pdo->query('SELECT container_id FROM shipment_drafts WHERE id='.$f['drafts'][0])->fetchColumn(),'audits'=>(int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE entity_type='shipment_draft' AND entity_id IN (".implode(',',$f['drafts']).')')->fetchColumn()];
        if($pdo->inTransaction())$pdo->rollBack();echo "\n__STATE__".json_encode($state);
    });
    if($case==='direct-retry')$containers('POST',(string)$f['containers'][0],'assign-orders',['order_ids'=>[$f['orders'][0]]]);
    if($case==='detail'){
        $handler=require dirname(__DIR__).'/backend/api/handlers/orders.php';
        $handler('GET',(string)$f['orders'][0],null,[]);
    }
    if($case==='different-container')$containers('POST',(string)$f['containers'][1],'assign-orders',['order_ids'=>[$f['orders'][0]]]);
    if($case==='draft-retry')$drafts('POST',(string)$f['drafts'][0],'add-orders',['order_ids'=>[$f['orders'][0]]]);
    if($case==='different-draft')$drafts('POST',(string)$f['drafts'][1],'add-orders',['order_ids'=>[$f['orders'][0]]]);
    if($case==='partial')$pdo->prepare('UPDATE warehouse_receipt_items SET actual_quantity=5 WHERE order_item_id=?')->execute([$f['items'][1]]);
    if($case==='excess')$pdo->prepare('UPDATE warehouse_receipt_items SET actual_quantity=11 WHERE order_item_id=?')->execute([$f['items'][1]]);
    if($case==='unallocated')$pdo->prepare('DELETE FROM warehouse_receipt_items WHERE receipt_id=?')->execute([$f['receipts'][1]]);
    if($case==='mismatch')$pdo->prepare('UPDATE warehouse_receipt_items SET actual_cbm=.1 WHERE order_item_id=?')->execute([$f['items'][1]]);
    if($case==='pending')$pdo->prepare("UPDATE orders SET confirmation_token='pending-feedback' WHERE id=?")->execute([$f['orders'][1]]);
    if(in_array($case,['partial','excess','unallocated','mismatch','pending'],true)){
        $row=$pdo->query('SELECT * FROM orders WHERE id='.$f['orders'][1])->fetch(PDO::FETCH_ASSOC);$row=CargoMetricsService::attachOrders($pdo,[$row])[0];assignmentAssert(!ShipmentAssignmentService::eligible($row),'Read projection admitted invalid cargo');
        $containers('POST',(string)$f['containers'][1],'assign-orders',['order_ids'=>[$f['orders'][1]]]);
    }
    if($case==='locked'){$pdo->prepare("UPDATE containers SET status='on_route' WHERE id=?")->execute([$f['containers'][1]]);$containers('POST',(string)$f['containers'][1],'assign-orders',['order_ids'=>[$f['orders'][1]]]);}
    if($case==='finalized'){$pdo->prepare("UPDATE shipment_drafts SET status='finalized' WHERE id=?")->execute([$f['drafts'][0]]);$drafts('POST',(string)$f['drafts'][0],'remove-orders',['order_ids'=>[$f['orders'][0]]]);}
    if($case==='remove')$drafts('POST',(string)$f['drafts'][0],'remove-orders',['order_ids'=>[$f['orders'][0]]]);
    if($case==='foreign-remove')$drafts('POST',(string)$f['drafts'][1],'remove-orders',['order_ids'=>[$f['orders'][0]]]);
    if($case==='move')$drafts('POST',(string)$f['drafts'][0],'assign-container',['container_id'=>$f['containers'][1]]);
    if($case==='delete'){
        $row=$pdo->query('SELECT * FROM shipment_drafts WHERE id='.$f['drafts'][0])->fetch(PDO::FETCH_ASSOC);
        $drafts('DELETE',(string)$f['drafts'][0],null,['deletion_revision'=>RecycleBinService::shipmentDeletionRevision($row,[$f['orders'][0]])]);
    }
    if(in_array($case,['race-a','race-b'],true))$containers('POST',(string)$f['containers'][$case==='race-a'?0:1],'assign-orders',['order_ids'=>[$f['orders'][1]]]);
    if($case==='edit-received'){
        $ordersHandler=require dirname(__DIR__).'/backend/api/handlers/orders.php';
        $items=$pdo->query('SELECT * FROM order_items WHERE order_id='.$f['orders'][0])->fetchAll(PDO::FETCH_ASSOC);
        $ordersHandler('PUT',(string)$f['orders'][0],null,['items'=>$items]);
    }
    if($case==='batch-failure'){
        try{$containers('POST',(string)$f['containers'][0],'assign-orders',['order_ids'=>[$f['orders'][1],$f['orders'][2]]]);}
        catch(Throwable $e){jsonError('Injected assignment failure: '.$e->getMessage(),500);}
    }
    throw new RuntimeException('Unknown assignment test');
}
function assignmentStart(array $args):array{$pipes=[];$p=proc_open(array_merge([PHP_BINARY,__FILE__],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);return [$p,$pipes];}
function assignmentFinish(array $worker):array{[$p,$pipes]=$worker;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);assignmentAssert($exit===0 && $err==='',"Assignment probe failed: $err $out");$parts=explode("\n__STATE__",$out);return [json_decode($parts[0],true),isset($parts[1])?json_decode($parts[1],true):null];}
foreach(['direct-retry','different-container','draft-retry','different-draft','partial','excess','unallocated','mismatch','pending','locked','finalized','remove','foreign-remove','move','delete'] as $case){
    [$r,$state]=assignmentFinish(assignmentStart(['--probe',$case]));$reject=in_array($case,['different-container','different-draft','partial','excess','unallocated','mismatch','pending','locked','finalized'],true);
    assignmentAssert(!empty($r['error'])===$reject,"Unexpected $case response: ".json_encode($r));
    if(str_contains($case,'retry'))assignmentAssert(!empty($r['data']['already_applied']) && $state['first_members']===1 && $state['audits']===0,'Retry changed reservation or duplicated audit');
    if(in_array($case,['remove','delete'],true))assignmentAssert($state['first_members']===0 && $state['first_status']==='ReadyForConsolidation' && $state['audits']===1,'Release did not update membership/state/audit');
    elseif($case==='move')assignmentAssert($state['first_members']===1 && $state['first_status']==='AssignedToContainer' && $state['audits']===1,'Move lost membership/state/audit');
    else assignmentAssert($state['first_members']===1,'Rejected/no-op action changed membership');
    echo "PASS: assignment $case\n";
}
[$detail,$state]=assignmentFinish(assignmentStart(['--probe','detail']));
assignmentAssert(($detail['data']['container']['id']??null)===$state['first_draft_container'],'Assigned order detail lost its container reference');
assignmentAssert(($detail['data']['procurement_editable']??null)===false && ($detail['data']['shipment_eligible']??null)===false,'Reserved cargo was advertised as editable/eligible');
assignmentAssert((float)$detail['data']['cargo_totals']['received_quantity']===10.0,'Assignment changed physical stock');
echo "PASS: assigned order detail, edit protection, eligibility and physical stock\n";
assignmentAssert($pdo->query('SELECT DATABASE()')->fetchColumn()==='clms_hardening_20260919','Concurrent fixtures require isolated database');
foreach([['race-a','race-b'],['race-a','race-a']] as $cases){$f=assignmentFixture($pdo);$a=assignmentStart(['--worker',$cases[0],json_encode($f)]);$b=assignmentStart(['--worker',$cases[1],json_encode($f)]);[$ra]=assignmentFinish($a);[$rb]=assignmentFinish($b);$errors=count(array_filter([$ra,$rb],fn($r)=>!empty($r['error'])));assignmentAssert($errors===($cases[0]===$cases[1]?0:1),'Concurrent assignment result mismatch');assignmentAssert((int)$pdo->query('SELECT COUNT(*) FROM shipment_draft_orders WHERE order_id='.$f['orders'][1])->fetchColumn()===1,'Concurrent duplicate reservation');echo 'PASS: concurrent '.implode('/',$cases)." keeps one reservation\n";}
$f=assignmentFixture($pdo);[$edited]=assignmentFinish(assignmentStart(['--worker','edit-received',json_encode($f)]));
assignmentAssert(!empty($edited['error']),'Order editing bypassed receiving/reservation guard: '.json_encode($edited));
assignmentAssert((int)$pdo->query('SELECT COUNT(*) FROM warehouse_receipt_items WHERE receipt_id='.$f['receipts'][0])->fetchColumn()===1,'Order edit deleted physical receipt allocation');
echo "PASS: alternate order edit cannot rewrite received/reserved cargo\n";
$f=assignmentFixture($pdo);$created=false;
try{
    $pdo->exec("CREATE TRIGGER qa_assignment_rollback BEFORE INSERT ON shipment_draft_orders FOR EACH ROW BEGIN IF NEW.order_id=".(int)$f['orders'][2]." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected second reservation failure'; END IF; END");$created=true;
    [$failed]=assignmentFinish(assignmentStart(['--worker','batch-failure',json_encode($f)]));
    assignmentAssert(!empty($failed['error']),'Assignment failure was not reproduced');
    assignmentAssert((int)$pdo->query('SELECT COUNT(*) FROM shipment_draft_orders WHERE order_id IN ('.$f['orders'][1].','.$f['orders'][2].')')->fetchColumn()===0,'Failed batch left its first reservation');
    assignmentAssert((int)$pdo->query("SELECT COUNT(*) FROM orders WHERE id IN (".$f['orders'][1].','.$f['orders'][2].") AND status='ReadyForConsolidation'")->fetchColumn()===2,'Failed batch changed order states');
    assignmentAssert((int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE entity_type='shipment_draft' AND entity_id=".$f['drafts'][0])->fetchColumn()===0,'Failed batch left success audit');
    echo "PASS: mid-batch failure rolls back membership, states and audit\n";
}finally{if($created)$pdo->exec('DROP TRIGGER qa_assignment_rollback');}
