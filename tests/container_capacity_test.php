<?php
require_once dirname(__DIR__).'/backend/config/database.php';
require_once dirname(__DIR__).'/backend/services/ContainerCapacityService.php';
$pdo=getDb();
function capacityAssert($ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function capacityFixture(PDO $pdo):array{
    $customer=(int)$pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn();$supplier=(int)$pdo->query('SELECT id FROM suppliers ORDER BY id LIMIT 1')->fetchColumn();$orders=[];
    foreach([[.4,40],[.6,60],[.6,60]] as $index=>[$cbm,$weight]){
        $pdo->prepare("INSERT INTO orders(customer_id,supplier_id,status,created_by) VALUES (?,?,'ReadyForConsolidation',1)")->execute([$customer,$supplier]);$order=(int)$pdo->lastInsertId();$orders[]=$order;
        $pdo->prepare("INSERT INTO order_items(order_id,quantity,cartons,qty_per_carton,unit,declared_cbm,declared_weight,description_en,shipping_code) VALUES (?,100,10,10,'pieces',?,?,'Ceramic serving bowls','CAPACITY-QA')")->execute([$order,$cbm,$weight]);$item=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO warehouse_receipts(order_id,actual_cartons,actual_cbm,actual_weight,receipt_condition,received_by) VALUES (?,10,?,?,'good',1)")->execute([$order,$cbm,$weight]);$receipt=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO warehouse_receipt_items(receipt_id,order_item_id,actual_cartons,actual_quantity,actual_cbm,actual_weight,receipt_condition) VALUES (?,?,10,100,?,?,'good')")->execute([$receipt,$item,$cbm,$weight]);
    }
    $pdo->prepare("INSERT INTO containers(code,max_cbm,max_weight,status) VALUES (?,1,100,'planning')")->execute(['SG-CAPACITY-'.bin2hex(random_bytes(5))]);$container=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO shipment_drafts(status,container_id) VALUES ('draft',?)")->execute([$container]);$draft=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO shipment_draft_orders(shipment_draft_id,order_id) VALUES (?,?)')->execute([$draft,$orders[0]]);
    $pdo->prepare("UPDATE orders SET status='AssignedToContainer' WHERE id=?")->execute([$orders[0]]);
    return compact('orders','container','draft');
}
if(($argv[1]??'')==='--ui'){
    if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='clms_hardening_20260919')throw new RuntimeException('UI fixtures require isolated database');
    $f=capacityFixture($pdo);$f['code']='SG-BEY-CAPACITY-'.$f['container'];$pdo->prepare('UPDATE containers SET code=? WHERE id=?')->execute([$f['code'],$f['container']]);echo json_encode($f);exit;
}
if(($argv[1]??'')==='--snapshot'){
    $f=json_decode($argv[2],true);$pdo->beginTransaction();
    // Establish a stale repeatable-read snapshot before another operator commits.
    $pdo->query('SELECT COUNT(*) FROM shipment_draft_orders')->fetchColumn();echo "READY\n";flush();fgets(STDIN);
    $s=$pdo->prepare('SELECT * FROM containers WHERE id=? FOR UPDATE');$s->execute([$f['container']]);
    try{ContainerCapacityService::check($pdo,$s->fetch(PDO::FETCH_ASSOC),[$f['orders'][2]]);echo json_encode(['rejected'=>false]);}catch(ContainerCapacityException $e){echo json_encode(['rejected'=>$e->overCapacity]);}finally{$pdo->rollBack();}exit;
}
if(in_array($argv[1]??'',['--probe','--worker'],true)){
    if(session_status()===PHP_SESSION_NONE)session_start();
    if($argv[1]==='--probe'){$pdo->beginTransaction();register_shutdown_function(static function()use($pdo){if($pdo->inTransaction())$pdo->rollBack();});$f=capacityFixture($pdo);}else{$f=json_decode($argv[3],true);}
    $_SESSION=['user_id'=>1,'user_roles'=>['SuperAdmin']];$case=$argv[2];$containers=require dirname(__DIR__).'/backend/api/handlers/containers.php';$drafts=require dirname(__DIR__).'/backend/api/handlers/shipment-drafts.php';
    require_once __DIR__.'/support/container_test_handler.php';$containers=containerTestHandler($pdo,$containers);
    if($case==='exact')$containers('POST',(string)$f['container'],'assign-orders',['order_ids'=>[$f['orders'][1]]]);
    if($case==='bulk'){$pdo->prepare('DELETE FROM shipment_draft_orders WHERE shipment_draft_id=?')->execute([$f['draft']]);$pdo->prepare("UPDATE orders SET status='ReadyForConsolidation' WHERE id=?")->execute([$f['orders'][0]]);$containers('POST',(string)$f['container'],'assign-orders',['order_ids'=>[$f['orders'][0],$f['orders'][1],$f['orders'][1]]]);}
    if(in_array($case,['over','force'],true))$containers('POST',(string)$f['container'],'assign-orders',['order_ids'=>[$f['orders'][1],$f['orders'][2]],'force'=>$case==='force']);
    if($case==='weight'){$pdo->prepare('UPDATE containers SET max_weight=99.9999 WHERE id=?')->execute([$f['container']]);$containers('POST',(string)$f['container'],'assign-orders',['order_ids'=>[$f['orders'][1]]]);}
    if($case==='micro-cbm'){$pdo->prepare('UPDATE warehouse_receipts SET actual_cbm=.600001 WHERE order_id=?')->execute([$f['orders'][1]]);$pdo->prepare('UPDATE warehouse_receipt_items wri JOIN warehouse_receipts wr ON wr.id=wri.receipt_id SET wri.actual_cbm=.600001 WHERE wr.order_id=?')->execute([$f['orders'][1]]);$containers('POST',(string)$f['container'],'assign-orders',['order_ids'=>[$f['orders'][1]]]);}
    if($case==='unknown'){$pdo->prepare("UPDATE warehouse_receipts SET voided_at=NOW(),void_reason='Rollback capacity regression' WHERE order_id=?")->execute([$f['orders'][0]]);$containers('POST',(string)$f['container'],'assign-orders',['order_ids'=>[$f['orders'][1]]]);}
    if($case==='draft-add')$drafts('POST',(string)$f['draft'],'add-orders',['order_ids'=>[$f['orders'][1]]]);
    if($case==='race-add')$drafts('POST',(string)$f['draft'],'add-orders',['order_ids'=>[$f['orders'][2]]]);
    if($case==='draft-over')$drafts('POST',(string)$f['draft'],'add-orders',['order_ids'=>[$f['orders'][1],$f['orders'][2]]]);
    if($case==='draft-assign'||$case==='draft-assign-over'){
        $pdo->exec("INSERT INTO shipment_drafts(status) VALUES ('draft')");$newDraft=(int)$pdo->lastInsertId();$ids=$case==='draft-assign'?[$f['orders'][1]]:[$f['orders'][1],$f['orders'][2]];
        foreach($ids as $oid){$pdo->prepare('INSERT INTO shipment_draft_orders(shipment_draft_id,order_id) VALUES (?,?)')->execute([$newDraft,$oid]);$pdo->prepare("UPDATE orders SET status='ConsolidatedIntoShipmentDraft' WHERE id=?")->execute([$oid]);}
        $drafts('POST',(string)$newDraft,'assign-container',['container_id'=>$f['container']]);
    }
    if($case==='edit-low')$containers('PUT',(string)$f['container'],null,['max_cbm'=>.3999]);
    if($case==='edit-exact')$containers('PUT',(string)$f['container'],null,['max_cbm'=>.4,'max_weight'=>40]);
    if($case==='race-edit')$containers('PUT',(string)$f['container'],null,['max_cbm'=>.8]);
    if($case==='invalid')$containers('PUT',(string)$f['container'],null,['max_cbm'=>[1]]);
    if($case==='finalize-over'){$pdo->prepare('UPDATE containers SET max_cbm=.3 WHERE id=?')->execute([$f['container']]);$drafts('POST',(string)$f['draft'],'finalize',[]);}
    if($case==='weight-filter'){$pdo->prepare('UPDATE containers SET max_cbm=10,max_weight=40 WHERE id=?')->execute([$f['container']]);$_GET=['q'=>$pdo->query('SELECT code FROM containers WHERE id='.(int)$f['container'])->fetchColumn(),'fill'=>'full'];$containers('GET',null,null,[]);}
    if($case==='unknown-read'){$pdo->prepare("UPDATE warehouse_receipts SET voided_at=NOW(),void_reason='Rollback capacity projection' WHERE order_id=?")->execute([$f['orders'][0]]);$containers('GET',(string)$f['container'],null,[]);}
    throw new RuntimeException('Unknown probe '.$case);
}
function capacityStart(array $args):array{$pipes=[];$p=proc_open(array_merge([PHP_BINARY,__FILE__],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);return [$p,$pipes];}
function capacityFinish(array $worker):array{[$p,$pipes]=$worker;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);$result=json_decode($out,true);capacityAssert($exit===0 && $err==='' && is_array($result),"Probe failed: $err $out");return $result;}
foreach(['exact','bulk','over','force','weight','micro-cbm','unknown','draft-add','draft-over','draft-assign','draft-assign-over','edit-low','edit-exact','invalid','finalize-over','weight-filter','unknown-read'] as $case){
    $r=capacityFinish(capacityStart(['--probe',$case]));$reject=in_array($case,['over','force','weight','micro-cbm','unknown','draft-over','draft-assign-over','edit-low','invalid','finalize-over'],true);
    capacityAssert(!empty($r['error'])===$reject,"$case unexpected result: ".json_encode($r));
    if(in_array($case,['exact','bulk'],true))capacityAssert((float)$r['data']['used_cbm']===1.0 && (float)$r['data']['used_weight']===100.0,'Exact capacity did not persist correct totals');
    if($case==='weight-filter')capacityAssert(count($r['data'])===1 && (float)$r['data'][0]['used_weight']===40.0,'Weight-full container missing from full filter');
    if($case==='unknown-read')capacityAssert($r['data']['capacity_known']===false && $r['data']['used_cbm']===null,'Unknown existing load displayed as available capacity');
    echo "PASS: capacity $case\n";
}
foreach([NAN,INF,'abc',0,-1,[],true,.00001,1000000] as $bad){$rejected=false;try{ContainerCapacityService::limit($bad,'Limit');}catch(ContainerCapacityException $e){$rejected=true;}capacityAssert($rejected,'Invalid capacity accepted');}
echo "PASS: capacity numeric validation\n";
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='clms_hardening_20260919')throw new RuntimeException('Concurrent committed fixtures require disposable hardening database');
foreach(['race-add','race-edit'] as $race){
    $f=capacityFixture($pdo);$a=capacityStart(['--worker','exact',json_encode($f)]);$b=capacityStart(['--worker',$race,json_encode($f)]);$results=[capacityFinish($a),capacityFinish($b)];
    capacityAssert(count(array_filter($results,fn($r)=>!empty($r['error'])))===1,'Concurrent capacity operations did not serialize: '.json_encode($results));
    $pdo->beginTransaction();try{$s=$pdo->prepare('SELECT * FROM containers WHERE id=? FOR UPDATE');$s->execute([$f['container']]);$load=ContainerCapacityService::check($pdo,$s->fetch(PDO::FETCH_ASSOC));}finally{$pdo->rollBack();}
    echo "PASS: capacity concurrent $race remains within limits\n";
}
$f=capacityFixture($pdo);$pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--snapshot',json_encode($f)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
capacityAssert(trim(fgets($pipes[1]))==='READY','Snapshot worker did not start');
$assigned=capacityFinish(capacityStart(['--worker','exact',json_encode($f)]));capacityAssert(empty($assigned['error']),'Competing assignment failed');
fwrite($pipes[0],"GO\n");fclose($pipes[0]);$result=json_decode(stream_get_contents($pipes[1]),true);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
capacityAssert($exit===0 && $err==='' && !empty($result['rejected']),'Stale snapshot missed committed container load');
echo "PASS: locking capacity reads see committed load despite an earlier repeatable-read snapshot\n";
