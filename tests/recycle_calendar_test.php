<?php
require_once dirname(__DIR__).'/backend/config/database.php';
$pdo=getDb();
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='clms_hardening_20260919')throw new RuntimeException('Disposable database required');
if(($argv[1]??'')==='--worker'){
    session_start();$r=json_decode($argv[2],true);$_SESSION=['user_id'=>$r['actor']??1,'user_roles'=>['SuperAdmin']];$_GET=$r['query']??[];
    require_once dirname(__DIR__).'/backend/api/helpers.php';
    if(!empty($r['recent'])){require_once dirname(__DIR__).'/backend/services/SessionPolicyService.php';$u=$pdo->query('SELECT * FROM users WHERE id='.(int)$_SESSION['user_id'])->fetch();SessionPolicyService::establish($_SESSION,(int)$u['session_version'],hash('sha256',$u['password_hash']));}
    $handler=require dirname(__DIR__).'/backend/api/handlers/'.$r['resource'].'.php';
    try{$handler($r['method']??'GET',isset($r['id'])?(string)$r['id']:null,$r['action']??null,$r['body']??[]);}catch(Throwable $e){jsonError($e->getMessage(),$e instanceof DomainException?($e->getCode()?:409):500);}exit;
}
function rcStart(array $r):array{$pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'--worker',json_encode($r)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);return [$p,$pipes];}
function rcFinish(array $w):array{[$p,$pipes]=$w;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);if($code||$err)throw new RuntimeException($out.$err);$j=json_decode($out,true);if(!is_array($j))throw new RuntimeException($out);return $j;}
function rcCall(array $r):array{return rcFinish(rcStart($r));}
$count=0;function rcCheck(bool $v,string $message):void{global $count;if(!$v)throw new RuntimeException($message);$count++;}
function rcMigration(PDO $p):void{
    $sql=preg_replace('/^\s*--.*$/m','',file_get_contents(dirname(__DIR__).'/backend/migrations/087_recycle_bin.sql'));
    foreach(array_filter(array_map('trim',explode(';',$sql))) as $q){$s=$p->query($q);if($s){do{$s->fetchAll();}while($s->nextRowset());$s->closeCursor();}}
}
rcMigration($pdo);rcMigration($pdo);
if(($argv[1]??'')==='--migrate'){echo "Migration 087 applied twice safely\n";exit;}
require_once dirname(__DIR__).'/backend/services/RecycleBinService.php';
require_once dirname(__DIR__).'/backend/services/ContainerCapacityService.php';
require_once dirname(__DIR__).'/backend/services/CargoCalendarService.php';
require_once dirname(__DIR__).'/backend/api/helpers.php';
require_once __DIR__.'/support/legacy_no_json_pdo.php';
$customer=(int)$pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn();
$supplier=(int)$pdo->query('SELECT id FROM suppliers ORDER BY id LIMIT 1')->fetchColumn();
$pdo->prepare("INSERT INTO procurement_drafts(name,supplier_id,created_by) VALUES ('Autumn household replenishment',?,1)")->execute([$supplier]);$pd=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO procurement_draft_items(draft_id,quantity,notes) VALUES (?,12,'Bamboo serving trays')")->execute([$pd]);
$get=rcCall(['resource'=>'procurement-drafts','id'=>$pd]);rcCheck(isset($get['data']['revision']),'Procurement detail available');
$delete=['resource'=>'procurement-drafts','method'=>'DELETE','id'=>$pd,'body'=>['revision'=>$get['data']['revision'],'delete_reason'=>'Supplier schedule changed']];
rcCheck(empty(rcCall($delete)['error']),'Procurement delete succeeds');
rcCheck(!empty(rcCall($delete)['error']),'Duplicate deletion fails safely');
rcCheck(!empty(rcCall(['resource'=>'procurement-drafts','id'=>$pd])['error']),'Deleted detail excluded');
rcCheck(!empty(rcCall(['resource'=>'procurement-drafts','id'=>$pd,'action'=>'export'])['error']),'Deleted export excluded');
rcCheck(!empty(rcCall(['resource'=>'procurement-drafts','id'=>$pd,'method'=>'PUT','body'=>['revision'=>$get['data']['revision'],'name'=>'Changed']])['error']),'Deleted update rejected');
$list=rcCall(['resource'=>'procurement-drafts']);rcCheck(!in_array($pd,array_column($list['data'],'id')),'Deleted normal list excludes record');
$bin=rcCall(['resource'=>'recycle-bin','query'=>['q'=>'Autumn household','type'=>'procurement_draft','deleted_by'=>'1','from'=>date('Y-m-d'),'to'=>date('Y-m-d'),'limit'=>'1']]);
rcCheck(($bin['meta']['total']??0)===1&&$bin['data'][0]['delete_reason']==='Supplier schedule changed','Recycle filters and actor/reason metadata');
rcCheck(empty($bin['data'][0]['can_purge']),'Recent or undated draft must not offer permanent deletion');
$version=$bin['data'][0]['version'];$restore=['resource'=>'recycle-bin','method'=>'POST','id'=>$pd,'action'=>'restore','body'=>['type'=>'procurement_draft','version'=>$version]];
rcCheck(!empty(rcCall(array_replace($restore,['actor'=>4]))['error']),'Ordinary employee cannot restore');
rcCheck(!empty(rcCall(array_replace($restore,['body'=>['type'=>'procurement_draft','version'=>'stale']]))['error']),'Restore conflict rejected');
$a=rcStart($restore);$b=rcStart($restore);$ra=rcFinish($a);$rb=rcFinish($b);rcCheck(empty($ra['error'])!==empty($rb['error']),'Concurrent restore applies exactly once');
$saved=rcCall(['resource'=>'procurement-drafts','id'=>$pd]);rcCheck(count($saved['data']['items'])===1,'Restored procurement retains items');
rcCheck(!empty(rcCall($delete)['error']),'Old delete request cannot delete a restored generation');
$delete['body']['revision']=$saved['data']['revision'];rcCheck(empty(rcCall($delete)['error']),'Second generation delete');
rcCheck(!empty(rcCall($restore)['error']),'Old restore token cannot recover a later deletion');
$bin=rcCall(['resource'=>'recycle-bin','query'=>['q'=>'Autumn household']]);$version=$bin['data'][0]['version'];
rcCheck(!empty(rcCall(['resource'=>'recycle-bin','method'=>'POST','id'=>$pd,'action'=>'purge','recent'=>true,'body'=>['type'=>'procurement_draft','version'=>$version,'confirmation'=>"DELETE procurement_draft $pd"]])['error']),'Ten-year cargo retention blocks purge');
$pdo->prepare("INSERT INTO containers(code,max_cbm,max_weight,status,expected_ship_date,eta_date,actual_departure_date,actual_arrival_date) VALUES (?,28,28000,'planning','2026-09-25','2026-09-29','2026-09-26','2026-09-30')")->execute(['RC-CARGO-'.bin2hex(random_bytes(3))]);$container=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO orders(customer_id,status,created_by,created_at,expected_ready_date) VALUES (?,'AssignedToContainer',1,'2026-09-01 10:00:00','2026-09-10')")->execute([$customer]);$order=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO order_items(order_id,quantity,unit,declared_cbm,declared_weight) VALUES (?,10,'pieces',2,100)")->execute([$order]);$item=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouse_receipts(order_id,actual_cartons,actual_cbm,actual_weight,received_by,received_at) VALUES (?,10,2,100,1,'2026-09-11 11:00:00')")->execute([$order]);$receipt=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO warehouse_receipt_items(receipt_id,order_item_id,actual_quantity,actual_cartons,actual_cbm,actual_weight) VALUES (?,?,10,10,2,100)')->execute([$receipt,$item]);
$pdo->prepare("INSERT INTO shipment_drafts(container_id,status,booking_number) VALUES (?,'draft','Autumn household cargo')")->execute([$container]);$ship=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO shipment_draft_orders(shipment_draft_id,order_id) VALUES (?,?)')->execute([$ship,$order]);
foreach([['order',$order,'approve','2026-09-02 09:00:00',[]],['order',$order,'receive','2026-09-11 11:00:00',['receipt_id'=>$receipt,'quantity_totals'=>['ordered'=>10,'remaining'=>0]]],['shipment_draft',$ship,'assign_orders','2026-09-12 09:00:00',['container_id'=>$container,'order_ids'=>[$order]]]] as [$entity,$id,$action,$date,$data])$pdo->prepare('INSERT INTO audit_log(entity_type,entity_id,action,created_at,new_value,user_id) VALUES (?,?,?,?,?,1)')->execute([$entity,$id,$action,$date,json_encode($data)]);
$range=['from'=>'2026-09-01','to'=>'2026-10-01'];
$events=rcCall(['resource'=>'calendar','query'=>$range]);rcCheck(empty($events['error']),'Calendar range returns canonical events: '.json_encode($events));
$types=array_column($events['data'],'event_type');foreach(['created','expected_receipt','approved','received','fully_received','assigned','planned_departure','eta','departed','arrived'] as $type)rcCheck(in_array($type,$types),"Canonical event $type");
$dates=array_column($events['data'],'date');$sorted=$dates;sort($sorted);rcCheck($dates===$sorted,'Calendar chronology is ordered by recorded dates');
rcCheck(!in_array('loaded',$types),'No fabricated physical load milestone');
$filtered=rcCall(['resource'=>'calendar','query'=>$range+['order'=>(string)$order,'event_type'=>'received']]);rcCheck(count($filtered['data'])===1&&$filtered['data'][0]['date']==='2026-09-11 11:00:00'&&str_contains($filtered['data'][0]['link'],'order_id='.$order),'Receipt timestamp, filter and record link');
rcCheck(empty(rcCall(['resource'=>'calendar','query'=>$range+['customer'=>'%_no_matching_customer']])['data']),'Literal search and customer filter');
rcCheck(!empty(rcCall(['resource'=>'calendar','query'=>['from'=>'2026-01-01','to'=>'2026-10-01']])['error']),'Oversized date range rejected');
rcCheck(!empty(rcCall(['resource'=>'calendar','query'=>['from'=>'2026-02-30','to'=>'2026-03-03']])['error']),'Invalid dates rejected');
$s=$pdo->prepare('SELECT * FROM containers WHERE id=?');$s->execute([$container]);$containerRow=$s->fetch();
$pdo->beginTransaction();$load=ContainerCapacityService::check($pdo,$containerRow);$pdo->rollBack();rcCheck($load['used_cbm']===2.0,'Container has cargo before draft deletion');
$shipGet=rcCall(['resource'=>'shipment-drafts','id'=>$ship]);$shipDelete=['resource'=>'shipment-drafts','method'=>'DELETE','id'=>$ship,'body'=>['deletion_revision'=>$shipGet['data']['deletion_revision'],'delete_reason'=>'Re-plan consolidation']];
rcCheck(empty(rcCall($shipDelete)['error']),'Shipment deletion succeeds with current cargo revision');
rcCheck($pdo->query('SELECT status FROM orders WHERE id='.$order)->fetchColumn()==='ReadyForConsolidation','Cargo released to eligible state');
$pdo->beginTransaction();$load=ContainerCapacityService::check($pdo,$containerRow);$pdo->rollBack();rcCheck($load['used_cbm']===0.0&&$load['order_count']===0,'Deleted draft contributes no capacity load');
rcCheck((int)$pdo->query('SELECT COUNT(*) FROM warehouse_receipts WHERE order_id='.$order)->fetchColumn()===1,'Receipt stock preserved');
rcCheck(!empty(rcCall(['resource'=>'shipment-drafts','id'=>$ship])['error']),'Deleted shipment detail unavailable');
rcCheck(!empty(rcCall(['resource'=>'shipment-drafts','method'=>'POST','id'=>$ship,'action'=>'finalize'])['error']),'Deleted draft cannot finalize');
$bin=rcCall(['resource'=>'recycle-bin','query'=>['type'=>'shipment_draft','q'=>'Autumn household cargo']]);rcCheck(count($bin['data'])===1,'Shipment present in bin');
$firstPage=rcCall(['resource'=>'recycle-bin','query'=>['limit'=>'1','offset'=>'0']]);$secondPage=rcCall(['resource'=>'recycle-bin','query'=>['limit'=>'1','offset'=>'1']]);
rcCheck($firstPage['meta']['total']===2&&count($secondPage['data'])===1&&$firstPage['data'][0]['record_type']!==$secondPage['data'][0]['record_type'],'Bin pagination includes both types without duplicate rows');
$restoreShip=['resource'=>'recycle-bin','method'=>'POST','id'=>$ship,'action'=>'restore','body'=>['type'=>'shipment_draft','version'=>$bin['data'][0]['version']]];
rcCheck(empty(rcCall($restoreShip)['error']),'Shipment restored');
$shipGet=rcCall(['resource'=>'shipment-drafts','id'=>$ship]);rcCheck($shipGet['data']['container_id']===null&&$shipGet['data']['order_ids']===[],'Restore does not steal cargo or restore reservations');
rcCheck(!empty(rcCall($shipDelete)['error']),'Stale shipment delete after restore rejected');
$pdo->prepare("UPDATE shipment_drafts SET status='finalized' WHERE id=?")->execute([$ship]);
rcCheck(!empty(rcCall(array_replace($shipDelete,['body'=>['deletion_revision'=>$shipGet['data']['deletion_revision']]]))['error']),'Finalized shipment protected');
$audit=$pdo->query("SELECT action FROM audit_log WHERE entity_type='procurement_draft' AND entity_id=$pd")->fetchAll(PDO::FETCH_COLUMN);rcCheck(in_array('deleted',$audit)&&in_array('restored',$audit),'Transactional audit history retained');
$f=CargoCalendarService::filters($range);rcCheck(CargoCalendarService::events(new LegacyNoJsonPDO($pdo),$f,false,false)===[],'Page access alone reveals no records');
$start=microtime(true);$legacy=CargoCalendarService::events(new LegacyNoJsonPDO($pdo),$f,true,true);$ms=round((microtime(true)-$start)*1000,2);rcCheck(count($legacy)>0,'No modern SQL required');
// Later shipment milestone is sourced from its audit, not from today's update time.
$pdo->prepare('UPDATE shipment_drafts SET container_id=? WHERE id=?')->execute([$container,$ship]);
$pdo->prepare('INSERT INTO shipment_draft_orders(shipment_draft_id,order_id) VALUES (?,?)')->execute([$ship,$order]);
$pdo->prepare("UPDATE orders SET status='Finalized' WHERE id=?")->execute([$order]);
$pdo->prepare("INSERT INTO audit_log(entity_type,entity_id,action,created_at,new_value,user_id) VALUES ('shipment_draft',?,'finalize','2026-09-24 14:00:00',?,1)")->execute([$ship,json_encode(['status'=>'finalized','order_ids'=>[$order]])]);
$finalEvents=rcCall(['resource'=>'calendar','query'=>$range+['event_type'=>'finalized','order'=>(string)$order]]);
rcCheck(count($finalEvents['data'])===1&&$finalEvents['data'][0]['date']==='2026-09-24 14:00:00','Finalization event uses canonical audit time');
$orderDepartures=rcCall(['resource'=>'calendar','query'=>$range+['event_type'=>'departed','order'=>(string)$order]]);
rcCheck(count($orderDepartures['data'])===1&&str_contains($orderDepartures['data'][0]['link'],'container_id='.$container),'Order timeline includes genuine container departure and correct link');
rcCheck(CargoCalendarService::events(new LegacyNoJsonPDO($pdo),CargoCalendarService::filters($range+['order'=>(string)$order]),false,true)===[],'Container-only permission cannot expose filtered order cargo');
$pdo->prepare('UPDATE warehouse_receipts SET voided_at=NOW() WHERE id=?')->execute([$receipt]);
rcCheck(rcCall(['resource'=>'calendar','query'=>$range+['event_type'=>'received','order'=>(string)$order]])['data']===[],'Voided receipt excluded from calendar');
rcCheck(rcCall(['resource'=>'calendar','query'=>$range+['event_type'=>'fully_received','order'=>(string)$order]])['data']===[],'Voided receipt completion excluded');
$pdo->prepare('UPDATE warehouse_receipts SET voided_at=NULL WHERE id=?')->execute([$receipt]);
$pdo->prepare("INSERT INTO users(email,password_hash,full_name,is_active) VALUES (?,'unusable','Calendar permission fixture',1)")->execute(['calendar-'.bin2hex(random_bytes(5)).'@example.invalid']);$reader=(int)$pdo->lastInsertId();
rcCheck(!empty(rcCall(['resource'=>'calendar','actor'=>$reader,'query'=>$range])['error']),'No calendar page permission rejected');
$pdo->prepare("INSERT INTO user_permission_overrides(user_id,permission_key,is_allowed) VALUES (?,'page:calendar',1)")->execute([$reader]);
$restricted=rcCall(['resource'=>'calendar','actor'=>$reader,'query'=>$range]);rcCheck(empty($restricted['error'])&&$restricted['data']===[],'Calendar-only grant leaks no underlying data');
rcCheck(!empty(rcCall(['resource'=>'recycle-bin','actor'=>$reader])['error']),'Bin read restricted');
$pdo->prepare("INSERT INTO user_roles(user_id,role_id) SELECT ?,id FROM roles WHERE code='ChinaAdmin'")->execute([$reader]);
foreach(['page:recycle_bin','page:procurement_drafts'] as $key)$pdo->prepare('INSERT INTO user_permission_overrides(user_id,permission_key,is_allowed) VALUES (?,?,1)')->execute([$reader,$key]);
$adminBin=rcCall(['resource'=>'recycle-bin','actor'=>$reader,'query'=>['type'=>'procurement_draft']]);rcCheck(empty($adminBin['error'])&&!$adminBin['data'][0]['can_restore'],'Bin view alone does not permit restoration');
rcCheck(!empty(rcCall(array_replace($restore,['actor'=>$reader]))['error']),'Administrator needs separate recovery grant');
// Retention, holds, confirmation and audited purge on an old, child-free disposable draft.
$pdo->prepare("INSERT INTO procurement_drafts(name,status,created_at,deleted_at,deleted_by) VALUES ('Expired empty draft','cancelled','2010-01-01',NOW(),1)")->execute();$oldId=(int)$pdo->lastInsertId();
$oldRow=$pdo->query('SELECT * FROM procurement_drafts WHERE id='.$oldId)->fetch(PDO::FETCH_ASSOC);
$purge=['resource'=>'recycle-bin','method'=>'POST','id'=>$oldId,'action'=>'purge','recent'=>true,'body'=>['type'=>'procurement_draft','version'=>RecycleBinService::version($oldRow),'confirmation'=>"DELETE procurement_draft $oldId"]];
rcCheck(!empty(rcCall(array_replace($purge,['recent'=>false]))['error']),'Purge requires recent authentication');
$pdo->prepare("INSERT INTO retention_holds(scope_type,scope_reference,reason,approval_reference) VALUES ('cargo',?,'Disposable hold test','QA')")->execute(['procurement_draft:'.$oldId]);$hold=(int)$pdo->lastInsertId();
rcCheck(!empty(rcCall($purge)['error']),'Hold overrides expiry');
$pdo->prepare('UPDATE retention_holds SET released_at=NOW(),release_approval_reference=? WHERE id=?')->execute(['QA isolated release',$hold]);
rcCheck(empty(rcCall($purge)['error']),'Old child-free record can be permanently removed with stronger authorization');
rcCheck(!$pdo->query('SELECT id FROM procurement_drafts WHERE id='.$oldId)->fetchColumn(),'Permanent removal persisted');
rcCheck((int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE entity_type='procurement_draft' AND entity_id=$oldId AND action='permanently_deleted'")->fetchColumn()===1,'Permanent deletion audit survives removal');
// Fail after the restoration UPDATE: the audit failure must roll back the restoration.
class RecycleAuditFailurePDO extends LegacyNoJsonPDO {
    public function __construct(private PDO $inner){parent::__construct($inner);}
    public function beginTransaction():bool{return $this->inner->beginTransaction();}
    public function commit():bool{return $this->inner->commit();}
    public function rollBack():bool{return $this->inner->rollBack();}
    public function prepare(string $q,array $options=[]):PDOStatement|false{if(str_starts_with($q,'INSERT INTO audit_log'))throw new RuntimeException('Injected audit failure');return parent::prepare($q,$options);}
}
if(session_status()!==PHP_SESSION_ACTIVE)session_start();$_SESSION=['user_id'=>1,'user_roles'=>['SuperAdmin']];
$row=$pdo->query('SELECT * FROM procurement_drafts WHERE id='.$pd)->fetch(PDO::FETCH_ASSOC);
try{RecycleBinService::restore(new RecycleAuditFailurePDO($pdo),'procurement_draft',$pd,1,RecycleBinService::version($row));throw new LogicException('Expected audit failure');}catch(RuntimeException $e){rcCheck($e->getMessage()==='Injected audit failure','Audit failure reproduced');}
rcCheck((bool)$pdo->query('SELECT deleted_at FROM procurement_drafts WHERE id='.$pd)->fetchColumn(),'Failed audit rolls back restore');
file_put_contents(__DIR__.'/support/recycle-calendar-fixtures.json',json_encode(['order'=>$order,'container'=>$container,'shipment'=>$ship,'procurement'=>$pd]));
echo "PASS $count recycle/calendar checks; canonical calendar query {$ms}ms\n";
