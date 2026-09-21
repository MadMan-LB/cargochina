<?php
require_once dirname(__DIR__).'/backend/config/database.php';
$pdo=getDb();
function ordersCheck($ok,$message):void{if(!$ok)throw new RuntimeException($message);}
ordersCheck($pdo->query('SELECT DATABASE()')->fetchColumn()==='clms_hardening_20260919','Orders tests require disposable database');
$cfg=require dirname(__DIR__).'/backend/config/config.php';ordersCheck($cfg['notification_channels']===['dashboard'] && empty($cfg['tracking_push_enabled']),'External delivery is forbidden');
if(($argv[1]??'')==='--worker'){
    if(session_status()===PHP_SESSION_NONE)session_start();$_SESSION=['user_id'=>1,'user_roles'=>['SuperAdmin']];
    $req=json_decode($argv[2],true);$handler=require dirname(__DIR__).'/backend/api/handlers/'.$req['resource'].'.php';
    try{$handler($req['method'],$req['id']??null,$req['action']??null,$req['body']??[]);}catch(Throwable $e){jsonError($e->getMessage(),500);}exit;
}
function ordersStart(array $request):array{$pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'--worker',json_encode($request)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);return [$p,$pipes];}
function ordersFinish(array $worker):array{[$p,$pipes]=$worker;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);ordersCheck($code===0&&$err==='',"Order worker failed: $err $out");$result=json_decode($out,true);ordersCheck(is_array($result),'Invalid JSON: '.$out);return $result;}
function ordersRequest(string $resource,string $method,?int $id,array $body):array{return ['resource'=>$resource,'method'=>$method,'id'=>$id===null?null:(string)$id,'body'=>$body];}
function ordersCall(string $resource,string $method,?int $id,array $body):array{return ordersFinish(ordersStart(ordersRequest($resource,$method,$id,$body)));}
$customer=(int)$pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn();$supplier=(int)$pdo->query('SELECT id FROM suppliers ORDER BY id LIMIT 1')->fetchColumn();
$base=['customer_id'=>$customer,'supplier_id'=>$supplier,'expected_ready_date'=>'2026-10-03','currency'=>'USD','items'=>[['description_en'=>'Bamboo serving trays','description_cn'=>'竹制托盘','quantity'=>10,'cartons'=>2,'qty_per_carton'=>5,'unit'=>'pieces','unit_price'=>8.5,'sell_price'=>10,'declared_cbm'=>.2,'declared_weight'=>20]]];
$payload=$base+['idempotency_key'=>'orders-qa-'.bin2hex(random_bytes(8))];
$create=ordersCall('orders','POST',null,$payload);ordersCheck(empty($create['error']),'Valid order rejected: '.json_encode($create));$id=(int)$create['data']['id'];$itemId=(int)$create['data']['items'][0]['id'];
$replay=ordersCall('orders','POST',null,$payload);ordersCheck(!empty($replay['idempotent_replay']) && (int)$replay['data']['id']===$id,'Identical order retry duplicated');
$changed=$payload;$changed['high_alert_notes']='Changed shipment reference';$conflict=ordersCall('orders','POST',null,$changed);ordersCheck(!empty($conflict['error']),'Changed order retry silently accepted');
echo "PASS: order creation and payload-bound retry\n";
$unitPayload=$base+['idempotency_key'=>'quantity-unit-'.bin2hex(random_bytes(8))];$unitPayload['items'][0]['unit']='cartons';$unitResult=ordersCall('orders','POST',null,$unitPayload);ordersCheck(($unitResult['data']['items'][0]['unit']??'')==='pieces','Packed piece quantity mislabeled as cartons');
echo "PASS: packed quantity uses piece units in canonical order projection\n";
foreach(['keyless','empty','negative','nonfinite','cartons-fraction','mismatch','bad-unit','bad-date'] as $case){
    $input=$base+['idempotency_key'=>'orders-invalid-'.bin2hex(random_bytes(6))];
    if($case==='keyless')unset($input['idempotency_key']);
    if($case==='empty')$input['items']=[];
    if($case==='negative')$input['items'][0]['unit_price']=-1;
    if($case==='nonfinite')$input['items'][0]['declared_cbm']='1e400';
    if($case==='cartons-fraction')$input['items'][0]['cartons']=1.5;
    if($case==='mismatch')$input['items'][0]['quantity']=11;
    if($case==='bad-unit')$input['items'][0]['unit']='invalid';
    if($case==='bad-date')$input['expected_ready_date']='2026-02-31';
    $r=ordersCall('orders','POST',null,$input);ordersCheck(!empty($r['error']),"Invalid $case accepted");
    if(isset($input['idempotency_key'])){$s=$pdo->prepare('SELECT COUNT(*) FROM orders WHERE creation_idempotency_key=?');$s->execute([$input['idempotency_key']]);ordersCheck((int)$s->fetchColumn()===0,'Invalid input persisted');}
    echo "PASS: order rejects $case\n";
}
$pdo->prepare("INSERT INTO design_attachments(entity_type,entity_id,file_path,uploaded_by) VALUES ('order_item',?,'uploads/qa-existing-design.pdf',1)")->execute([$itemId]);$design=(int)$pdo->lastInsertId();
$edit=$base+['lock_version'=>0];$edit['items'][0]['existing_item_id']=$itemId;$edit['items'][0]['sell_price']=11;$edit['items'][0]['total_amount']=110;$edit['currency']='RMB';
$edited=ordersCall('orders','PUT',$id,$edit);ordersCheck(empty($edited['error']),'Valid edit rejected: '.json_encode($edited));
ordersCheck((int)$edited['data']['items'][0]['id']===$itemId && $edited['data']['currency']==='RMB','Edit changed item identity or ignored currency');
ordersCheck((int)$pdo->query('SELECT entity_id FROM design_attachments WHERE id='.$design)->fetchColumn()===$itemId,'Edit lost design attachment');
$stale=ordersCall('orders','PUT',$id,$edit);ordersCheck(!empty($stale['error']),'Stale version overwrote order');
unset($edit['lock_version']);$missing=ordersCall('orders','PUT',$id,$edit);ordersCheck(!empty($missing['error']),'Missing version overwrote order');
echo "PASS: stable item identity, attachment, currency and optimistic edit protection\n";
$pdo->prepare("UPDATE orders SET status='Approved' WHERE id=?")->execute([$id]);$edit['lock_version']=1;$locked=ordersCall('orders','PUT',$id,$edit);ordersCheck(!empty($locked['error']),'Approved procurement changed');
echo "PASS: approved procurement is immutable\n";
$same=$base+['idempotency_key'=>'orders-race-'.bin2hex(random_bytes(8))];$req=ordersRequest('orders','POST',null,$same);$a=ordersStart($req);$b=ordersStart($req);$ra=ordersFinish($a);$rb=ordersFinish($b);ordersCheck(empty($ra['error'])&&empty($rb['error'])&&$ra['data']['id']===$rb['data']['id'],'Concurrent creation mismatch: '.json_encode([$ra,$rb]));
echo "PASS: concurrent order creation persists once\n";
$draft=['customer_id'=>$customer,'currency'=>'USD','expected_ready_date'=>'2026-10-03','idempotency_key'=>'draft-qa-'.bin2hex(random_bytes(8)),'supplier_sections'=>[['supplier_id'=>$supplier,'items'=>[['description_cn'=>'竹制托盘','description_en'=>'Bamboo serving trays','cartons'=>2,'pieces_per_carton'=>5,'cbm'=>.1,'weight'=>10,'dimensions_scope'=>'carton','unit_price'=>8.5,'sell_price'=>10,'item_type_code'=>'normal']]]]];
$req=ordersRequest('draft-orders','POST',null,$draft);$a=ordersStart($req);$b=ordersStart($req);$ra=ordersFinish($a);$rb=ordersFinish($b);ordersCheck(empty($ra['error'])&&empty($rb['error'])&&$ra['data']['id']===$rb['data']['id'],'Concurrent draft creation mismatch: '.json_encode([$ra,$rb]));
$draft['supplier_sections'][0]['items'][0]['weight']=11;$changedDraft=ordersCall('draft-orders','POST',null,$draft);ordersCheck(!empty($changedDraft['error']),'Changed draft retry accepted');
echo "PASS: draft builder creation shares retry contract\n";
$draftId=(int)$ra['data']['id'];$rawDraft=$draft;$rawDraft['idempotency_key']='draft-invalid-'.bin2hex(random_bytes(8));$rawDraft['supplier_sections'][0]['items'][0]['cartons']='2abc';
$invalidDraft=ordersCall('draft-orders','POST',null,$rawDraft);ordersCheck(!empty($invalidDraft['error']),'Builder accepted malformed number');
$alternate=ordersCall('orders','PUT',$draftId,$base+['lock_version'=>0]);ordersCheck(!empty($alternate['error']),'Standard endpoint rewrote rich draft builder metadata');
$designDelete=ordersCall('design-attachments','DELETE',$design,[]);ordersCheck(!empty($designDelete['error']),'Approved order design deleted through alternate endpoint');
echo "PASS: builder numeric validation and alternate endpoint protection\n";
$product=(int)$pdo->query('SELECT id FROM products ORDER BY id DESC LIMIT 1')->fetchColumn();
$pdo->prepare("INSERT INTO procurement_drafts(name,supplier_id,status,created_by) VALUES ('Bamboo serving trays replenishment',?,'draft',1)")->execute([$supplier]);$legacy=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO procurement_draft_items(draft_id,product_id,quantity,sort_order) VALUES (?,?,10,0)')->execute([$legacy,$product]);
$conversion=['customer_id'=>$customer,'currency'=>'USD'];$a=ordersStart(['resource'=>'procurement-drafts','method'=>'POST','id'=>(string)$legacy,'action'=>'convert','body'=>$conversion]);$b=ordersStart(['resource'=>'draft-orders','method'=>'POST','id'=>'legacy','action'=>$legacy.'/migrate','body'=>$conversion]);$ra=ordersFinish($a);$rb=ordersFinish($b);
ordersCheck(empty($ra['error'])&&empty($rb['error'])&&$ra['data']['order']['id']===$rb['data']['order']['id'],'Legacy conversion paths diverged: '.json_encode([$ra,$rb]));
$converted=(int)$ra['data']['order']['id'];ordersCheck((int)$pdo->query('SELECT COUNT(*) FROM item_number_references WHERE order_id='.$converted)->fetchColumn()>0,'Legacy conversion bypassed numbering reservation');
echo "PASS: both legacy conversion paths serialize and reserve canonical item numbers\n";
$legacyPayload=['name'=>'Ceramic serving collection','supplier_id'=>$supplier,'idempotency_key'=>'legacy-create-'.bin2hex(random_bytes(8)),'items'=>[['product_id'=>$product,'quantity'=>12]]];
$req=ordersRequest('procurement-drafts','POST',null,$legacyPayload);$a=ordersStart($req);$b=ordersStart($req);$ra=ordersFinish($a);$rb=ordersFinish($b);ordersCheck(empty($ra['error'])&&empty($rb['error'])&&$ra['data']['id']===$rb['data']['id'],'Legacy creation duplicated: '.json_encode([$ra,$rb]));
$legacyId=(int)$ra['data']['id'];$rev=$ra['data']['revision'];$update=['revision'=>$rev,'name'=>'Ceramic serving collection — revised','status'=>'pending_review'];$updated=ordersCall('procurement-drafts','PUT',$legacyId,$update);ordersCheck(empty($updated['error']),'Valid legacy edit failed');$stale=ordersCall('procurement-drafts','PUT',$legacyId,$update);ordersCheck(!empty($stale['error']),'Legacy stale write accepted');
$badState=ordersCall('procurement-drafts','PUT',$legacyId,['revision'=>$updated['data']['revision'],'status'=>'converted']);ordersCheck(!empty($badState['error']),'Legacy status bypassed order conversion');
echo "PASS: legacy creation retry, edit revision and conversion-state protection\n";
$fresh=ordersCall('orders','POST',null,$base+['idempotency_key'=>'edit-race-'.bin2hex(random_bytes(8))]);$raceId=(int)$fresh['data']['id'];$raceItem=(int)$fresh['data']['items'][0]['id'];
$editBody=$base+['lock_version'=>0];$editBody['items'][0]['existing_item_id']=$raceItem;$editBody['high_alert_notes']='First operator update';$a=ordersStart(ordersRequest('orders','PUT',$raceId,$editBody));$editBody['high_alert_notes']='Second operator update';$b=ordersStart(ordersRequest('orders','PUT',$raceId,$editBody));$ra=ordersFinish($a);$rb=ordersFinish($b);ordersCheck(count(array_filter([$ra,$rb],fn($r)=>!empty($r['error'])))===1,'Concurrent edits both succeeded');
echo "PASS: concurrent order edits serialize by required version\n";
$editBody['lock_version']=1;$editBody['items'][0]['sell_price']=13;$editBody['items'][0]['total_amount']=130;$trigger=false;
try{
    $pdo->exec("CREATE TRIGGER qa_order_edit_rollback BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.entity_type='order' AND NEW.action='update' AND NEW.entity_id=$raceId THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected order edit audit failure'; END IF; END");$trigger=true;
    $failed=ordersCall('orders','PUT',$raceId,$editBody);ordersCheck(!empty($failed['error']),'Edit audit fault not reproduced');
    ordersCheck((int)$pdo->query('SELECT lock_version FROM orders WHERE id='.$raceId)->fetchColumn()===1,'Failed edit incremented version');
    ordersCheck((float)$pdo->query('SELECT sell_price FROM order_items WHERE id='.$raceItem)->fetchColumn()===10.0,'Failed edit changed item price');
    ordersCheck((int)$pdo->query('SELECT COUNT(*) FROM item_number_references WHERE order_id='.$raceId)->fetchColumn()===1,'Failed edit lost reservation');
}finally{if($trigger)$pdo->exec('DROP TRIGGER qa_order_edit_rollback');}
echo "PASS: failed order edit rolls back header, item, reservation and audit\n";
$costType=(string)$pdo->query('SELECT code FROM draft_order_cost_types WHERE is_active=1 ORDER BY code LIMIT 1')->fetchColumn();
$cost=['order_id'=>$draftId,'cost_type_code'=>$costType,'description_en'=>'Warehouse handling','description_zh'=>'仓库处理','amount'=>'12.50','currency'=>'USD','base_currency'=>'USD','idempotency_key'=>'cost-qa-'.bin2hex(random_bytes(8))];
$costResult=ordersCall('draft-order-costs','POST',null,$cost);ordersCheck(empty($costResult['error']),'Cost fixture failed: '.json_encode($costResult));$costId=(int)$costResult['data']['id'];$repeat=ordersCall('draft-order-costs','POST',null,$cost);ordersCheck((int)($repeat['data']['id']??0)===$costId,'Cost replay duplicated');$different=$cost;$different['amount']='13.00';ordersCheck(!empty(ordersCall('draft-order-costs','POST',null,$different)['error']),'Cost replay changed payload');
$changedDraft=$draft;$changedDraft['currency']='RMB';$changedDraft['lock_version']=0;$identity=ordersCall('draft-orders','PUT',$draftId,$changedDraft);ordersCheck(!empty($identity['error']),'Order currency changed under linked costs');
ordersCheck(!empty(ordersCall('draft-order-costs','PUT',$costId,$cost)['error']),'Missing cost version was accepted');
$overflow=$cost;$overflow['idempotency_key']='cost-overflow-'.bin2hex(random_bytes(8));$overflow['amount']='9999999999.9999';$overflow['currency']='RMB';$overflow['exchange_rate']='9999999999.99999999';ordersCheck(!empty(ordersCall('draft-order-costs','POST',null,$overflow)['error']),'Converted cost overflow accepted');
echo "PASS: linked financial identity, cost replay/version and converted amount bounds\n";
foreach(['amount'=>[],'currency'=>[],'supplier_id'=>'1bad'] as $field=>$value){$bad=$cost;$bad['idempotency_key']='cost-malformed-'.bin2hex(random_bytes(6));$bad[$field]=$value;ordersCheck(!empty(ordersCall('draft-order-costs','POST',null,$bad)['error']),'Malformed cost '.$field.' accepted');}
$req=ordersRequest('draft-order-costs','DELETE',$costId,[]);$a=ordersStart($req);$b=ordersStart($req);$ra=ordersFinish($a);$rb=ordersFinish($b);ordersCheck(empty($ra['error'])&&empty($rb['error']),'Concurrent cost archive failed');ordersCheck((int)$pdo->query("SELECT COUNT(*) FROM draft_order_cost_history WHERE cost_id=$costId AND action='delete'")->fetchColumn()===1,'Cost archive replay duplicated history');
echo "PASS: cost malformed inputs and concurrent archive idempotency\n";
$otherCustomer=(int)$pdo->query('SELECT id FROM customers WHERE id<>'.$customer.' ORDER BY id LIMIT 1')->fetchColumn();ordersCheck($otherCustomer>0,'Second customer fixture missing');
$key='customer-race-'.bin2hex(random_bytes(8));$first=$base+['idempotency_key'=>$key];$second=$first;$second['customer_id']=$otherCustomer;
$a=ordersStart(ordersRequest('orders','POST',null,$first));$b=ordersStart(ordersRequest('orders','POST',null,$second));$ra=ordersFinish($a);$rb=ordersFinish($b);ordersCheck(count(array_filter([$ra,$rb],fn($r)=>!empty($r['error'])))===1,'Cross-customer key collision not rejected');
$s=$pdo->prepare('SELECT COUNT(*) FROM orders WHERE creation_idempotency_key=?');$s->execute([$key]);ordersCheck((int)$s->fetchColumn()===1,'Cross-customer retry duplicated order');
echo "PASS: cross-customer creation key collision persists at most one order\n";
if(($argv[1]??'')==='--ui')echo json_encode(['standard_order'=>$raceId,'draft_order'=>$draftId,'approved_standard_order'=>$id]);
