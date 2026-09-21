<?php
require_once dirname(__DIR__).'/backend/config/database.php';$pdo=getDb();
function raceCheck($ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
raceCheck($pdo->query('SELECT DATABASE()')->fetchColumn()==='clms_hardening_20260919','Disposable database required');
if(($argv[1]??'')==='--worker'){
 session_start();$r=json_decode($argv[2],true);$_SESSION=['user_id'=>$r['user']??1,'user_roles'=>['SuperAdmin']];$_GET=$r['query']??[];
 if($r['resource']==='arrival-scheduler'){
  require_once dirname(__DIR__).'/backend/services/ContainerArrivalNotificationService.php';
  $notifications=new NotificationService($pdo);$config=new ReflectionProperty($notifications,'config');$config->setAccessible(true);$config->setValue($notifications,['notification_channels'=>['dashboard']]);
  try{echo json_encode(['scheduled'=>ContainerArrivalNotificationService::notifyDue($pdo,$notifications,(int)$r['id'],[7,3,1,0],'2026-09-19')]);}catch(Throwable $e){echo json_encode(['error'=>$e->getMessage()]);}exit;
 }
 $h=require dirname(__DIR__).'/backend/api/handlers/'.$r['resource'].'.php';try{$h($r['method']??'GET',$r['id']??null,$r['action']??null,$r['body']??[]);}catch(Throwable $e){jsonError($e->getMessage(),500);}exit;
}
function raceStart(array $r):array{$pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'--worker',json_encode($r)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);return [$p,$pipes];}
function raceFinish(array $w):array{[$p,$pipes]=$w;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);raceCheck($exit===0&&$err==='','Worker failed: '.$err.' '.$out);$r=json_decode($out,true);raceCheck(is_array($r),'Invalid worker result: '.$out);return $r;}
function raceCall(array $r):array{return raceFinish(raceStart($r));}
$tag='CONCURRENCY-'.bin2hex(random_bytes(5));$pdo->prepare('INSERT INTO suppliers(code,name) VALUES (?,?)')->execute([$tag,'Coastal supplier QA']);$supplier=(int)$pdo->lastInsertId();
$buyer=(int)$pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn();$pdo->prepare("INSERT INTO orders(customer_id,status,created_by) VALUES (?,'Draft',1)")->execute([$buyer]);$order=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO order_items(order_id,quantity,shared_carton_contents) VALUES (?,10,?)")->execute([$order,json_encode([['supplier_id'=>$supplier,'quantity'=>10]])]);
$payment=['resource'=>'suppliers','method'=>'POST','id'=>(string)$supplier,'action'=>'payments','body'=>['amount'=>'90.1250','invoice_amount'=>'100.2500','currency'=>'USD','payment_channel'=>'Bank Transfer','marked_full_payment'=>true,'payment_type'=>'full','order_id'=>$order,'idempotency_key'=>$tag.'-payment']];
$a=raceStart($payment);$b=raceStart($payment);$ra=raceFinish($a);$rb=raceFinish($b);raceCheck(empty($ra['error'])&&empty($rb['error'])&&$ra['data']['id']===$rb['data']['id'],'Payment replay failed '.json_encode([$ra,$rb]));
raceCheck($ra['data']['settlement_delta']==='10.1250','Settlement precision mismatch');
$bad=$payment;$bad['body']['amount']='91';raceCheck(!empty(raceCall($bad)['error']),'Changed payload replay accepted');
foreach([['amount'=>[]],['amount'=>'100000000'],['invoice_amount'=>-1],['payment_type'=>'wrong'],['marked_full_payment'=>'false'],['payment_account_qr_path'=>'../private'],['order_id'=>'1bad']] as $change){$bad=$payment;$bad['body']=array_merge($bad['body'],$change,['idempotency_key'=>$tag.bin2hex(random_bytes(4))]);raceCheck(!empty(raceCall($bad)['error']),'Malformed payment accepted');}
$ledger=['resource'=>'balances','method'=>'POST','id'=>'transactions','body'=>['party_type'=>'supplier','party_id'=>$supplier,'transaction_type'=>'payment_sent','direction'=>'reduce_balance','amount'=>'20.0001','currency'=>'USD','order_id'=>$order,'transaction_date'=>'2026-09-19','idempotency_key'=>$tag.'-ledger']];
$a=raceStart($ledger);$b=raceStart($ledger);$ra=raceFinish($a);$rb=raceFinish($b);raceCheck(empty($ra['error'])&&empty($rb['error'])&&$ra['data']['id']===$rb['data']['id'],'Linked supplier payment replay failed '.json_encode([$ra,$rb]));
$history=raceCall(['resource'=>'balances','id'=>'transactions','query'=>['party_type'=>'supplier','party_id'=>$supplier]]);raceCheck(count($history['data']??[])===2,'Linked supplier payment counted twice');
$s=$pdo->prepare('SELECT COUNT(*),SUM(amount),SUM(settlement_delta) FROM supplier_payments WHERE supplier_id=?');$s->execute([$supplier]);$before=$s->fetch(PDO::FETCH_NUM);raceCheck((int)$before[0]===2&&$before[1]==='110.1251'&&$before[2]==='10.1250','Supplier totals mismatch');
$trigger=false;try{$pdo->exec("CREATE TRIGGER qa_supplier_race_rollback BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.entity_type='supplier_payment' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected payment failure'; END IF; END");$trigger=true;$bad=$payment;$bad['body']['idempotency_key']=$tag.'-rollback';raceCheck(!empty(raceCall($bad)['error']),'Audit failure ignored');$s->execute([$supplier]);raceCheck($s->fetch(PDO::FETCH_NUM)===$before,'Payment rollback leaked rows');}finally{if($trigger)$pdo->exec('DROP TRIGGER qa_supplier_race_rollback');}
echo "PASS: supplier payment retries, shared-carton ownership, exact settlement, both ledger paths, validation and rollback\n";
$pdo->prepare("INSERT INTO users(email,password_hash,full_name,is_active) VALUES (?,'unusable','Preferences QA',1)")->execute([$tag.'@example.invalid']);$user=(int)$pdo->lastInsertId();
$read=['resource'=>'notification-preferences','user'=>$user];$a=raceStart($read);$b=raceStart($read);$ra=raceFinish($a);$rb=raceFinish($b);raceCheck($ra===$rb&&count($ra['data'])===15,'Concurrent default reads diverged');raceCheck((int)$pdo->query('SELECT COUNT(*) FROM user_notification_preferences WHERE user_id='.$user)->fetchColumn()===0,'Preference GET wrote data');
$write=$read+['method'=>'PUT','body'=>['revision'=>$ra['revision'],'preferences'=>[['channel'=>'email','event_type'=>'order_received','enabled'=>false]]]];
$a=raceStart($write);$b=raceStart($write);$ra=raceFinish($a);$rb=raceFinish($b);raceCheck(empty($ra['error'])&&$ra===$rb,'Identical preference retry failed');
$bad=$write;$bad['body']['preferences'][0]['enabled']=true;raceCheck(!empty(raceCall($bad)['error']),'Stale preference overwrite accepted');
$bad=$write;$bad['body']['revision']=$ra['revision'];$bad['body']['preferences'][0]['enabled']='false';raceCheck(!empty(raceCall($bad)['error']),'String false coerced to true');
$bad['body']['preferences']=$write['body']['preferences'];$bad['body']['preferences'][]=$bad['body']['preferences'][0];raceCheck(!empty(raceCall($bad)['error']),'Duplicate preferences accepted');
raceCheck(raceCall($read)===$ra,'Preference failure changed stored state');
$trigger=false;try{$pdo->exec("CREATE TRIGGER qa_preference_race_rollback BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.entity_type='notification_preferences' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected preference failure'; END IF; END");$trigger=true;$bad=$write;$bad['body']['revision']=$ra['revision'];$bad['body']['preferences'][0]['enabled']=true;raceCheck(!empty(raceCall($bad)['error']),'Preference audit failure ignored');raceCheck(raceCall($read)===$ra,'Preference rollback failed');}finally{if($trigger)$pdo->exec('DROP TRIGGER qa_preference_race_rollback');}
echo "PASS: read-only defaults, duplicate preference saves, stale edits, strict flags, duplicate rejection and rollback\n";
$pdo->prepare("INSERT INTO expense_categories(code,name,category_type,is_active) VALUES (?,?,'warehouse',1)")->execute([$tag,'Warehouse QA handling']);$category=(int)$pdo->lastInsertId();
$expense=['resource'=>'expenses','method'=>'POST','body'=>['category_id'=>$category,'amount'=>'18.1234','currency'=>'USD','expense_date'=>'2026-09-19','payee'=>$tag,'order_id'=>$order,'supplier_id'=>$supplier,'idempotency_key'=>$tag.'-expense']];
$a=raceStart($expense);$b=raceStart($expense);$ra=raceFinish($a);$rb=raceFinish($b);raceCheck(empty($ra['error'])&&empty($rb['error'])&&$ra['data']['id']===$rb['data']['id'],'Expense concurrent retry failed '.json_encode([$ra,$rb]));$expenseId=$ra['data']['id'];$expenseRevision=$ra['data']['revision'];
raceCheck($ra['data']['amount']==='18.1234','Expense precision changed');
$edit=['resource'=>'expenses','method'=>'PUT','id'=>(string)$expenseId,'body'=>['revision'=>$expenseRevision,'amount'=>'19.1234']];$a=raceStart($edit);$edit['body']['amount']='20.1234';$b=raceStart($edit);$ra=raceFinish($a);$rb=raceFinish($b);raceCheck(count(array_filter([$ra,$rb],fn($r)=>!empty($r['error'])))===1,'Expense competing edits both committed');
$saved=raceCall(['resource'=>'expenses','id'=>(string)$expenseId])['data'];
foreach([['amount'=>-1],['currency'=>'INVALID'],['expense_date'=>'2026-02-30'],['order_id'=>'1bad'],['notes'=>[]]] as $change){$bad=$edit;$bad['body']=$change+['revision'=>$saved['revision']];raceCheck(!empty(raceCall($bad)['error']),'Invalid expense accepted');}
$listed=raceCall(['resource'=>'expenses','query'=>['q'=>$tag]]);raceCheck(count($listed['data'])===1&&$listed['summary'][0]['total']===$saved['amount'],'Expense filtered summary mismatch');
$trigger=false;try{$pdo->exec("CREATE TRIGGER qa_expense_race_rollback BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.entity_type='expense' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected expense failure'; END IF; END");$trigger=true;$bad=$edit;$bad['body']=['revision'=>$saved['revision'],'amount'=>'21'];raceCheck(!empty(raceCall($bad)['error']),'Expense audit failure ignored');raceCheck(raceCall(['resource'=>'expenses','id'=>(string)$expenseId])['data']['revision']===$saved['revision'],'Expense rollback failed');}finally{if($trigger)$pdo->exec('DROP TRIGGER qa_expense_race_rollback');}
$delete=['resource'=>'expenses','method'=>'DELETE','id'=>(string)$expenseId,'body'=>['revision'=>$saved['revision']]];$a=raceStart($delete);$b=raceStart($delete);$ra=raceFinish($a);$rb=raceFinish($b);raceCheck(!empty($ra['data']['deleted'])&&!empty($rb['data']['deleted']),'Concurrent expense delete failed');
echo "PASS: expense retry, exact decimals, linked identity, competing edits, filtered summaries, validation, audit rollback and repeated delete\n";
$pdo->prepare("INSERT INTO containers(code,max_cbm,max_weight,status,eta_date) VALUES (?,33,25000,'on_route','2026-09-19')")->execute([$tag.'-arrival']);$arrival=(int)$pdo->lastInsertId();
$request=['resource'=>'arrival-scheduler','id'=>$arrival];$a=raceStart($request);$b=raceStart($request);$ra=raceFinish($a);$rb=raceFinish($b);
raceCheck(empty($ra['error']) && empty($rb['error']) && (int)($ra['scheduled']??false)+(int)($rb['scheduled']??false)===1,'Concurrent arrival day notifications duplicated or failed');
$count=(int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE target_type='container' AND target_id=$arrival")->fetchColumn();
$admins=(int)$pdo->query("SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE u.is_active=1 AND r.code IN ('ChinaAdmin','LebanonAdmin','SuperAdmin')")->fetchColumn();
raceCheck($count===$admins&&$count>0,'Arrival notifications missing or duplicated per recipient');
raceCheck((int)$pdo->query("SELECT COUNT(*) FROM container_arrival_notifications WHERE container_id=$arrival AND days_before=0")->fetchColumn()===1,'Arrival day zero was skipped');
raceCheck(raceCall($request)['scheduled']===false,'Scheduler retry duplicated committed notifications');
$pdo->prepare("INSERT INTO containers(code,max_cbm,max_weight,status,eta_date) VALUES (?,33,25000,'on_route','2026-09-20')")->execute([$tag.'-arrival-fault']);$arrivalFault=(int)$pdo->lastInsertId();
$pdo->exec("CREATE TRIGGER qa_arrival_rollback BEFORE INSERT ON notifications FOR EACH ROW BEGIN IF NEW.target_type='container' AND NEW.target_id=$arrivalFault THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Arrival rollback fault'; END IF; END");
try{
 $bad=raceCall(['resource'=>'arrival-scheduler','id'=>$arrivalFault]);raceCheck(str_contains($bad['error']??'','Arrival rollback fault'),'Arrival fault not reproduced');
 raceCheck((int)$pdo->query("SELECT COUNT(*) FROM container_arrival_notifications WHERE container_id=$arrivalFault")->fetchColumn()===0,'Failed arrival retained dedup marker');
 raceCheck((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE target_type='container' AND target_id=$arrivalFault")->fetchColumn()===0,'Failed arrival leaked notification');
}finally{$pdo->exec('DROP TRIGGER qa_arrival_rollback');}
raceCheck(raceCall(['resource'=>'arrival-scheduler','id'=>$arrivalFault])['scheduled']===true,'Retry after arrival rollback did not recover');
$pdo->prepare("INSERT INTO containers(code,max_cbm,max_weight,status,eta_date) VALUES (?,33,25000,'arrived','2026-09-22')")->execute([$tag.'-arrival-closed']);$arrived=(int)$pdo->lastInsertId();
raceCheck(raceCall(['resource'=>'arrival-scheduler','id'=>$arrived])['scheduled']===false,'Already arrived container notified');
echo "PASS: arrival-day zero, concurrent scheduler, retry, recipient counts, finalized arrival exclusion and atomic delivery-intent rollback\n";
echo json_encode(['supplier'=>$supplier,'order'=>$order,'user'=>$user]),"\n";
