<?php
require_once dirname(__DIR__).'/backend/config/database.php';
require_once dirname(__DIR__).'/backend/services/OrderReceivingService.php';
require_once dirname(__DIR__).'/backend/services/ReceivingExcelImportService.php';
require_once dirname(__DIR__).'/backend/services/CargoMetricsService.php';
require_once dirname(__DIR__).'/backend/services/OrderReceiptWorkflowService.php';
$pdo=getDb();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'clms_hardening_20260919') throw new RuntimeException('Requires disposable hardening database');
$config=require dirname(__DIR__).'/backend/config/config.php';
if ($config['notification_channels']!==['dashboard']) throw new RuntimeException('External delivery must be disabled');
function receivingFixture(PDO $pdo,int $count=1):array {
    $customer=(int)$pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn();
    $supplier=(int)$pdo->query('SELECT id FROM suppliers ORDER BY id LIMIT 1')->fetchColumn();
    $user=(int)$pdo->query("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.code='ContainersStaff' ORDER BY u.id DESC LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO orders(customer_id,supplier_id,status,created_by) VALUES (?,?,'Approved',?)")->execute([$customer,$supplier,$user]);$order=(int)$pdo->lastInsertId();$items=[];
    for($i=0;$i<$count;$i++){$pdo->prepare("INSERT INTO order_items(order_id,cartons,qty_per_carton,quantity,unit,declared_cbm,declared_weight,unit_price,total_amount,description_en,shipping_code) VALUES (?,10,10,100,'pieces',1,100,2,200,'Stainless steel utensil sets','RECEIVING-QA')")->execute([$order]);$items[]=(int)$pdo->lastInsertId();}
    return compact('order','items','user');
}
function receivingInput(array $f,int $cartons,string $key):array {
    return ['idempotency_key'=>$key,'condition'=>'good','actual_cartons'=>$cartons,'actual_cbm'=>$cartons/10,'actual_weight'=>$cartons*10,'items'=>[['order_item_id'=>$f['items'][0],'actual_cartons'=>$cartons,'actual_quantity'=>$cartons*10,'actual_cbm'=>$cartons/10,'actual_weight'=>$cartons*10]]];
}
function checkReceiving(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
function rejectReceiving(callable $fn,string $message):void {try{$fn();}catch(OrderReceivingValidationException $e){echo "PASS: $message\n";return;}throw new RuntimeException('Accepted '.$message);}
if(($argv[1]??'')==='--ui') {$a=receivingFixture($pdo);$b=receivingFixture($pdo,2);echo json_encode(['main'=>$a,'separate'=>$b]);exit;}
if(($argv[1]??'')==='--large') {$f=receivingFixture($pdo,81);$pdo->prepare('UPDATE order_items SET cartons=1,qty_per_carton=10,quantity=10,declared_cbm=.01,declared_weight=1 WHERE order_id=?')->execute([$f['order']]);echo json_encode($f);exit;}
if(($argv[1]??'')==='--auth') {
    $pdo->prepare('INSERT INTO users(email,password_hash,full_name,is_active) VALUES (?,?,?,1)')->execute(['receiving-reader-'.bin2hex(random_bytes(4)).'@example.invalid',password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),'Receiving QA reader']);
    session_id('hardening-reader-'.bin2hex(random_bytes(16)));session_start();$_SESSION=['user_id'=>(int)$pdo->lastInsertId(),'user_name'=>'Receiving QA reader','user_roles'=>[]];$session=session_id();session_write_close();
    file_put_contents(dirname(__DIR__).'/artifacts/cargo-demo/qa-reader-session.json',json_encode(['session'=>$session]));echo "Isolated unprivileged QA session prepared\n";exit;
}
if(($argv[1]??'')==='--pagination') {
    $f=receivingFixture($pdo);$svc=new OrderReceivingService();
    for($i=0;$i<51;$i++){$payload=receivingInput($f,1,'receipt-pagination-'.$f['order'].'-'.$i);$payload['actual_cbm']=$payload['items'][0]['actual_cbm']=.01;$payload['actual_weight']=$payload['items'][0]['actual_weight']=1;$payload['items'][0]['actual_quantity']=1;$svc->receive($pdo,$f['order'],$payload,$f['user']);}
    echo json_encode($f);exit;
}
if(($argv[1]??'')==='--worker') {
    $f=json_decode($argv[2],true);try{echo json_encode((new OrderReceivingService())->receive($pdo,$f['order'],receivingInput($f,6,$argv[3]),$f['user']));}catch(OrderReceivingValidationException $e){echo json_encode(['rejected'=>$e->getMessage()]);}exit;
}
if(($argv[1]??'')==='--import-worker') {
    $_SESSION=['user_id'=>(int)$argv[3],'user_roles'=>['ContainersStaff']];
    $handler=require dirname(__DIR__).'/backend/api/handlers/receiving.php';$handler('POST','import','commit',['preview_token'=>$argv[2]]);exit;
}
if(($argv[1]??'')==='--export-worker') {
    $pdo->beginTransaction();register_shutdown_function(static function()use($pdo){if($pdo->inTransaction())$pdo->rollBack();});
    $f=receivingFixture($pdo);$pdo->prepare("UPDATE order_items SET description_en='=2+3' WHERE id=?")->execute([$f['items'][0]]);
    $receipt=(new OrderReceivingService())->receive($pdo,$f['order'],receivingInput($f,3,'receiving-export-check'),$f['user'],false);
    $_SESSION=['user_id'=>$f['user'],'user_roles'=>['ContainersStaff']];$_GET=['format'=>$argv[2]];
    $handler=require dirname(__DIR__).'/backend/api/handlers/receiving.php';$handler('GET','receipts',$receipt['receipt_id'].'/export',[]);exit;
}
$service=new OrderReceivingService();$pdo->beginTransaction();
try {
    $f=receivingFixture($pdo,2);$input=receivingInput($f,3,'receiving-domain-first');$input['fees']=[['label'=>'Pallet handling','amount'=>5,'currency'=>'USD']];
    $noKey=$input;unset($noKey['idempotency_key']);rejectReceiving(fn()=>$service->receive($pdo,$f['order'],$noKey,$f['user'],false),'keyless partial receipt rejected');
    $first=$service->receive($pdo,$f['order'],$input,$f['user'],false);
    checkReceiving($first['status']==='InTransitToWarehouse' && $first['quantity_totals']['remaining']===170.0,'partial completion derived from all item quantities');
    $totals=CargoMetricsService::totals($pdo,[$f['order']])[$f['order']];
    checkReceiving($totals['quantity']===30.0 && $totals['received_quantity']===30.0,'unreceived item contributes no stock quantity');
    $items=$pdo->query('SELECT * FROM order_items WHERE order_id='.$f['order'])->fetchAll();$attached=CargoMetricsService::attachItems($pdo,$items);
    checkReceiving($attached[1]['cargo_quantity']===0.0 && $attached[1]['remaining_quantity']===100.0,'item projection preserves unreceived remaining quantity');
    $replay=$service->receive($pdo,$f['order'],$input,$f['user'],false);checkReceiving(!empty($replay['idempotent_replay']),'identical retry returns one receipt');
    $different=$input;$different['notes']='changed';rejectReceiving(fn()=>$service->receive($pdo,$f['order'],$different,$f['user'],false),'changed-payload retry rejected');
    rejectReceiving(fn()=>$service->receive($pdo,$f['order'],receivingInput($f,8,'receiving-domain-over'),$f['user'],false),'cumulative over-receipt rejected');
    $duplicate=receivingInput($f,3,'receiving-domain-duplicate');$duplicate['items'][]=$duplicate['items'][0];rejectReceiving(fn()=>$service->receive($pdo,$f['order'],$duplicate,$f['user'],false),'duplicate item cannot create stock twice');
    $badPhoto=$input;$badPhoto['idempotency_key']='receiving-domain-photo';$badPhoto['photo_paths']=['../config/config.php'];rejectReceiving(fn()=>$service->receive($pdo,$f['order'],$badPhoto,$f['user'],false),'unsafe photo rejected');
    $raw=['_row'=>2,'order_id'=>$f['order'],'order_item_id'=>$f['items'][0],'actual_cartons'=>8,'actual_quantity'=>80,'actual_cbm'=>.8,'actual_weight'=>80,'condition'=>'partial'];
    checkReceiving(!(new ReceivingExcelImportService())->validateRows($pdo,[$raw])['is_valid'],'import cannot bypass remaining quantity');
    $last=receivingInput($f,7,'receiving-domain-final');$second=receivingInput(['items'=>[$f['items'][1]]],10,'unused')['items'][0];$last['items'][]=$second;$last['actual_cartons']=17;$last['actual_cbm']=1.7;$last['actual_weight']=170;
    $final=$service->receive($pdo,$f['order'],$last,$f['user'],false);checkReceiving($final['status']==='ReadyForConsolidation' && $final['quantity_totals']['remaining']===0.0,'final receipt reconciles cumulative quantities and CBM');
    checkReceiving((int)$pdo->query('SELECT COUNT(*) FROM warehouse_receipts WHERE order_id='.$f['order'])->fetchColumn()===2,'failed writes and replay create no receipts');
    $pdo->prepare("UPDATE orders SET status='CustomerDeclinedAfterAutoConfirm' WHERE id=?")->execute([$f['order']]);
    OrderReceiptWorkflowService::resetDeclinedOrder($pdo,$f['order'],$f['user'],'Receiving reversal regression');
    checkReceiving((float)$pdo->query('SELECT COALESCE(SUM(amount),0) FROM shipment_financial_entries WHERE order_id='.$f['order'])->fetchColumn()===0.0,'receipt reversal also reverses posted fees without deleting financial history');
    $totals=CargoMetricsService::totals($pdo,[$f['order']])[$f['order']];checkReceiving($totals['received_quantity']===0.0 && $totals['receipt_count']===0,'voided receipts removed from active stock');
    rejectReceiving(fn()=>$service->receive($pdo,$f['order'],$input,$f['user'],false),'voided operation cannot replay success');
    $photoFixture=receivingFixture($pdo);$photoInput=receivingInput($photoFixture,10,'receiving-domain-variance');
    $photoInput['actual_cbm']=$photoInput['items'][0]['actual_cbm']=1.2;
    $photoInput['actual_weight']=$photoInput['items'][0]['actual_weight']=150;
    rejectReceiving(fn()=>$service->receive($pdo,$photoFixture['order'],$photoInput,$photoFixture['user'],false),'final cumulative variance requires evidence');
    $photoPath='uploads/receiving-hardening-'.bin2hex(random_bytes(6)).'.png';
    $photoFile=dirname(__DIR__).'/backend/'.$photoPath;
    file_put_contents($photoFile,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    try {
        $photoInput['photo_paths']=[$photoPath];$photoInput['items'][0]['photo_paths']=[$photoPath];
        $variance=$service->receive($pdo,$photoFixture['order'],$photoInput,$photoFixture['user'],false);
        checkReceiving($variance['status']==='Confirmed' && $variance['variance_detected'],'image-backed variance enters customer follow-up state');
        $physical=CargoMetricsService::totals($pdo,[$photoFixture['order']])[$photoFixture['order']];
        checkReceiving($physical['cbm']===1.2 && $physical['weight']===150.0,'physical totals use actual weight and CBM');
        checkReceiving((float)$pdo->query('SELECT declared_weight FROM order_items WHERE id='.$photoFixture['items'][0])->fetchColumn()===100.0,'declared weight remains unchanged');
    } finally {unlink($photoFile);}
    $pdo->exec('SAVEPOINT notification_safety');
    $pdo->exec("UPDATE system_config SET key_value='email,whatsapp' WHERE key_name='NOTIFICATION_CHANNELS'");
    (new NotificationService($pdo))->notifyOrderReceived($f['order'],$f['user'],false,null,true);
    checkReceiving((int)$pdo->query("SELECT COUNT(*) FROM notification_delivery_log WHERE status='pending' AND attempts=0")->fetchColumn()>0,'external notifications queue without sending inside receiving transaction');
    $pdo->exec('ROLLBACK TO SAVEPOINT notification_safety');
    checkReceiving((int)$pdo->query("SELECT COUNT(*) FROM notification_delivery_log WHERE status='pending'")->fetchColumn()===0,'rollback removes notification delivery intent');
} finally {$pdo->rollBack();}
// Real concurrent operators against disposable committed fixtures only.
$f=receivingFixture($pdo);$workers=[];
foreach(['receiving-concurrent-a','receiving-concurrent-b'] as $key){$pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--worker',json_encode($f),$key.'-'.$f['order']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$workers[]=[$process,$pipes];}
$results=[];foreach($workers as [$process,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit)throw new RuntimeException($err);$results[]=json_decode($out,true);}
checkReceiving(count(array_filter($results,fn($r)=>isset($r['receipt_id'])))===1 && count(array_filter($results,fn($r)=>isset($r['rejected'])))===1,'concurrent partial receipts cannot exceed ordered quantity');
checkReceiving((float)$pdo->query('SELECT SUM(actual_quantity) FROM warehouse_receipt_items WHERE order_item_id='.$f['items'][0])->fetchColumn()===60.0,'concurrent stock persists exactly once');
$handler=require dirname(__DIR__).'/backend/api/handlers/receiving.php';
$raw=[['_row'=>2,'customer_id'=>(int)$pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn(),'supplier_id'=>(int)$pdo->query('SELECT id FROM suppliers ORDER BY id LIMIT 1')->fetchColumn(),'description_en'=>'Stackable storage baskets','actual_cartons'=>6,'actual_pieces_per_carton'=>10,'actual_quantity'=>60,'actual_cbm'=>.6,'actual_weight'=>60,'condition'=>'good']];
$preview=(new ReceivingExcelImportService())->validateDirectIntakeRows($pdo,$raw);
checkReceiving($preview['is_valid'],'direct intake uses shared quantity validation');
$preview['raw_rows']=$raw;$preview['template_metadata']=[];
[$importId,$token]=receivingStoreExcelImportPreview($pdo,['name'=>'receiving-domain.csv'],$preview,$f['user']);
$beforeOrders=(int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();$beforeReceipts=(int)$pdo->query('SELECT COUNT(*) FROM warehouse_receipts')->fetchColumn();
$workers=[];for($i=0;$i<2;$i++){$pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--import-worker',$token,(string)$f['user']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$workers[]=[$process,$pipes];}
$results=[];foreach($workers as [$process,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit)throw new RuntimeException($err.$out);$results[]=json_decode($out,true);}
checkReceiving(count(array_filter($results,fn($r)=>!empty($r['data']['receipts'])))===2,'both concurrent import requests return committed result: '.json_encode($results));
checkReceiving((int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn()===$beforeOrders+1 && (int)$pdo->query('SELECT COUNT(*) FROM warehouse_receipts')->fetchColumn()===$beforeReceipts+1,'concurrent direct imports create one order and one receipt');
$firstBatch=receivingFixture($pdo);$secondBatch=receivingFixture($pdo);$batchRows=[];
foreach([$firstBatch,$secondBatch] as $index=>$fixture)$batchRows[]=['_row'=>$index+2,'order_id'=>$fixture['order'],'order_item_id'=>$fixture['items'][0],'actual_cartons'=>10,'actual_quantity'=>100,'actual_cbm'=>1,'actual_weight'=>100,'condition'=>'good'];
$preview=(new ReceivingExcelImportService())->validateRows($pdo,$batchRows);$preview['raw_rows']=$batchRows;
[$failedImport,$failedToken]=receivingStoreExcelImportPreview($pdo,['name'=>'receiving-rollback.csv'],$preview,$f['user']);
// Fault only the second disposable receipt, after the first order was written.
$pdo->exec("CREATE TRIGGER receiving_rollback_guard BEFORE INSERT ON warehouse_receipts FOR EACH ROW BEGIN IF NEW.order_id=".$secondBatch['order']." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Receiving rollback fault injection'; END IF; END");
try {
    $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--import-worker',$failedToken,(string)$f['user']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    checkReceiving($exit!==0 && str_contains($out.$err,'Receiving rollback fault injection'),'second-order failure was reproduced');
    $ids=$firstBatch['order'].','.$secondBatch['order'];
    checkReceiving((int)$pdo->query("SELECT COUNT(*) FROM warehouse_receipts WHERE order_id IN ($ids)")->fetchColumn()===0 && (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE id IN ($ids) AND status='Approved'")->fetchColumn()===2,'batch error rolls back the first receipt and both order states');
} finally {$pdo->exec('DROP TRIGGER receiving_rollback_guard');}
foreach(['csv','xlsx'] as $format) {
    $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--export-worker',$format],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit)throw new RuntimeException($err.$out);
    if($format==='csv') {
        $rows=array_map('str_getcsv',explode("\n",trim($out)));$record=array_values(array_filter($rows,fn($row)=>($row[6]??'')==="'=2+3"))[0]??null;
    } else {
        $file=tempnam(sys_get_temp_dir(),'receiving_export_');file_put_contents($file,$out);
        try {$book=\PhpOffice\PhpSpreadsheet\IOFactory::load($file);$sheet=$book->getActiveSheet();$record=null;foreach($sheet->toArray() as $index=>$row)if(($row[6]??'')==='=2+3'){$record=$row;checkReceiving($sheet->getCell('G'.($index+1))->getDataType()==='s','XLSX formula-like descriptions are literal strings');break;}} finally {unlink($file);}
    }
    checkReceiving($record && (float)$record[8]===30.0 && (float)$record[9]===3.0 && (float)$record[10]===.3 && (float)$record[11]===30.0, strtoupper($format).' export matches selected receipt quantity, cartons, CBM, weight and neutralizes formulas');
}
echo "PASS: receiving domain service regression; committed concurrency fixtures remain only in disposable database\n";
