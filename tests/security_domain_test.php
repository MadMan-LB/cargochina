<?php
require_once dirname(__DIR__).'/backend/config/database.php';$pdo=getDb();
require_once dirname(__DIR__).'/backend/api/helpers.php';
function securityCheck($ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
securityCheck($pdo->query('SELECT DATABASE()')->fetchColumn()==='clms_hardening_20260919','Security tests require disposable database');
if(($argv[1]??'')==='--worker'){
    if(session_status()===PHP_SESSION_NONE)session_start();$r=json_decode($argv[2],true);$_SESSION=empty($r['user'])?[]:['user_id'=>$r['user'],'user_roles'=>['SuperAdmin']];$_GET=$r['query']??[];
    register_shutdown_function(static function(){fwrite(STDERR,(string)(http_response_code()?:200));});
    require_once dirname(__DIR__).'/backend/api/helpers.php';
    if(($r['mode']??'')==='image'){
        require_once dirname(__DIR__).'/backend/services/OrderExcelService.php';$service=new OrderExcelService($pdo);$m=new ReflectionMethod($service,'validateWorkbookImageFile');jsonResponse($m->invoke($service,$r['path']));
    }
    if(($r['mode']??'')==='media'){
        require_once dirname(__DIR__).'/backend/services/UploadAccessService.php';UploadAccessService::authorize($pdo,$r['path']);jsonResponse(['allowed'=>true]);
    }
    $h=require dirname(__DIR__).'/backend/api/handlers/'.$r['resource'].'.php';
    try{$h($r['method']??'GET',$r['id']??null,$r['action']??null,$r['body']??[]);}catch(Throwable $e){jsonError($e->getMessage(),500);}exit;
}
function securityCall(array $r):array{
    $pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'--worker',json_encode($r)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);securityCheck($exit===0&&ctype_digit($err),'Security worker failed: '.$err.' '.substr($out,0,160));$body=json_decode($out,true);securityCheck(is_array($body),'Invalid security response: '.substr($out,0,160));return ['status'=>(int)$err,'body'=>$body];
}
$users=[];foreach(['operator_a','operator_b','restricted_a','restricted_b','roleless','disabled'] as $kind){$pdo->prepare("INSERT INTO users(email,password_hash,full_name,is_active) VALUES (?,'unusable',?,?)")->execute(['security-'.bin2hex(random_bytes(6)).'@example.invalid','Security QA '.$kind,$kind==='disabled'?0:1]);$users[$kind]=(int)$pdo->lastInsertId();}
$role=(int)$pdo->query("SELECT id FROM roles WHERE code='ChinaEmployee'")->fetchColumn();foreach(['operator_a','operator_b','disabled'] as $kind)$pdo->prepare('INSERT INTO user_roles(user_id,role_id) VALUES (?,?)')->execute([$users[$kind],$role]);
foreach(['restricted_a','restricted_b'] as $kind) foreach(['customers.read','customers.finance','design-attachments','internal-messages','uploads.read','orders.write','products.write'] as $permission) $pdo->prepare('INSERT INTO user_permission_overrides(user_id,permission_key,is_allowed) VALUES (?,?,1)')->execute([$users[$kind],$permission]);
foreach(glob(dirname(__DIR__).'/backend/api/handlers/*.php') as $file){$resource=basename($file,'.php');if(in_array($resource,['auth','confirm'],true))continue;
    $r=securityCall(['resource'=>$resource]);securityCheck($r['status']===401,'Anonymous direct handler allowed: '.$resource.' '.json_encode($r));
    $r=securityCall(['resource'=>$resource,'user'=>$users['disabled']]);securityCheck($r['status']===401,'Disabled session retained access: '.$resource.' '.json_encode($r));
}
echo "PASS: every private handler rejects anonymous and disabled users, including forged cached admin roles\n";
foreach(['orders','receiving','warehouse-stock','containers','shipment-drafts','products','suppliers','customers','draft-orders','order-templates','financials','balances','users','config','diagnostics','audit-log','translate','translations','upload'] as $resource){$r=securityCall(['resource'=>$resource,'user'=>$users['roleless']]);securityCheck($r['status']===403,'Roleless direct endpoint allowed: '.$resource.' '.json_encode($r));}
foreach([['orders','POST',null,'approve'],['orders','POST',null,null],['orders','PUT','1',null],['orders','DELETE','1',null],['containers','POST','1','assign-orders'],['shipment-drafts','POST','1','finalize'],['shipment-drafts','POST','1','push']] as [$resource,$method,$id,$action])securityCheck(securityCall(['resource'=>$resource,'user'=>$users['roleless'],'method'=>$method,'id'=>$id,'action'=>$action])['status']===403,'Roleless alternate mutation allowed');
foreach(['1oops','-1','0','4294967296'] as $id)securityCheck(securityCall(['resource'=>'orders','user'=>1,'id'=>$id])['status']===422,'Malformed identity accepted');
echo "PASS: direct permission checks, alternate mutations and identity validation\n";
$tag='SECURITY-'.bin2hex(random_bytes(5));$pdo->prepare('INSERT INTO customers(code,name,created_by) VALUES (?,?,?)')->execute([$tag,'Coastal security fixture',$users['restricted_a']]);$buyer=(int)$pdo->lastInsertId();
securityCheck(securityCall(['resource'=>'customers','user'=>$users['operator_b'],'id'=>(string)$buyer])['status']===200,'Explicit customer page grant lost shared visibility');
foreach([['customers',(string)$buyer,null,[]],['customers',(string)$buyer,'balance',[]],['design-attachments',null,null,['entity_type'=>'customer','entity_id'=>$buyer]],['internal-messages',null,null,['customer_id'=>$buyer]]] as [$resource,$id,$action,$query]){$r=securityCall(['resource'=>$resource,'user'=>$users['restricted_b'],'id'=>$id,'action'=>$action,'query'=>$query]);securityCheck(in_array($r['status'],[403,404],true),'Cross-customer private data exposed: '.$resource.' '.json_encode($r));}
$pdo->prepare("INSERT INTO orders(customer_id,status,created_by) VALUES (?,'Draft',?)")->execute([$buyer,$users['restricted_a']]);$order=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO order_items(order_id,quantity,cartons,qty_per_carton,unit,declared_cbm,declared_weight,description_en,dimensions_scope) VALUES (?,10,1,10,'pieces',.1,10,'Linen runners','carton')")->execute([$order]);$item=(int)$pdo->lastInsertId();
$r=securityCall(['resource'=>'item-classifications','user'=>1,'method'=>'PUT','id'=>(string)$item,'body'=>['entity_type'=>'order_item','item_type_code'=>'normal','confidence'=>1]]);securityCheck($r['status']===200,'Valid classification denied: '.json_encode($r));
foreach(['Approved','Confirmed','AssignedToContainer','FinalizedAndPushedToTracking'] as $state){$pdo->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$state,$order]);$r=securityCall(['resource'=>'item-classifications','user'=>1,'method'=>'PUT','id'=>(string)$item,'body'=>['entity_type'=>'order_item','item_type_code'=>'dangerous']]);securityCheck($r['status']===409,'Locked classification changed: '.$state);}
securityCheck($pdo->query("SELECT item_type_code FROM item_classifications WHERE entity_type='order_item' AND entity_id=$item")->fetchColumn()==='normal','Blocked classification persisted');
echo "PASS: customer ownership and alternate classification state protection\n";
require_once dirname(__DIR__).'/backend/services/UploadAccessService.php';
$path='uploads/qa_security_'.bin2hex(random_bytes(8)).'.png';$file=dirname(__DIR__).'/backend/'.$path;
file_put_contents($file,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aTa4AAAAASUVORK5CYII='));
try{
    UploadAccessService::register($pdo,$path,$users['restricted_a']);
    securityCheck(securityCall(['mode'=>'media','user'=>$users['restricted_a'],'path'=>$path])['status']===200,'Uploader cannot read pending image');
    securityCheck(securityCall(['mode'=>'media','user'=>$users['restricted_b'],'path'=>$path])['status']===403,'Another operator read pending upload');
    $pdo->prepare('UPDATE order_items SET image_paths=? WHERE id=?')->execute([json_encode([$path]),$item]);
    securityCheck(securityCall(['mode'=>'media','user'=>$users['restricted_b'],'path'=>$path])['status']===200,'Operationally referenced cargo image denied');
    $pdo->prepare("INSERT INTO design_attachments(entity_type,entity_id,file_path,file_type,uploaded_by) VALUES ('customer',?,?,'png',?)")->execute([$buyer,$path,$users['restricted_a']]);
    securityCheck(securityCall(['mode'=>'media','user'=>$users['restricted_b'],'path'=>$path])['status']===404,'Passport exposed through operational image reference');
    securityCheck(securityCall(['mode'=>'image','user'=>$users['restricted_b'],'path'=>$file])['status']===404,'Workbook embedded another customer passport');
    $r=securityCall(['resource'=>'design-attachments','user'=>$users['restricted_b'],'method'=>'POST','body'=>['entity_type'=>'product','entity_id'=>1,'file_path'=>$path]]);securityCheck($r['status']===404,'Private file reattached through another entity');
    foreach(['uploads/../config/config.php','C:/Windows/win.ini','https://example.invalid/image.png'] as $bad)securityCheck(securityCall(['mode'=>'media','user'=>1,'path'=>$bad])['status']===422,'Unsafe file path accepted');
} finally {if(is_file($file))unlink($file);}
echo "PASS: pending-upload ownership, operational sharing, private-file precedence, workbook and attachment bypass prevention\n";
echo json_encode(['users'=>$users,'order'=>$order,'item'=>$item]),"\n";

