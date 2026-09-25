<?php
require_once dirname(__DIR__).'/backend/config/database.php';
$pdo=getDb();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='clms_hardening_20260919')throw new RuntimeException('Disposable database required');
if(($argv[1]??'')==='--worker'){
    session_start();$r=json_decode($argv[2],true);$_SESSION=['user_id'=>$r['actor']??1,'user_roles'=>[]];$_GET=$r['query']??[];
    require_once dirname(__DIR__).'/backend/api/helpers.php';
    try{
        if(!empty($r['legacy'])){require_once __DIR__.'/support/legacy_no_json_pdo.php';require_once dirname(__DIR__).'/backend/services/SupplierItemsService.php';jsonResponse(SupplierItemsService::listing(new LegacyNoJsonPDO($pdo),(int)$r['id'],$_GET));}
        $h=require dirname(__DIR__).'/backend/api/handlers/'.($r['resource']??'suppliers').'.php';$h($r['method']??'GET',isset($r['id'])?(string)$r['id']:null,$r['action']??null,$r['body']??[]);
    }catch(Throwable $e){jsonError($e->getMessage(),500);}exit;
}
function siRaw(array $r):string{$pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'--worker',json_encode($r)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);if($code||$err)throw new RuntimeException($out.$err);return $out;}
function siCall(array $r):array{$out=siRaw($r);$j=json_decode($out,true);if(!is_array($j))throw new RuntimeException($out);return $j;}
$count=0;function siCheck(bool $ok,string $label):void{global $count;if(!$ok)throw new RuntimeException($label);$count++;}
$customer=(int)$pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn();
$pdo->prepare('INSERT INTO suppliers(code,name) VALUES (?,?)')->execute(['CEDAR-'.bin2hex(random_bytes(3)),'Cedar Homewares']);$supplier=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO suppliers(code,name) VALUES (?,?)')->execute(['ALPINE-'.bin2hex(random_bytes(3)),'Alpine Lighting']);$other=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO products(supplier_id,description_en,description_cn,hs_code) VALUES (?,'Bamboo tray','竹托盘','4419')")->execute([$supplier]);$product=(int)$pdo->lastInsertId();
$orders=[];foreach([$supplier,$other,$other] as $s){$pdo->prepare("INSERT INTO orders(customer_id,supplier_id,status,created_by) VALUES (?,?,'Draft',1)")->execute([$customer,$s]);$orders[]=(int)$pdo->lastInsertId();}
$insert=$pdo->prepare("INSERT INTO order_items(order_id,supplier_id,description_en,item_no,item_number,quantity,unit,cartons,declared_cbm,declared_weight,shared_carton_contents) VALUES (?,?,?,?,?,12,'pieces',1,0.12,6,?)");
$insert->execute([$orders[0],null,'Bamboo tray','CED-1','007',null]);
$insert->execute([$orders[1],$supplier,'Bamboo basket','CED-2','007',null]);
$insert->execute([$orders[0],$other,'Unrelated lamp','ALP-1','LAMP',null]);
$shared=json_encode([['supplier_id'=>(string)$supplier,'description_en'=>'Cedar shared bowl','item_no'=>'CED-3','item_number'=>'008','quantity'=>6],['supplier_id'=>$other,'description_en'=>'Private unrelated content','quantity'=>6]]);
$insert->execute([$orders[2],$other,'Shared shipment carton',null,null,$shared]);
$request=['id'=>$supplier,'action'=>'items-orders'];
$res=siCall($request);siCheck(empty($res['error'])&&$res['meta']['total']===3,'Header, item and shared-carton links included without unrelated item');
siCheck(count(array_filter($res['data'],static fn($r)=>$r['item_number']==='007'))===2,'Duplicate manual numbers preserved');
siCheck(count(array_filter($res['data'],static fn($r)=>$r['item_no']==='CED-1'))===1,'Internal item identifier preserved');
$sharedRows=array_values(array_filter($res['data'],static fn($r)=>$r['shared_carton_link']));siCheck(count($sharedRows)===1&&count($sharedRows[0]['linked_contents'])===1&&!str_contains(json_encode($res),'Private unrelated content'),'Only matching shared-carton contents exposed');
$legacy=siCall($request+['legacy'=>true]);siCheck($legacy['data']===$res['data'],'MySQL 5.5 fallback agrees with native JSON branch');
$page=siCall($request+['query'=>['limit'=>'1','offset'=>'1']]);siCheck($page['meta']['total']===3&&count($page['data'])===1,'Paginated count preserves distinct business rows');
$filtered=siCall($request+['query'=>['q'=>'007']]);siCheck(count($filtered['data'])===2,'Search by repeated manual number');
$filtered=siCall($request+['query'=>['q'=>'008']]);siCheck(count($filtered['data'])===1&&$filtered['data'][0]['shared_carton_link'],'Search matching shared-carton identifier');
$filtered=siCall($request+['query'=>['q'=>'Private unrelated content']]);siCheck($filtered['data']===[],'Search cannot expose another supplier shared content');
$filtered=siCall($request+['query'=>['q'=>'%_']]);siCheck($filtered['data']===[],'Search treats wildcard characters literally');
$products=siCall($request+['query'=>['kind'=>'products','q'=>'竹']]);siCheck($products['meta']['total']===1&&(int)$products['data'][0]['id']===$product,'Unicode catalog search returns supplier products');
siCheck(!empty(siCall($request+['query'=>['offset'=>'-1']])['error']),'Invalid pagination rejected');
$pdo->prepare("INSERT INTO users(email,password_hash,full_name,is_active) VALUES (?,'unusable','Supplier scope reader',1)")->execute(['supplier-scope-'.bin2hex(random_bytes(4)).'@example.invalid']);$reader=(int)$pdo->lastInsertId();
siCheck(!empty(siCall($request+['actor'=>$reader])['error']),'Unauthorized direct API rejected');
foreach(['suppliers.read','suppliers.details.read','suppliers.manage.read','orders.read'] as $key)$pdo->prepare('INSERT INTO user_permission_overrides(user_id,permission_key,is_allowed) VALUES (?,?,1)')->execute([$reader,$key]);
$restricted=siCall($request+['actor'=>$reader]);siCheck(empty($restricted['error'])&&$restricted['data']===[],'Action-only reader cannot cross customer visibility scope');
siCheck(!empty(siCall($request+['actor'=>$reader,'query'=>['kind'=>'products']])['error']),'Catalog permission independently enforced');
$pdo->prepare("INSERT INTO user_permission_overrides(user_id,permission_key,is_allowed) VALUES (?,'page:suppliers',1)")->execute([$reader]);
siCheck(siCall($request+['actor'=>$reader])['meta']['total']===3,'Supplier page grant enables normal operational lookup');
// Receive existing cargo before archiving its supplier; its stock must survive unchanged.
require_once dirname(__DIR__).'/backend/services/OrderReceivingService.php';
$pdo->prepare("UPDATE orders SET status='Approved' WHERE id=?")->execute([$orders[0]]);
$receiptItems=$pdo->query('SELECT id FROM order_items WHERE order_id='.(int)$orders[0])->fetchAll(PDO::FETCH_COLUMN);
(new OrderReceivingService())->receive($pdo,$orders[0],['idempotency_key'=>'supplier-stock-'.$supplier,'actual_cartons'=>2,'actual_cbm'=>.24,'actual_weight'=>12,'items'=>array_map(static fn($i)=>['order_item_id'=>(int)$i,'actual_cartons'=>1,'actual_quantity'=>12,'actual_cbm'=>.12,'actual_weight'=>6],$receiptItems)],1,true);
$stockBefore=siCall(['resource'=>'warehouse-stock','query'=>['order_ids'=>(string)$orders[0]]]);
siCheck(empty($stockBefore['error'])&&!empty($stockBefore['data']),'Received supplier cargo has warehouse projection');
$current=siCall(['id'=>$supplier]);$delete=['id'=>$supplier,'method'=>'DELETE','body'=>['revision'=>$current['data']['revision']]];
siCheck(empty(siCall($delete)['error']),'Supplier with real item links archives');
siCheck(siCall($request)['meta']['total']===3,'History lookup remains consistent after archive');
$stockAfter=siCall(['resource'=>'warehouse-stock','query'=>['order_ids'=>(string)$orders[0]]]);
siCheck($stockAfter===$stockBefore,'Supplier archive cannot change received quantities, stock, CBM or weight');
$orderAfter=siCall(['resource'=>'orders','id'=>$orders[0]]);
siCheck(empty($orderAfter['error'])&&str_contains(json_encode($orderAfter),'Cedar Homewares'),'Existing order still resolves archived supplier name');
$csv=siRaw(['resource'=>'orders','id'=>$orders[0],'action'=>'export','query'=>['format'=>'csv']]);
siCheck(str_contains($csv,'CED-1')&&str_contains($csv,'007')&&str_contains($csv,'Cedar Homewares'),'Historical CSV retains archived supplier and both item identifiers');
$xlsx=siRaw(['resource'=>'orders','id'=>$orders[0],'action'=>'export','query'=>['format'=>'xlsx']]);
siCheck(str_starts_with($xlsx,'PK'),'Historical XLSX still downloads after supplier deletion');
$procurement=siCall(['resource'=>'procurement-drafts','method'=>'POST','body'=>['name'=>'Blocked archived product','idempotency_key'=>'archived-product-'.$supplier,'items'=>[['product_id'=>$product,'quantity'=>1]]]]);
siCheck(!empty($procurement['error'])&&str_contains($procurement['message']??'','Supplier is deleted'),'Product-only procurement cannot bypass supplier archive');
siCheck(!in_array($product,array_map('intval',array_column(siCall(['resource'=>'products','id'=>'search','query'=>['q'=>'Bamboo']])['data'],'id')),true),'Archived supplier catalog excluded from new-item search');
$newProduct=['resource'=>'products','method'=>'POST','body'=>['supplier_id'=>$supplier,'description_en'=>'Unwanted new product','cbm'=>0.1,'idempotency_key'=>bin2hex(random_bytes(16))]];
$rejectedProduct=siCall($newProduct);siCheck(!empty($rejectedProduct['error'])&&str_contains($rejectedProduct['message']??'','Supplier is deleted'),'Direct creation cannot use archived supplier');
$csv='code,name'."\n".$current['data']['code'].',Cedar Homewares';
$rejectedImport=siCall(['id'=>'import','method'=>'POST','body'=>['csv'=>$csv,'idempotency_key'=>bin2hex(random_bytes(16))]]);siCheck(!empty($rejectedImport['error'])&&str_contains($rejectedImport['message']??'','Supplier is deleted'),'Import cannot silently reactivate archived supplier');
siCheck($pdo->query('SELECT COUNT(*) FROM orders WHERE id IN ('.implode(',',$orders).')')->fetchColumn()==3,'Archive preserves operational orders');
$pdo->prepare("UPDATE suppliers SET deleted_at=NULL,deleted_by=NULL,delete_reason=NULL WHERE id=?")->execute([$supplier]); // disposable UI fixture; production restore is tested separately
$fresh=siCall(['method'=>'POST','body'=>['name'=>'Cypress Homewares '.bin2hex(random_bytes(4)),'idempotency_key'=>bin2hex(random_bytes(16))]]);
siCheck(empty($fresh['error'])&&!empty($fresh['data']['id']),'Unused supplier created through normal API');
$freshId=(int)$fresh['data']['id'];$freshDetail=siCall(['id'=>$freshId]);
siCheck(siCall(['id'=>$freshId,'action'=>'items-orders'])['meta']['total']===0,'New supplier has no linked order items');
siCheck(empty(siCall(['method'=>'DELETE','id'=>$freshId,'body'=>['revision'=>$freshDetail['data']['revision']]])['error']),'Newly created supplier deletes without false dependency rejection');
siCheck(!empty(siCall(['id'=>$freshId])['data']['deleted_at']),'Unused supplier deletion persists');
file_put_contents(__DIR__.'/support/supplier-items-fixtures.json',json_encode(['supplier'=>$supplier,'orders'=>$orders,'product'=>$product]));
echo "PASS $count supplier items/downstream checks\n";
