<?php
define('CLMS_ASSIGNMENT_FIXTURES_ONLY',true);
require __DIR__.'/assignment_domain_test.php';
require_once dirname(__DIR__).'/backend/services/ContainerWriteService.php';
assignmentAssert($pdo->query('SELECT DATABASE()')->fetchColumn()==='clms_hardening_20260919','Container tests require disposable database');
if(($argv[1]??'')==='--worker'){
    session_start();$req=json_decode($argv[2],true);$_SESSION=['user_id'=>1,'user_roles'=>['SuperAdmin']];if(!empty($req['forbidden']))$_SESSION=['user_id'=>(int)$req['forbidden'],'user_roles'=>[]];$_GET=$req['query']??[];
    $handler=require dirname(__DIR__).'/backend/api/handlers/containers.php';
    try{$handler($req['method'],$req['id']??null,$req['action']??null,$req['body']??[]);}catch(Throwable $e){jsonError($e->getMessage(),500);}exit;
}
function containerStart(array $req):array{$pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'--worker',json_encode($req)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);return [$p,$pipes];}
function containerFinish(array $worker):array{[$p,$pipes]=$worker;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);assignmentAssert($exit===0&&$err==='','Container worker failed: '.$err.' '.$out);$result=json_decode($out,true);assignmentAssert(is_array($result),'Invalid container JSON: '.$out);return $result;}
function containerCall(string $method,?int $id,array $body=[],?string $action=null,array $query=[]):array{return containerFinish(containerStart(['method'=>$method,'id'=>$id===null?null:(string)$id,'body'=>$body,'action'=>$action,'query'=>$query]));}
$base=['code'=>'SG-BEY-OPERATIONS-'.bin2hex(random_bytes(4)),'max_cbm'=>28,'max_weight'=>28000,'expected_ship_date'=>'2026-10-01','eta_date'=>'2026-10-25','destination_country'=>'Lebanon','destination'=>'Beirut','vessel_name'=>'CMA CGM SEINE','notes'=>'October homeware consolidation','idempotency_key'=>'container-qa-'.bin2hex(random_bytes(8))];
$req=['method'=>'POST','body'=>$base];$a=containerStart($req);$b=containerStart($req);$ra=containerFinish($a);$rb=containerFinish($b);assignmentAssert(empty($ra['error'])&&empty($rb['error'])&&$ra['data']['id']===$rb['data']['id'],'Container create retry diverged: '.json_encode([$ra,$rb]));$id=(int)$ra['data']['id'];$revision=$ra['data']['revision'];
assignmentAssert($ra['data']['eta_date']==='2026-10-25'&&$ra['data']['destination']==='Beirut','Creation omitted schedule/destination');
$bad=$base;$bad['notes']='different';assignmentAssert(!empty(containerCall('POST',null,$bad)['error']),'Changed creation retry accepted');
echo "PASS: concurrent creation, payload binding, persisted schedule and destination\n";
foreach(['invalid-day','bad-date','date-array','reversed-dates','country','code-length','missing-key','invalid-capacity'] as $case){
    $input=$base;$input['code']='SG-INVALID-'.bin2hex(random_bytes(4));$input['idempotency_key']='container-invalid-'.bin2hex(random_bytes(6));
    if($case==='invalid-day')$input['eta_date']='2026-02-31';if($case==='bad-date')$input['eta_date']='tomorrow';if($case==='date-array')$input['eta_date']=[];
    if($case==='reversed-dates')$input['eta_date']='2026-09-25';if($case==='country')$input['destination_country']='Unknown destination';if($case==='code-length')$input['code']=str_repeat('C',51);if($case==='missing-key')unset($input['idempotency_key']);if($case==='invalid-capacity')$input['max_cbm']='1e400';
    assignmentAssert(!empty(containerCall('POST',null,$input)['error']),'Invalid creation accepted: '.$case);$s=$pdo->prepare('SELECT COUNT(*) FROM containers WHERE code=?');$s->execute([$input['code']]);assignmentAssert(!(int)$s->fetchColumn(),'Invalid container persisted');echo "PASS: container rejects $case\n";
}
$a=containerStart(['method'=>'PUT','id'=>(string)$id,'body'=>['revision'=>$revision,'notes'=>'First operator schedule']]);$b=containerStart(['method'=>'PUT','id'=>(string)$id,'body'=>['revision'=>$revision,'notes'=>'Second operator schedule']]);$ra=containerFinish($a);$rb=containerFinish($b);assignmentAssert(count(array_filter([$ra,$rb],fn($r)=>!empty($r['error'])))===1,'Concurrent edits silently overwrote');
assignmentAssert(!empty(containerCall('PUT',$id,['notes'=>'Missing version'])['error']),'Missing revision accepted');
$read=containerCall('GET',$id)['data'];$updated=containerCall('PUT',$id,['revision'=>$read['revision'],'code'=>$base['code'].'-A']);assignmentAssert(empty($updated['error']),'Rename failed');$replay=containerCall('POST',null,$base);assignmentAssert((int)($replay['data']['id']??0)===$id,'Create retry after rename duplicated container');
echo "PASS: stale edits rejected and creation retry survives renamed identity\n";
$trigger=false;try{
    $pdo->exec("CREATE TRIGGER qa_container_audit_rollback BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.entity_type='container' AND NEW.action='update' AND NEW.entity_id=$id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected container audit failure'; END IF; END");$trigger=true;
    $before=containerCall('GET',$id)['data'];$failed=containerCall('PUT',$id,['revision'=>$before['revision'],'notes'=>'Rollback check']);$after=containerCall('GET',$id)['data'];assignmentAssert(!empty($failed['error'])&&$before['revision']===$after['revision'],'Audit failure left committed container edit');
}finally{if($trigger)$pdo->exec('DROP TRIGGER qa_container_audit_rollback');}
echo "PASS: container edit and audit rollback together\n";
$trigger=false;try{
    $pdo->exec("CREATE TRIGGER qa_container_create_rollback BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.entity_type='container' AND NEW.action='create' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected container create audit failure'; END IF; END");$trigger=true;
    $attempt=$base;$attempt['code']='SG-ROLLBACK-'.bin2hex(random_bytes(5));$attempt['idempotency_key']='container-rollback-'.bin2hex(random_bytes(6));$failed=containerCall('POST',null,$attempt);$s=$pdo->prepare('SELECT COUNT(*) FROM containers WHERE code=?');$s->execute([$attempt['code']]);assignmentAssert(!empty($failed['error'])&&!(int)$s->fetchColumn(),'Failed creation persisted container');
}finally{if($trigger)$pdo->exec('DROP TRIGGER qa_container_create_rollback');}
echo "PASS: container creation rolls back when audit fails\n";
$f=assignmentFixture($pdo);$container=(int)$f['containers'][0];$other=(int)$f['orders'][1];
$pdo->prepare('INSERT INTO shipment_draft_orders(shipment_draft_id,order_id) VALUES (?,?)')->execute([$f['drafts'][1],$other]);
$pdo->prepare('UPDATE shipment_drafts SET container_id=? WHERE id=?')->execute([$container,$f['drafts'][1]]);$pdo->prepare("UPDATE orders SET currency='RMB' WHERE id=?")->execute([$other]);
$pdo->prepare('UPDATE order_items SET sell_price=10 WHERE order_id IN (?,?,?)')->execute($f['orders']);
$detail=containerCall('GET',$container,[],'orders');assignmentAssert(empty($detail['error']),'Container contents failed');$totals=$detail['data']['totals'];assignmentAssert($totals['amount']===null&&count($totals['amounts_by_currency'])===2,'Container added incompatible currencies');
$before=containerCall('GET',$container)['data'];$countryChange=containerCall('PUT',$container,['revision'=>$before['revision'],'destination_country'=>'Lebanon']);assignmentAssert(!empty($countryChange['error']),'Unspecified order destination silently accepted a country change');
$pdo->prepare('DELETE wri FROM warehouse_receipt_items wri JOIN warehouse_receipts wr ON wr.id=wri.receipt_id WHERE wr.order_id=?')->execute([$f['orders'][0]]);
$unknown=containerCall('GET',$container,[],'orders')['data']['totals'];assignmentAssert($unknown['quantity']===null,'Unknown quantity collapsed to zero');
echo "PASS: mixed currencies, assigned destination guard and unknown physical quantity\n";
foreach([['status'=>'bogus'],['fill'=>'bogus'],['limit'=>'invalid'],['offset'=>-1]] as $query)assignmentAssert(!empty(containerCall('GET',null,[],null,$query)['error']),'Malformed container filter accepted');
$literal=containerCall('GET',null,[],null,['q'=>'%']);assignmentAssert(empty($literal['data']),'Search wildcard exposed unrelated containers');
$page=containerCall('GET',null,[],null,['limit'=>1,'offset'=>1]);assignmentAssert(count($page['data'])===1&&$page['meta']['total']>1,'Container pagination mismatch');
$pdo->prepare("INSERT INTO users(email,password_hash,full_name,is_active) VALUES (?,'unusable','Container permission QA',1)")->execute(['container-qa-'.bin2hex(random_bytes(6)).'@example.invalid']);$reader=(int)$pdo->lastInsertId();
foreach(['GET','PUT','POST'] as $method){$result=containerFinish(containerStart(['method'=>$method,'id'=>$method==='POST'?null:(string)$id,'forbidden'=>$reader]));assignmentAssert(!empty($result['error']),'Roleless direct container handler access');}
echo "PASS: filters, literal search, pagination and direct authorization\n";
if(($argv[1]??'')==='--ui')echo json_encode(['container'=>$id,'code'=>$updated['data']['code']]);
