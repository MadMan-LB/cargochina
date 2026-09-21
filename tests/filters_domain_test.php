<?php
require_once dirname(__DIR__).'/backend/config/database.php';
$pdo=getDb();
function filterCheck($ok,string $message):void { if(!$ok)throw new RuntimeException($message); }
filterCheck($pdo->query('SELECT DATABASE()')->fetchColumn()==='clms_hardening_20260919','Filter tests require disposable database');
if(($argv[1]??'')==='--worker') {
    session_start(); $_SESSION=['user_id'=>1,'user_roles'=>['SuperAdmin']];
    $r=json_decode($argv[2],true); $_GET=$r['query'];
    $h=require dirname(__DIR__).'/backend/api/handlers/'.$r['resource'].'.php';
    try {$h('GET',$r['id']??null,$r['action']??null,[]);} catch(Throwable $e){jsonError($e->getMessage(),500);} exit;
}
function filterCall(string $resource,array $query=[],?string $id=null,?string $action=null):array {
    $r=compact('resource','query','id','action');$pipes=[];
    $p=proc_open([PHP_BINARY,__FILE__,'--worker',json_encode($r)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);
    filterCheck($exit===0&&$err==='','Query worker failed: '.$err);$data=json_decode($out,true);filterCheck(is_array($data),'Invalid response: '.substr($out,0,180));return $data;
}
foreach(['orders','draft-orders','receiving','warehouse-stock','containers','shipment-drafts','tracking-push-log','customers','products','suppliers','balances'] as $resource) {
    foreach([['q'=>['nested']],['supplier_id'=>'1junk'],['limit'=>'bad'],['offset'=>-1],['date_from'=>'2026-02-30'],['date_from'=>'2026-10-02','date_to'=>'2026-10-01']] as $q) {
        $r=filterCall($resource,$q);filterCheck(!empty($r['error'])&&!str_contains($r['message'],'SQLSTATE'),'Malformed filter accepted or reached SQL: '.$resource.' '.json_encode($q));
    }
}
foreach([['orders','status','unknown'],['orders','status_mode','bogus'],['products','item_type','banana'],['products','image_filter','sometimes'],['suppliers','payment_status','unknown'],['balances','currency','EUR'],['draft-orders','status',['Unknown']]] as [$resource,$key,$value])filterCheck(!empty(filterCall($resource,[$key=>$value])['error']),'Unknown enum silently broadened filter');
echo "PASS: malformed arrays, IDs, paging, real dates, reversed ranges and enumerations rejected across operational queries\n";
$tag='FILTER-'.bin2hex(random_bytes(5));
$pdo->prepare('INSERT INTO customers(code,name,created_by) VALUES (?,?,1)')->execute([$tag,'Coastal linen filters']);$buyer=(int)$pdo->lastInsertId();
$supplierIds=[];foreach(['Linen supplier','Woven supplier'] as $name){$pdo->prepare('INSERT INTO suppliers(code,name) VALUES (?,?)')->execute([$tag.'-'.count($supplierIds),$name]);$supplierIds[]=(int)$pdo->lastInsertId();}
[$supplier,$sharedSupplier]=$supplierIds;
$productIds=[];foreach(['%_','AB'] as $suffix){$pdo->prepare("INSERT INTO products(supplier_id,description_en,cbm,weight,dimensions_scope) VALUES (?,?,.1,1,'piece')")->execute([$supplier,$tag.$suffix]);$productIds[]=(int)$pdo->lastInsertId();}
$r=filterCall('products',['q'=>$tag.'%_']);filterCheck(count($r['data']??[])===1&&(int)$r['data'][0]['id']===$productIds[0],'Product wildcard expanded search');
$orders=[];
for($i=0;$i<3;$i++){
    $pdo->prepare("INSERT INTO orders(customer_id,supplier_id,status,order_type,created_by,created_at) VALUES (?,?,'Draft','draft_procurement',1,'2026-09-19 10:00:00')")->execute([$buyer,$supplier]);$order=(int)$pdo->lastInsertId();$orders[]=$order;
    $contents=$i===0?json_encode([['supplier_id'=>$sharedSupplier,'supplier_name'=>'Woven supplier','quantity'=>2,'pieces_per_carton'=>2,'description_en'=>'Mixed linen shipment','description_cn'=>'Mixed linen shipment']]):null;
    $pdo->prepare("INSERT INTO order_items(order_id,supplier_id,quantity,cartons,qty_per_carton,unit,declared_cbm,declared_weight,description_en,dimensions_scope,shared_carton_enabled,shared_carton_contents) VALUES (?,?,2,1,2,'pieces',.1,1,?,'carton',?,?)")->execute([$order,$supplier,$tag.($i===0?'%_':'AB'),$i===0?1:0,$contents]);
}
foreach(['orders','draft-orders'] as $resource){
    $r=filterCall($resource,['q'=>$tag.'%_']);filterCheck(count($r['data']??[])===1&&(int)$r['data'][0]['id']===$orders[0],'Order wildcard expanded: '.$resource);
    $r=filterCall($resource,['supplier_id'=>$sharedSupplier,'customer_id'=>$buyer]);filterCheck(count($r['data']??[])===1&&(int)$r['data'][0]['id']===$orders[0],'Shared-carton supplier omitted: '.$resource.' '.json_encode($r));
}
$ids=[];for($offset=0;$offset<3;$offset++){$r=filterCall('orders',['customer_id'=>$buyer,'limit'=>1,'offset'=>$offset]);filterCheck(($r['meta']['total']??0)===3,'Order total varies across pages');$ids[]=(int)$r['data'][0]['id'];}
filterCheck($ids===array_reverse($orders),'Stable newest-first pages duplicate or omit tied rows');
$r=filterCall('orders',['customer_id'=>$buyer,'offset'=>99999]);filterCheck($r['data']===[]&&$r['meta']['total']===3&&!$r['meta']['has_more'],'Beyond-end page metadata incorrect');
echo "PASS: literal wildcard matching, contained-supplier filters and stable complete order pages\n";
$pdo->prepare("INSERT INTO supplier_payments(supplier_id,amount,invoice_amount,currency,payment_type,payment_channel,marked_by) VALUES (?,20,30,'USD','payment','Cash',1)")->execute([$supplier]);
foreach(['outstanding'=>true,'fully_paid'=>false] as $status=>$expected){$r=filterCall('suppliers',['q'=>$tag.'-0','payment_status'=>$status]);filterCheck(empty($r['error'])&&(count($r['data'])===1)===$expected,'Supplier payment filter failed: '.json_encode($r));}
$r=filterCall('balances',['q'=>$tag.'%_'],'transactions');filterCheck(empty($r['error'])&&$r['data']===[],'Financial literal filter failed');
echo "PASS: supplier payment predicates execute and financial filters remain literal\n";
$pdo->exec('UPDATE orders SET status=\'Approved\' WHERE id IN ('.implode(',',$orders).')');
foreach($orders as $index=>$order)$pdo->prepare('UPDATE order_items SET shipping_code=? WHERE order_id=?')->execute([$tag.($index===0?'%_':'AB'),$order]);
$queue=filterCall('receiving',['shipping_code'=>$tag.'%_'],'queue');filterCheck(count($queue['data']??[])===1&&(int)$queue['data'][0]['id']===$orders[0],'Receiving shipping-code wildcard expanded');
$sender=(int)$pdo->query('SELECT id FROM users WHERE id<>1 ORDER BY id LIMIT 1')->fetchColumn();$messages=[];
foreach(array_slice($orders,0,2) as $order){$pdo->prepare('INSERT INTO internal_messages(customer_id,order_id,sender_id,body) VALUES (?,?,?,?)')->execute([$buyer,$order,$sender,'Filtered receipt question']);$messages[]=(int)$pdo->lastInsertId();}
$r=filterCall('internal-messages',['customer_id'=>$buyer,'order_id'=>$orders[0]]);filterCheck(count($r['data']??[])===1,'Message order filter failed');
filterCheck($pdo->query('SELECT read_at FROM internal_messages WHERE id='.$messages[0])->fetchColumn()!==null&&$pdo->query('SELECT read_at FROM internal_messages WHERE id='.$messages[1])->fetchColumn()===null,'Filtered read marked unseen messages read');
echo "PASS: receiving search stays literal and reading filtered messages changes only returned records\n";
foreach([['customer_id'=>$buyer],['supplier_id'=>$supplier],['q'=>$tag],['limit'=>1,'offset'=>1]] as $query){
    $full=filterCall('orders',$query+['view'=>'full']);$list=filterCall('orders',$query+['view'=>'list']);
    filterCheck(empty($full['error'])&&empty($list['error'])&&$full['meta']===$list['meta'],'Compact list changed pagination');
    foreach($full['data'] as &$row)unset($row['items']);unset($row);
    filterCheck($full['data']===$list['data'],'Compact order list changed totals, suppliers, permissions or states');
}
filterCheck(!empty(filterCall('orders',['view'=>'unknown'])['error']),'Unknown list projection accepted');
echo "PASS: compact order lists preserve all summary calculations, eligibility, supplier filters and pagination\n";
echo json_encode(['tag'=>$tag,'orders'=>$orders,'supplier'=>$supplier]),"\n";
