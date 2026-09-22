<?php
require_once dirname(__DIR__).'/backend/config/database.php';$pdo=getDb();
function exportCheck($ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
exportCheck($pdo->query('SELECT DATABASE()')->fetchColumn()==='clms_hardening_20260919','Export tests require disposable database');
if(($argv[1]??'')==='--worker'){
    session_start();$r=json_decode($argv[2],true);$_SESSION=['user_id'=>$r['user']??1,'user_roles'=>($r['user']??1)===1?['SuperAdmin']:[]];$_GET=$r['query']??[];
    $handler=require dirname(__DIR__).'/backend/api/handlers/'.$r['resource'].'.php';
    try{$handler($r['method']??'GET',$r['id']??null,$r['action']??null,$r['body']??[]);}catch(Throwable $e){jsonError($e->getMessage(),500);}exit;
}
function exportCall(string $resource,?string $id,?string $action,array $query=[],array $body=[],?int $user=null,?string $method=null):string{
    $pipes=[];$r=compact('resource','id','action','query','body');if($body)$r['method']='POST';if($method)$r['method']=$method;if($user!==null)$r['user']=$user;
    $p=proc_open([PHP_BINARY,__FILE__,'--worker',json_encode($r)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);exportCheck($exit===0&&$err==='','Export worker error: '.$err.' '.substr($out,0,180));return $out;
}
function csvRows(string $csv):array{$stream=fopen('php://temp','w+');fwrite($stream,$csv);rewind($stream);$rows=[];while(($row=fgetcsv($stream,0,',','"',''))!==false)$rows[]=$row;fclose($stream);return $rows;}
function readWorkbook(string $bytes){exportCheck(str_starts_with($bytes,'PK'),'Expected XLSX: '.substr($bytes,0,180));$tmp=tempnam(sys_get_temp_dir(),'qa_export_');try{file_put_contents($tmp,$bytes);return \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);}finally{unlink($tmp);}}
require_once dirname(__DIR__).'/vendor/autoload.php';
$tag='EXPORT-'.bin2hex(random_bytes(5));$pdo->prepare('INSERT INTO customers(code,name,created_by) VALUES (?,?,1)')->execute([$tag,'=1+2']);$buyer=(int)$pdo->lastInsertId();$supplier=(int)$pdo->query('SELECT id FROM suppliers ORDER BY id LIMIT 1')->fetchColumn();
$pdo->prepare("INSERT INTO orders(customer_id,supplier_id,status,currency,created_by) VALUES (?,?,'InTransitToWarehouse','USD',1)")->execute([$buyer,$supplier]);$order=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO order_items(order_id,supplier_id,item_no,quantity,unit,cartons,qty_per_carton,declared_cbm,declared_weight,unit_price,total_amount,description_en,dimensions_scope) VALUES (?,?,?,3,'pieces',3,1,.333333,.1001,5,15,'=2+3','piece')")->execute([$order,$supplier,$tag.'-1']);$item=(int)$pdo->lastInsertId();
foreach([[.123456,1.2345],[.000001,.0001]] as [$cbm,$weight]){$pdo->prepare("INSERT INTO warehouse_receipts(order_id,actual_cartons,actual_cbm,actual_weight,receipt_condition,received_by) VALUES (?,1,?,?,'partial',1)")->execute([$order,$cbm,$weight]);$receipt=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO warehouse_receipt_items(receipt_id,order_item_id,actual_cartons,actual_quantity,actual_cbm,actual_weight,receipt_condition) VALUES (?,?,1,1,?,?,'partial')")->execute([$receipt,$item,$cbm,$weight]);}
$csv=csvRows(exportCall('orders','export','list',['format'=>'csv','customer_id'=>$buyer,'limit'=>1,'offset'=>999]));exportCheck(count($csv)===2&&(int)$csv[1][0]===$order,'Filtered CSV obeyed page offset or included unrelated data');exportCheck((float)$csv[1][10]===.123457&&(float)$csv[1][11]===1.2346&&$csv[1][12]==='USD','Filtered CSV lost canonical actual precision/currency');exportCheck(str_starts_with($csv[1][2],"'="),'CSV customer formula executable');
$single=csvRows(exportCall('orders',(string)$order,'export',['format'=>'csv']));$line=array_values(array_filter($single,fn($r)=>($r[4]??null)===$tag.'-1'))[0];exportCheck($line[6]==="'=2+3"&&(float)$line[13]===.333333&&(float)$line[15]===.1001,'Procurement CSV changed declared totals or formula text');
$book=readWorkbook(exportCall('orders',(string)$order,'export',['format'=>'xlsx']));$seen=[];foreach($book->getAllSheets() as $sheet)foreach($sheet->getCellCollection()->getCoordinates() as $coordinate){$cell=$sheet->getCell($coordinate);exportCheck($cell->getDataType()!=='f','Untrusted XLSX formula executed');$seen[]=$cell->getValue();}exportCheck(in_array('=2+3',$seen,true)&&in_array(.1001,$seen,true),'XLSX lost literal description or declared precision');$book->disconnectWorksheets();
$book=readWorkbook(exportCall('orders','export','list',['format'=>'xlsx','customer_id'=>$buyer,'limit'=>1,'offset'=>999]));$rows=$book->getActiveSheet()->toArray(null,false,false,false);$line=array_values(array_filter($rows,fn($r)=>(string)($r[0]??'')===(string)$order))[0];exportCheck((float)$line[11]===.123457&&(float)$line[12]===1.2346&&$line[13]==='USD','XLSX list disagrees with canonical CSV/API');$book->disconnectWorksheets();
echo "PASS: filtered CSV/XLSX ignore page offsets, preserve actual precision/currency and inert literal strings; procurement totals remain declared\n";
$pdo->prepare('UPDATE order_items SET item_number=? WHERE id=?')->execute(['00125',$item]);
foreach ([['orders','export','list',['customer_id'=>$buyer],13,14], ['receiving','export','queue',['customer_id'=>$buyer],11,12], ['receiving','receipts',$receipt.'/export',[],13,14]] as [$resource,$route,$action,$query,$autoColumn,$manualColumn]) {
    $csv=csvRows(exportCall($resource,$route,$action,$query+['format'=>'csv']));
    exportCheck(($csv[0][$autoColumn]??'')==='I.I.N'&&($csv[0][$manualColumn]??'')==='Item Number','CSV identifiers missing: '.$resource.'/'.$action);
    exportCheck(($csv[1][$autoColumn]??'')===$tag.'-1'&&($csv[1][$manualColumn]??'')==='00125','CSV identifier values missing: '.$resource.'/'.$action);
    $book=readWorkbook(exportCall($resource,$route,$action,$query+['format'=>'xlsx']));
    $sheet=$book->getActiveSheet(); $found=false;
    foreach($sheet->getCellCollection()->getCoordinates() as $coordinate) {
        $cell=$sheet->getCell($coordinate);
        if($cell->getValue()==='00125') { $found=true; exportCheck($cell->getDataType()==='s','Leading-zero reference must remain Excel text'); }
    }
    exportCheck($found,'XLSX reference missing: '.$resource.'/'.$action);$book->disconnectWorksheets();
}
echo "PASS: order-list, receiving-queue and receipt CSV/XLSX include both saved identifiers as text\n";
$pdo->prepare('UPDATE order_items SET shared_carton_enabled=1, shared_carton_contents=? WHERE id=?')->execute([json_encode([['item_no'=>'AUTO-SHARED','item_number'=>'00125'],['item_no'=>'AUTO-SHARED','item_number'=>'00125']]),$item]);
foreach ([['receiving','export','queue',['customer_id'=>$buyer],11,12], ['receiving','receipts',$receipt.'/export',[],13,14]] as [$resource,$route,$action,$query,$autoColumn,$manualColumn]) {
    $csv=csvRows(exportCall($resource,$route,$action,$query+['format'=>'csv']));
    exportCheck($csv[1][$autoColumn]===$tag."-1\nAUTO-SHARED\nAUTO-SHARED" && $csv[1][$manualColumn]==="00125\n00125\n00125",'Contained duplicate references lost in '.$action);
}
$pdo->prepare('UPDATE order_items SET shared_carton_enabled=0, shared_carton_contents=NULL WHERE id=?')->execute([$item]);
echo "PASS: queue and receipt reports preserve all repeated shared-carton identifiers without extra cargo rows\n";
$before=$pdo->query('SELECT description_cn,description_en FROM order_items WHERE id='.$item)->fetch(PDO::FETCH_ASSOC);$book=readWorkbook(exportCall('orders','bulk-export',null,[],['ids'=>[$order,$order]]));$book->disconnectWorksheets();exportCheck($pdo->query('SELECT description_cn,description_en FROM order_items WHERE id='.$item)->fetch(PDO::FETCH_ASSOC)===$before,'Download translated or mutated order');
foreach([['ids'=>[$order.'junk']],['ids'=>[$order,4294967295]],['ids'=>[]]] as $body){$bad=json_decode(exportCall('orders','bulk-export',null,[],$body),true);exportCheck(!empty($bad['error']),'Invalid selected export accepted');}
$bad=json_decode(exportCall('orders',(string)$order,'export',['format'=>'pdf']),true);exportCheck(!empty($bad['error']),'Unsupported format silently exported');
$pdo->prepare("INSERT INTO users(email,password_hash,full_name,is_active) VALUES (?,'unusable','Report permission QA',1)")->execute(['export-'.bin2hex(random_bytes(5)).'@example.invalid']);$reader=(int)$pdo->lastInsertId();foreach([['orders',(string)$order,'export'],['orders','export','list'],['draft-orders',(string)$order,'export'],['warehouse-stock','export',null],['containers','1','export'],['dashboard','stats',null]] as [$resource,$id,$action]){exportCheck(!empty(json_decode(exportCall($resource,$id,$action,['format'=>'csv'],[],$reader),true)['error']),'Roleless export allowed: '.$resource);}
echo "PASS: selected exports validate identities, repeat IDs once, preserve saved descriptions and enforce direct authorization\n";
$draft=exportCall('draft-orders',null,null,[],['customer_id'=>$buyer,'currency'=>'USD','idempotency_key'=>'report-draft-'.bin2hex(random_bytes(8)),'supplier_sections'=>[['supplier_id'=>$supplier,'items'=>[['description_en'=>'Bamboo tray carton','cartons'=>3,'pieces_per_carton'=>2,'cbm'=>.111111,'weight'=>.1234,'dimensions_scope'=>'carton','unit_price'=>2,'sell_price'=>3,'item_type_code'=>'normal']]]]]);$draft=json_decode($draft,true);exportCheck(!empty($draft['data']['id']),'Draft export fixture failed: '.json_encode($draft));$draftId=(int)$draft['data']['id'];$rows=csvRows(exportCall('draft-orders',(string)$draftId,'export',['format'=>'csv']));$subtotal=array_values(array_filter($rows,fn($r)=>($r[4]??'')==='Supplier subtotal'))[0];$grand=array_values(array_filter($rows,fn($r)=>($r[4]??'')==='Grand total'))[0];foreach([$subtotal,$grand] as $line)exportCheck(count($line)===30&&(float)$line[21]===18.0&&(float)$line[23]===.333333&&(float)$line[25]===.3702,'Draft CSV subtotal columns or totals incorrect');
echo "PASS: draft CSV subtotal/grand-total columns match detail headers and stored totals\n";
$exportImage='uploads/export-status-'.bin2hex(random_bytes(6)).'.png';$exportImageFile=dirname(__DIR__).'/backend/'.$exportImage;
try {
    $im=imagecreatetruecolor(32,24);imagefilledrectangle($im,0,0,31,23,imagecolorallocate($im,30,100,180));imagepng($im,$exportImageFile);imagedestroy($im);
    $pdo->prepare('UPDATE order_items SET image_paths=?,item_number=? WHERE order_id=?')->execute([json_encode([$exportImage]),'00125',$draftId]);
    foreach(['Draft','Approved','Confirmed','InShipmentDraft','ConsolidatedIntoShipmentDraft','AssignedToContainer','Finalized'] as $status) {
        $pdo->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$status,$draftId]);
        $before=$pdo->query('SELECT item_no,item_number,quantity,declared_cbm,declared_weight FROM order_items WHERE order_id='.$draftId)->fetchAll(PDO::FETCH_ASSOC);
        foreach(['orders','draft-orders'] as $resource){
            $book=readWorkbook(exportCall($resource,(string)$draftId,'export',['format'=>'xlsx']));
            exportCheck(count($book->getActiveSheet()->getDrawingCollection())>0,'Image lost in '.$resource.' '.$status);$book->disconnectWorksheets();
            exportCheck(!empty(csvRows(exportCall($resource,(string)$draftId,'export',['format'=>'csv']))),'CSV failed at '.$status);
        }
        $book=readWorkbook(exportCall('orders','bulk-export',null,[],['ids'=>[$draftId]]));$book->disconnectWorksheets();
        exportCheck($pdo->query('SELECT item_no,item_number,quantity,declared_cbm,declared_weight FROM order_items WHERE order_id='.$draftId)->fetchAll(PDO::FETCH_ASSOC)===$before,'Status export changed saved cargo');
    }
    $pdo->prepare("INSERT INTO user_permission_overrides(user_id,permission_key,is_allowed) VALUES (?,'page:orders',1)")->execute([$reader]);
    $book=readWorkbook(exportCall('draft-orders',(string)$draftId,'export',['format'=>'xlsx'],[],$reader));$book->disconnectWorksheets();
    exportCheck(!empty(json_decode(exportCall('draft-orders',(string)$draftId,null,[],[],$reader),true)['error']),'Download grant also opened the draft builder');
    echo "PASS: image-bearing Draft/Approved/Confirmed/shipment/assigned/finalized XLSX/CSV and bulk downloads; Orders-page export access does not grant builder access\n";
} finally { @unlink($exportImageFile); }
$pdo->prepare("INSERT INTO orders(customer_id,supplier_id,status,confirmation_token,created_by) VALUES (?,?,'Draft',?,1)")->execute([$buyer,$supplier,$tag]);$invalidTokenOrder=(int)$pdo->lastInsertId();$stats=json_decode(exportCall('dashboard','stats',null),true)['data'];$expected=(int)$pdo->query("SELECT COUNT(*) FROM orders WHERE COALESCE(confirmation_token,'')<>'' AND status IN ('Confirmed','AwaitingCustomerConfirmation')")->fetchColumn();exportCheck($stats['customer_feedback_pending']===$expected,'Dashboard counts invalid-state feedback tokens');
echo "PASS: dashboard feedback counts respect canonical review states\n";
$pdo->prepare("INSERT INTO customer_deposits(customer_id,order_id,amount,currency,payment_method,reference_no,created_by) VALUES (?,?,1234.5678,'USD','Bank Transfer',?,1)")->execute([$buyer,$order,$tag]);
foreach(['transactions'=>4,'documents'=>5] as $dataset=>$column){$bytes=exportCall('balances','export',null,['dataset'=>$dataset,'party_type'=>'customer','party_id'=>$buyer,'limit'=>1,'offset'=>999]);$book=readWorkbook($bytes);$rows=$book->getActiveSheet()->toArray(null,false,false,false);exportCheck(count(array_filter($rows,fn($r)=>isset($r[$column])&&is_numeric($r[$column])&&(float)$r[$column]===1234.5678))===1,'Financial export rounded, duplicated or omitted deposit');$book->disconnectWorksheets();}
exportCheck(!empty(json_decode(exportCall('balances','export',null,['dataset'=>'unknown']),true)['error']),'Unknown report dataset fell back silently');
echo "PASS: financial document/transaction exports preserve ledger precision and reject unknown datasets\n";
$pdo->prepare("INSERT INTO orders(customer_id,supplier_id,status,currency,created_by) VALUES (?,?,'AssignedToContainer','USD',1)")->execute([$buyer,$supplier]);$legacy=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO order_items(order_id,quantity,cartons,qty_per_carton,unit,declared_cbm,declared_weight,description_en) VALUES (?,10,1,10,'pieces',.9,20,'Unreconciled legacy cargo')")->execute([$legacy]);
$pdo->prepare("INSERT INTO containers(code,status,max_cbm,max_weight) VALUES (?,'planning',28,28000)")->execute([$tag]);$container=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO shipment_drafts(container_id,status) VALUES (?,'draft')")->execute([$container]);$shipment=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO shipment_draft_orders(shipment_draft_id,order_id) VALUES (?,?)')->execute([$shipment,$legacy]);
foreach(['csv','xlsx'] as $format){$blocked=json_decode(exportCall('containers',(string)$container,'export',['format'=>$format]),true);exportCheck(!empty($blocked['error'])&&str_contains($blocked['message'],'reconciliation'),'Unreconciled packing list exported');}
echo "PASS: container CSV/XLSX block unreconciled item quantities instead of inventing cargo\n";
if(($argv[1]??'')==='--ui')echo json_encode(compact('order','draftId','buyer','tag'));

$pdo->prepare("INSERT INTO products(supplier_id,description_en,description_cn,cbm,weight,pieces_per_carton,dimensions_scope,unit_price) VALUES (?,'Woven basket','Woven basket',.1,10,3,'carton',2)")->execute([$supplier]);$product=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO procurement_drafts(name,supplier_id,status,created_by) VALUES (?,?,'draft',1)")->execute([$tag,$supplier]);$legacyDraft=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO procurement_draft_items(draft_id,product_id,quantity) VALUES (?,?,10)')->execute([$legacyDraft,$product]);
$legacyRows=csvRows(exportCall('procurement-drafts',(string)$legacyDraft,'export',['format'=>'csv']));$line=$legacyRows[6];exportCheck((float)$line[11]===.333333&&(float)$line[12]===33.3333,'Legacy CSV ignored catalog carton packing');
$book=readWorkbook(exportCall('procurement-drafts',(string)$legacyDraft,'export',['format'=>'xlsx']));$values=[];foreach($book->getActiveSheet()->getCellCollection()->getCoordinates() as $c)$values[]=$book->getActiveSheet()->getCell($c)->getValue();exportCheck(in_array(.333333,$values,true)&&in_array(33.3333,$values,true),'Legacy workbook differs from CSV');$book->disconnectWorksheets();
$converted=json_decode(exportCall('procurement-drafts',(string)$legacyDraft,'convert',[],['customer_id'=>$buyer,'currency'=>'USD']),true);exportCheck(empty($converted['error']),'Legacy conversion failed: '.json_encode($converted));$convertedId=$converted['data']['order']['id'];
$convertedItem=$pdo->query('SELECT * FROM order_items WHERE order_id='.(int)$convertedId)->fetch(PDO::FETCH_ASSOC);exportCheck((float)$convertedItem['declared_cbm']===.333333&&(float)$convertedItem['declared_weight']===33.3333&&$convertedItem['dimensions_scope']==='piece','Converted cargo changed measurement basis');
$reopened=json_decode(exportCall('draft-orders',(string)$convertedId,null),true)['data'];$body=['customer_id'=>$buyer,'currency'=>'USD','lock_version'=>$reopened['lock_version'],'supplier_sections'=>$reopened['supplier_sections']];$saved=json_decode(exportCall('draft-orders',(string)$convertedId,null,[],$body,null,'PUT'),true);exportCheck(empty($saved['error']),'Measurement round-trip save failed: '.json_encode($saved));$persisted=$pdo->query('SELECT declared_cbm,declared_weight FROM order_items WHERE order_id='.(int)$convertedId)->fetch(PDO::FETCH_ASSOC);exportCheck((float)$persisted['declared_cbm']===.333333&&(float)$persisted['declared_weight']===33.3333,'Unchanged builder edit drifted declared totals');
$pdo->prepare('UPDATE products SET pieces_per_carton=NULL WHERE id=?')->execute([$product]);$bad=json_decode(exportCall('procurement-drafts',(string)$legacyDraft,'export',['format'=>'csv']),true);exportCheck(!empty($bad['error']),'Ambiguous carton packing exported');
require_once dirname(__DIR__).'/backend/services/OrderWriteService.php';exportCheck(OrderWriteService::productMeasurementMultiplier(['dimensions_scope'=>'carton','pieces_per_carton'=>24],['quantity'=>48,'cartons'=>4,'qty_per_carton'=>12])===2.0,'Catalog sync used order packing instead of catalog packing');
echo "PASS: legacy carton CSV/XLSX, conversion and edit preserve exact totals; missing packing rejected; catalog sync uses its own basis\n";
