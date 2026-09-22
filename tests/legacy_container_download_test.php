<?php
require_once __DIR__.'/support/legacy_no_json_pdo.php';
require_once dirname(__DIR__).'/backend/config/database.php';
require_once dirname(__DIR__).'/backend/services/UploadAccessService.php';
require_once dirname(__DIR__).'/backend/services/ContainerWriteService.php';
require_once dirname(__DIR__).'/backend/services/OrderExcelService.php';
$pdo=getDb();
if ($pdo->query('SELECT DATABASE()')->fetchColumn()!=='clms_hardening_20260919') throw new RuntimeException('Requires disposable schema');
$legacy=new LegacyNoJsonPDO($pdo);$checks=0;
function legacyDownloadCheck(bool $ok,string $label): void { global $checks; if(!$ok)throw new RuntimeException($label);$checks++; }
if(session_status()===PHP_SESSION_NONE)session_start();$_SESSION=['user_id'=>1,'user_roles'=>['SuperAdmin']];
$tag='legacy-'.bin2hex(random_bytes(6));$key=$tag.'_request';
$insert=$pdo->prepare("INSERT INTO audit_log(entity_type,entity_id,action,new_value,user_id) VALUES ('container',?,'create',?,1)");
$insert->execute([123,'{malformed '.$key]);
$insert->execute([124,json_encode(['idempotency_key'=>$key.'-other'])]);
$insert->execute([125,json_encode(['nested'=>['idempotency_key'=>$key]])]);
$insert->execute([126,json_encode(['idempotency_key'=>$key,'request_hash'=>'hash'])]);
legacyDownloadCheck((int)ContainerWriteService::findCreateRequest($legacy,$key)['entity_id']===126,'Exact top-level retry key, not substring/nested/malformed audit');
legacyDownloadCheck(ContainerWriteService::findCreateRequest($legacy,$tag.'-missing')===null,'Unknown create request');
$customer=(int)$pdo->query('SELECT id FROM customers LIMIT 1')->fetchColumn();
$private='uploads/'.$tag.'-证件.png';
$pdo->exec("INSERT INTO audit_log(entity_type,entity_id,action,new_value,user_id) VALUES ('design_attachment',0,'create','{broken',1)");
$pdo->prepare("INSERT INTO audit_log(entity_type,entity_id,action,new_value,user_id) VALUES ('design_attachment',0,'create',?,1)")->execute([json_encode(['entity_type'=>'customer','entity_id'=>$customer,'file_path'=>$private])]);
foreach([$pdo,$legacy] as $connection) legacyDownloadCheck(array_map('intval',UploadAccessService::privateCustomerReferences($connection,$private))===[$customer],'Private deleted document remains protected, including escaped Unicode');
$path='uploads/'.$tag.'.png';$file=dirname(__DIR__).'/backend/'.$path;$out=tempnam(sys_get_temp_dir(),'clms_legacy_xlsx_');
try {
    $im=imagecreatetruecolor(32,24);imagefilledrectangle($im,0,0,31,23,imagecolorallocate($im,30,100,180));imagepng($im,$file);imagedestroy($im);
    $pdo->prepare("INSERT INTO orders(customer_id,status,created_by) VALUES (?,'Draft',1)")->execute([$customer]);$orderId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO order_items(order_id,item_no,item_number,quantity,cartons,qty_per_carton,declared_cbm,declared_weight,image_paths,shared_carton_contents) VALUES (?,\'AUTO-1\',\'00125\',2,1,2,.2,4,?,?)')->execute([$orderId,json_encode([$path]),'{invalid']);$itemId=(int)$pdo->lastInsertId();
    $operational=new ReflectionMethod(UploadAccessService::class,'hasOperationalReference');
    foreach([$pdo,$legacy] as $connection) {
        legacyDownloadCheck($operational->invoke(null,$connection,$path),'Registered operational image remains accessible');
        legacyDownloadCheck(!$operational->invoke(null,$connection,'uploads/unreferenced-'.$tag.'.png'),'No unrelated operational image access');
    }
    $pdo->prepare('UPDATE order_items SET image_paths=NULL,shared_carton_contents=? WHERE id=?')->execute([json_encode([['item_number'=>$path,'photo_paths'=>[$path]]]),$itemId]);
    legacyDownloadCheck($operational->invoke(null,$legacy,$path),'Contained photo path recognized');
    $pdo->prepare('UPDATE order_items SET shared_carton_contents=? WHERE id=?')->execute([json_encode([['item_number'=>$path]]),$itemId]);
    legacyDownloadCheck(!$operational->invoke(null,$legacy,$path),'Item text is not a photo reference');
    $pdo->prepare('UPDATE order_items SET image_paths=?,shared_carton_contents=NULL WHERE id=?')->execute([json_encode([$path]),$itemId]);
    foreach(['Draft','Approved','Confirmed','InShipmentDraft','ConsolidatedIntoShipmentDraft','AssignedToContainer','Finalized'] as $status) {
        $pdo->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$status,$orderId]);
        $order=['id'=>$orderId,'status'=>$status,'customer_name'=>'Local export fixture','currency'=>'USD'];
        $items=$pdo->query('SELECT * FROM order_items WHERE order_id='.$orderId)->fetchAll(PDO::FETCH_ASSOC);
        (new OrderExcelService($legacy))->saveOrderXlsx($order,$items,$out);
        $book=PhpOffice\PhpSpreadsheet\IOFactory::load($out);$sheet=$book->getActiveSheet();
        legacyDownloadCheck(count($sheet->getDrawingCollection())>0,'Image embedded under MySQL 5.5 guard: '.$status);
        $values=[];foreach($sheet->getCellCollection()->getCoordinates() as $c)$values[]=$sheet->getCell($c)->getValue();
        legacyDownloadCheck(in_array('AUTO-1',$values,true)&&in_array('00125',$values,true),'Both identifiers preserved: '.$status);
        $book->disconnectWorksheets();
    }
    echo "PASS: $checks legacy container-retry, media-scope and image-bearing status export assertions\n";
} finally { @unlink($file);@unlink($out); }
