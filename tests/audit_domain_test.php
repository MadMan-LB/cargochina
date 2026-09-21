<?php
require_once dirname(__DIR__).'/backend/config/database.php';$pdo=getDb();
function auditCheck($ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
auditCheck($pdo->query('SELECT DATABASE()')->fetchColumn()==='clms_hardening_20260919','Disposable database required');
if(($argv[1]??'')==='--worker'){
 session_start();$r=json_decode($argv[2],true);$_SESSION=['user_id'=>$r['user']??1,'user_roles'=>['SuperAdmin']];$_GET=$r['query']??[];
 $h=require dirname(__DIR__).'/backend/api/handlers/'.$r['resource'].'.php';try{$h($r['method']??'GET',$r['id']??null,$r['action']??null,$r['body']??[]);}catch(Throwable $e){jsonError($e->getMessage(),500);}exit;
}
function auditStart(array $r):array{$pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'--worker',json_encode($r)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);return [$p,$pipes];}
function auditFinish(array $w):array{[$p,$pipes]=$w;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);auditCheck($exit===0&&$err==='','Worker failed: '.$err.' '.$out);$r=json_decode($out,true);auditCheck(is_array($r),'Invalid worker result: '.$out);return $r;}
function auditCall(array $r):array{return auditFinish(auditStart($r));}
$tag='AUDIT-'.bin2hex(random_bytes(5));
function latestAudit(PDO $p,string $entity,int $id):array{$s=$p->prepare('SELECT * FROM audit_log WHERE entity_type=? AND entity_id=? ORDER BY id DESC LIMIT 1');$s->execute([$entity,$id]);return $s->fetch(PDO::FETCH_ASSOC)?:[];}
$product=['idempotency_key'=>$tag.'-product','force_create'=>1,'description_en'=>'Linen runner '.$tag,'description_cn'=>'QA','cbm'=>.1,'weight'=>12,'pieces_per_carton'=>10,'dimensions_scope'=>'carton','item_type_code'=>'normal','item_type_confirmed'=>1];
$r=auditCall(['resource'=>'products','method'=>'POST','body'=>$product]);auditCheck(!empty($r['data']['id']),'Product creation failed '.json_encode($r));$productId=(int)$r['data']['id'];$a=latestAudit($pdo,'product',$productId);auditCheck($a['action']==='create'&&(int)$a['user_id']===1,'Product creation audit missing');
$product['revision']=auditCall(['resource'=>'products','id'=>(string)$productId])['data']['revision'];
$r=auditCall(['resource'=>'products','method'=>'PUT','id'=>(string)$productId,'body'=>array_merge($product,['weight'=>13])]);auditCheck(empty($r['error']),'Product update failed');$a=latestAudit($pdo,'product',$productId);auditCheck((float)json_decode($a['old_value'],true)['weight']===12.0&&(float)json_decode($a['new_value'],true)['weight']===13.0,'Product before/after audit incorrect');
$supplier=['idempotency_key'=>$tag.'-supplier','name'=>'Harbour packaging '.$tag,'code'=>$tag];$r=auditCall(['resource'=>'suppliers','method'=>'POST','body'=>$supplier]);auditCheck(!empty($r['data']['id']),'Supplier creation failed '.json_encode($r));$supplierId=(int)$r['data']['id'];auditCheck(latestAudit($pdo,'supplier',$supplierId)['action']==='create','Supplier creation audit missing');
$supplier['revision']=auditCall(['resource'=>'suppliers','id'=>(string)$supplierId])['data']['revision'];
$r=auditCall(['resource'=>'suppliers','method'=>'PUT','id'=>(string)$supplierId,'body'=>$supplier+['notes'=>'Quality review complete']]);auditCheck(empty($r['error'])&&latestAudit($pdo,'supplier',$supplierId)['action']==='update','Supplier edit audit missing');
foreach(['product'=>$productId,'supplier'=>$supplierId] as $entity=>$id){$trigger=false;try{$pdo->exec("CREATE TRIGGER qa_audit_atomic BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.entity_type='$entity' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected history failure'; END IF; END");$trigger=true;$body=$entity==='product'?array_merge($product,['weight'=>99]):array_merge($supplier,['notes'=>'Must roll back']);$body['revision']=auditCall(['resource'=>$entity.'s','id'=>(string)$id])['data']['revision'];$r=auditCall(['resource'=>$entity.'s','method'=>'PUT','id'=>(string)$id,'body'=>$body]);auditCheck(!empty($r['error']),'Missing audit did not fail mutation');$saved=auditCall(['resource'=>$entity.'s','id'=>(string)$id])['data'];auditCheck($entity==='product'?(float)$saved['weight']===13.0:$saved['notes']==='Quality review complete','Mutation escaped failed history');}finally{if($trigger)$pdo->exec('DROP TRIGGER qa_audit_atomic');}}
echo "PASS: product/supplier actor and before/after records; failed audit rolls back business data\n";
foreach (['products'=>$productId,'suppliers'=>$supplierId] as $resource=>$id) {
    $base=$resource==='products'?$product:$supplier;
    unset($base['revision']);
    $base['idempotency_key']=$tag.'-concurrent-'.$resource;
    if($resource==='products')$base['description_en']='Cotton napkins '.$tag;
    else{$base['name']='Pearl textile supply '.$tag;$base['code']=$tag.'-replay';}
    $a=auditStart(['resource'=>$resource,'method'=>'POST','body'=>$base]);
    $b=auditStart(['resource'=>$resource,'method'=>'POST','body'=>$base]);
    $ra=auditFinish($a);$rb=auditFinish($b);
    auditCheck(!empty($ra['data']['id'])&&$ra['data']['id']===$rb['data']['id'],'Catalog create replay duplicated records');
    $changed=$base;$changed[$resource==='products'?'weight':'notes']='123';
    auditCheck(!empty(auditCall(['resource'=>$resource,'method'=>'POST','body'=>$changed])['error']),'Catalog retry accepted a different payload');
    $base=$resource==='products'?array_merge($product,['weight'=>14]):array_merge($supplier,['notes'=>'Concurrent verified update']);
    $base['revision']=auditCall(['resource'=>$resource,'id'=>(string)$id])['data']['revision'];
    $a=auditStart(['resource'=>$resource,'method'=>'PUT','id'=>(string)$id,'body'=>$base]);
    $b=auditStart(['resource'=>$resource,'method'=>'PUT','id'=>(string)$id,'body'=>$base]);
    $ra=auditFinish($a);$rb=auditFinish($b);
    auditCheck((int)empty($ra['error'])+(int)empty($rb['error'])===1,'Stale concurrent catalog edit was not rejected: '.json_encode([$resource,$ra,$rb]));
    auditCheck(!empty(auditCall(['resource'=>$resource,'method'=>'DELETE','id'=>(string)$id,'body'=>['revision'=>$base['revision']]])['error']),'Stale catalog delete succeeded');
    $fresh=auditCall(['resource'=>$resource,'id'=>(string)$id])['data'];
    auditCheck(!empty($fresh['revision'])&&$fresh['revision']!==$base['revision'],'Catalog revision did not persist');
}
echo "PASS: catalog simultaneous create replay, conflicting payload, stale edits/deletes and persisted revisions\n";
$configBefore=$pdo->query('SELECT key_name,key_value FROM system_config')->fetchAll(PDO::FETCH_ASSOC);
try{
 $configRevision=auditCall(['resource'=>'config'])['revision'];$r=auditCall(['resource'=>'config','method'=>'PUT','body'=>['revision'=>$configRevision,'config'=>['UPLOAD_ALLOWED_TYPES'=>['png','jpg'],'VARIANCE_THRESHOLD_PERCENT'=>'12','WHATSAPP_API_TOKEN'=>'qa-secret-'.$tag]]]);auditCheck(empty($r['error'])&&$r['data']['UPLOAD_ALLOWED_TYPES']==='png,jpg'&&$r['data']['VARIANCE_THRESHOLD_PERCENT']==='12','Configuration allowlist corrupted');auditCheck(!str_contains(json_encode($r),'qa-secret-'),'Config response leaked secret');$a=latestAudit($pdo,'system_config',0);auditCheck(!str_contains(json_encode($a),'qa-secret-'),'Config audit leaked secret');
 $trigger=false;try{$pdo->exec("CREATE TRIGGER qa_config_audit_atomic BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.entity_type='system_config' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected config failure'; END IF; END");$trigger=true;$configRevision=auditCall(['resource'=>'config'])['revision'];$r=auditCall(['resource'=>'config','method'=>'PUT','body'=>['revision'=>$configRevision,'config'=>['VARIANCE_THRESHOLD_PERCENT'=>'17']]]);auditCheck(!empty($r['error']),'Config audit failure ignored');auditCheck($pdo->query("SELECT key_value FROM system_config WHERE key_name='VARIANCE_THRESHOLD_PERCENT'")->fetchColumn()==='12','Config rollback failed');}finally{if($trigger)$pdo->exec('DROP TRIGGER qa_config_audit_atomic');}
}finally{$pdo->beginTransaction();$pdo->exec('DELETE FROM system_config');$s=$pdo->prepare('INSERT INTO system_config(key_name,key_value) VALUES (?,?)');foreach($configBefore as $r)$s->execute([$r['key_name'],$r['key_value']]);$pdo->commit();}
$pdo->prepare("INSERT INTO users(email,password_hash,full_name,is_active) VALUES (?,'never-expose-this-hash','Audit role QA',1)")->execute([$tag.'@example.invalid']);$user=(int)$pdo->lastInsertId();$r=auditCall(['resource'=>'users','method'=>'PUT','id'=>(string)$user,'body'=>['revision'=>auditCall(['resource'=>'users','id'=>(string)$user])['data']['revision'],'roles'=>['ChinaEmployee'],'department_ids'=>[],'is_active'=>1]]);auditCheck(empty($r['error']),'User edit failed '.json_encode($r));$a=latestAudit($pdo,'user',$user);auditCheck($a['action']==='update'&&!str_contains(json_encode($a),'never-expose'),'User audit missing or exposed password hash');auditCheck(count(json_decode($a['new_value'],true)['roles'])===1,'User roles missing from audit');
$pdo->prepare("INSERT INTO audit_log(entity_type,entity_id,action,new_value,user_id,created_at) VALUES ('qa_history',?,'create',?,1,'2026-09-19 12:00:00')")->execute([$user,json_encode(['nested'=>['confirmation_token'=>'private-token','password'=>'private-password'],'safe'=>'visible'])]);
for($i=0;$i<5;$i++)$pdo->prepare("INSERT INTO audit_log(entity_type,entity_id,action,new_value,user_id,created_at) VALUES ('qa_history',?,'create','{}',1,'2026-09-19 12:00:00')")->execute([$user]);
$ids=[];for($offset=0;$offset<6;$offset+=2){$r=auditCall(['resource'=>'audit-log','query'=>['entity_type'=>'qa_history','entity_id'=>$user,'limit'=>2,'offset'=>$offset]]);auditCheck(empty($r['error']),'Audit list failed');auditCheck(!str_contains(json_encode($r),'private-token')&&!str_contains(json_encode($r),'private-password'),'Historical audit secrets exposed');$ids=[...$ids,...array_column($r['data'],'id')];}auditCheck(count($ids)===6&&count(array_unique($ids))===6,'Audit timestamp ties duplicated pages');
foreach([['limit'=>'bad'],['entity_id'=>'1oops'],['date_from'=>'2026-09-20','date_to'=>'2026-09-19']] as $query)auditCheck(!empty(auditCall(['resource'=>'audit-log','query'=>$query])['error']),'Invalid audit filter accepted');
echo "PASS: configuration transaction/redaction, user role history, historical secret redaction and stable audit filters/pages\n";
require_once dirname(__DIR__).'/backend/services/TrainingDataResetService.php';$previous=getenv('APP_ENV');putenv('APP_ENV=production');try{$blocked=false;try{(new TrainingDataResetService($pdo))->reset(['logs'],1);}catch(InvalidArgumentException $e){$blocked=str_contains($e->getMessage(),'disabled');}auditCheck($blocked,'Production history reset not blocked');}finally{putenv('APP_ENV='.$previous);}
echo "PASS: production training reset rejected before mutation\n";
require_once dirname(__DIR__).'/backend/services/SettingsWriteService.php';
auditCheck(SettingsWriteService::arrivalDays('7, 3,1,0,3')===[7,3,1,0],'Arrival schedule normalization lost days');
foreach(['','7,,1','-1','366','1.5','3oops',[],true] as $bad){$rejected=false;try{SettingsWriteService::arrivalDays($bad);}catch(InvalidArgumentException $e){$rejected=true;}auditCheck($rejected,'Invalid arrival schedule accepted');}
foreach(['config'=>'system_config','business-settings'=>'business_settings'] as $resource=>$table){
    $before=SettingsWriteService::values($pdo,$table);
    try{
        $loaded=auditCall(['resource'=>$resource]);
        $updates=$resource==='config'?['VARIANCE_THRESHOLD_PERCENT'=>(string)(((int)($before['VARIANCE_THRESHOLD_PERCENT']??0)+1)%100)]:['ARRIVAL_NOTIFY_DAYS'=>($before['ARRIVAL_NOTIFY_DAYS']??'')==='17,11,4'?'18,12,5':'17,11,4'];
        $request=['resource'=>$resource,'method'=>'PUT','body'=>['config'=>$updates,'revision'=>$loaded['revision']]];
        $a=auditStart($request);$request['body']['config']=$resource==='config'?['VARIANCE_THRESHOLD_PERCENT'=>(string)(((int)($before['VARIANCE_THRESHOLD_PERCENT']??0)+2)%100)]:['ARRIVAL_NOTIFY_DAYS'=>'19,9'];$b=auditStart($request);
        $ra=auditFinish($a);$rb=auditFinish($b);
        auditCheck((int)empty($ra['error'])+(int)empty($rb['error'])===1,'Concurrent settings overwrite succeeded: '.json_encode([$resource,$ra,$rb]));
        auditCheck(auditCall(['resource'=>$resource])['revision']!==$loaded['revision'],'Settings revision did not persist');
    }finally{$pdo->beginTransaction();$pdo->exec("DELETE FROM $table");$s=$pdo->prepare("INSERT INTO $table(key_name,key_value) VALUES (?,?)");foreach($before as $key=>$value)$s->execute([$key,$value]);$pdo->commit();}
}
echo "PASS: multi-day arrival schedule, invalid values and concurrent settings revisions; fixtures restored\n";
foreach([null,'permission-overrides','customer-visibility'] as $action){
    $loaded=auditCall(['resource'=>'users','id'=>(string)$user,'action'=>$action])['data'];
    $revision=$loaded['revision'];
    $first=$action==='permission-overrides'?['permissions'=>['page:orders']]:($action==='customer-visibility'?['mode'=>'all']:['roles'=>['WarehouseStaff']]);
    $second=$action==='permission-overrides'?['permissions'=>['page:products']]:($action==='customer-visibility'?['mode'=>'selected','allowed_creator_user_ids'=>[1]]:['roles'=>['ChinaAdmin']]);
    $request=['resource'=>'users','id'=>(string)$user,'action'=>$action,'method'=>'PUT','body'=>$first+['revision'=>$revision]];
    $a=auditStart($request);$request['body']=$second+['revision'=>$revision];$b=auditStart($request);
    $ra=auditFinish($a);$rb=auditFinish($b);
    auditCheck((int)empty($ra['error'])+(int)empty($rb['error'])===1,'User settings accepted a stale concurrent edit: '.json_encode([$action,$ra,$rb]));
}
$sidebar=auditCall(['resource'=>'users','id'=>'sidebar-access'])['data'];
$configBefore=SettingsWriteService::values($pdo,'system_config');
try{
    $request=['resource'=>'users','id'=>'sidebar-access','method'=>'PUT','body'=>['settings'=>['ChinaEmployee'=>['orders']],'revision'=>$sidebar['revision']]];
    $a=auditStart($request);$request['body']['settings']=['ChinaEmployee'=>['products']];$b=auditStart($request);$ra=auditFinish($a);$rb=auditFinish($b);
    auditCheck((int)empty($ra['error'])+(int)empty($rb['error'])===1,'Sidebar permissions lost a concurrent update');
}finally{$pdo->beginTransaction();$pdo->exec('DELETE FROM system_config');$s=$pdo->prepare('INSERT INTO system_config(key_name,key_value) VALUES (?,?)');foreach($configBefore as $key=>$value)$s->execute([$key,$value]);$pdo->commit();}
$base=auditCall(['resource'=>'users','id'=>(string)$user])['data'];
foreach([['roles'=>['UnknownRole']],['department_ids'=>['1oops']],['is_active'=>'inactive']] as $bad)auditCheck(!empty(auditCall(['resource'=>'users','id'=>(string)$user,'method'=>'PUT','body'=>$bad+['revision'=>$base['revision']]])['error']),'Invalid user role/department/state accepted');
$adminRole=(int)$pdo->query("SELECT id FROM roles WHERE code='SuperAdmin'")->fetchColumn();$s=$pdo->prepare('SELECT user_id FROM user_roles WHERE role_id=? AND user_id<>1');$s->execute([$adminRole]);$otherAdmins=$s->fetchAll(PDO::FETCH_COLUMN);
try{
    $pdo->prepare('DELETE FROM user_roles WHERE role_id=? AND user_id<>1')->execute([$adminRole]);
    $admin=auditCall(['resource'=>'users','id'=>'1'])['data'];
    foreach([['roles'=>[]],['is_active'=>0]] as $bad)auditCheck(!empty(auditCall(['resource'=>'users','id'=>'1','method'=>'PUT','body'=>$bad+['revision'=>$admin['revision']]])['error']),'Last active administrator was removed');
}finally{$s=$pdo->prepare('INSERT IGNORE INTO user_roles(user_id,role_id) VALUES (?,?)');foreach($otherAdmins as $uid)$s->execute([$uid,$adminRole]);}
echo "PASS: roles, overrides, customer visibility and sidebar stale writes; strict user values and last-administrator protection\n";
