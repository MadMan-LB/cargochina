<?php

$root=dirname(__DIR__);
require_once $root.'/backend/config/database.php';
require_once $root.'/backend/api/helpers.php';
require_once $root.'/backend/services/NotificationTargetService.php';
require_once $root.'/backend/services/NotificationService.php';

$pdo=getDb();$passed=0;$failed=0;
function ntTest(string $name,callable $fn):void{global$passed,$failed;try{$fn();echo"PASS: $name\n";$passed++;}catch(Throwable$e){echo"FAIL: $name - {$e->getMessage()}\n";$failed++;}}
function ntAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function ntOpenNotification(string $root,int $id,array $roles=['SuperAdmin']):array{
    $rootEsc=addslashes(str_replace('\\','/',$root));$rolesCode=var_export($roles,true);$tmp=tempnam(sys_get_temp_dir(),'notification_open_').'.php';
    $code="<?php\nsession_start();\n\$_SESSION['user_id']=1;\n\$_SESSION['user_roles']=$rolesCode;\nrequire '$rootEsc/backend/config/database.php';\nrequire '$rootEsc/backend/api/helpers.php';\n\$h=require '$rootEsc/backend/api/handlers/notifications.php';\n\$h('POST','".$id."','open',[]);";
    file_put_contents($tmp,$code);$out=shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($tmp).' 2>&1');@unlink($tmp);return json_decode((string)$out,true)?:[];
}

$nonce=bin2hex(random_bytes(4));
$userId=(int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
$pdo->prepare('INSERT INTO customers(code,name,created_by) VALUES (?,?,?)')->execute(["NTC-$nonce","Notification Target Customer $nonce",$userId?:null]);$customerId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO suppliers(code,name) VALUES (?,?)')->execute(["NTS-$nonce","Notification Target Supplier $nonce"]);$supplierId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO users(email,password_hash,full_name,is_active) VALUES (?,?,?,1)')->execute(["nt-restricted-$nonce@example.test",password_hash('isolated-test',PASSWORD_DEFAULT),'Restricted notification user']);$restrictedUserId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO orders(customer_id,supplier_id,expected_ready_date,status,order_type,created_by) VALUES (?,?,CURDATE(),'Draft','draft_procurement',?)")->execute([$customerId,$supplierId,$userId?:null]);$orderId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO containers(code,max_cbm,max_weight,status) VALUES (?,28,20000,'available')")->execute(["NT-$nonce"]);$containerId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO shipment_drafts(container_id,status) VALUES (?,'draft')")->execute([$containerId]);$shipmentDraftId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouse_receipts(order_id,actual_cartons,actual_cbm,actual_weight,receipt_condition,received_by,receiving_operation_id) VALUES (?,0,0,0,'good',?,?)")->execute([$orderId,$userId?:null,"nt-receipt-$nonce"]);$receiptId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO supplier_payments(supplier_id,order_id,amount,currency,payment_type) VALUES (?,?,1,'USD','partial')")->execute([$supplierId,$orderId]);$paymentId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO balance_transactions(party_type,party_id,order_id,transaction_type,direction,amount,currency,created_by,transaction_date) VALUES ('customer',?,?,'adjustment','increase_balance',1,'USD',?,CURDATE())")->execute([$customerId,$orderId,$userId?:null]);$balanceId=(int)$pdo->lastInsertId();
$service=new NotificationTargetService($pdo);

try{
    ntTest('structured draft order resolves to exact builder',function()use($service,$orderId,$userId){$r=$service->resolve(['target_type'=>'order','target_id'=>$orderId],$userId,['SuperAdmin']);ntAssert(!empty($r['available']),'target unavailable');ntAssert($r['url']==="/cargochina/procurement_drafts.php?order_id=$orderId",'wrong exact route');});
    ntTest('legacy order notification fallback is narrow and exact',function()use($service,$orderId,$userId){$r=$service->resolve(['type'=>'order_created','title'=>"Order #$orderId created"],$userId,['SuperAdmin']);ntAssert(!empty($r['available'])&&!empty($r['legacy']),'legacy order not resolved');});
    ntTest('deleted target is unavailable without unrelated redirect',function()use($service,$userId){$r=$service->resolve(['target_type'=>'order','target_id'=>2147483647],$userId,['SuperAdmin']);ntAssert(empty($r['available'])&&empty($r['url']),'missing target redirected');});
    ntTest('page permission is checked independently of notification ownership',function()use($service,$containerId,$userId){$r=$service->resolve(['target_type'=>'container','target_id'=>$containerId],$userId,['FieldStaff']);ntAssert(empty($r['available']),'unauthorized container target was exposed');});
    ntTest('customer-scoped order target rejects IDOR access',function()use($service,$orderId,$restrictedUserId){$r=$service->resolve(['target_type'=>'order','target_id'=>$orderId],$restrictedUserId,['ChinaEmployee']);ntAssert(empty($r['available']),'order outside customer visibility scope was exposed');});
    ntTest('approved target map resolves every supported entity to an exact internal route',function()use($service,$userId,$customerId,$supplierId,$containerId,$shipmentDraftId,$receiptId,$orderId,$paymentId,$balanceId){
        $cases=[
            'customer'=>[$customerId,"/cargochina/customers.php?customer_id=$customerId"],
            'supplier'=>[$supplierId,"/cargochina/suppliers.php?supplier_id=$supplierId"],
            'container'=>[$containerId,"/cargochina/containers.php?container_id=$containerId"],
            'shipment_draft'=>[$shipmentDraftId,"/cargochina/consolidation.php?shipment_draft_id=$shipmentDraftId"],
            'receiving_record'=>[$receiptId,"/cargochina/receiving.php?receipt_id=$receiptId&order_id=$orderId"],
            'supplier_payment'=>[$paymentId,"/cargochina/suppliers.php?supplier_id=$supplierId&payment_id=$paymentId"],
            'balance_transaction'=>[$balanceId,"/cargochina/balances.php?transaction_id=$balanceId#transactions"],
        ];
        foreach($cases as $type=>[$id,$url]){$r=$service->resolve(['target_type'=>$type,'target_id'=>$id],$userId,['SuperAdmin']);ntAssert(!empty($r['available']),"$type target unavailable");ntAssert(($r['url']??null)===$url,"$type route was not exact");ntAssert(str_starts_with($url,'/cargochina/'),"$type route was external");}
    });
    ntTest('ambiguous old notification is not guessed',function()use($service,$userId){$r=$service->resolve(['type'=>'stale_order_alert','title'=>'Stale order alert'],$userId,['SuperAdmin']);ntAssert(empty($r['available']),'ambiguous target was guessed');});
    ntTest('open endpoint returns exact target and marks notification read',function()use($pdo,$root,$orderId){$nid=(new NotificationService($pdo))->notify(1,'order_created','Target open test',null,'dashboard','order',$orderId);try{$json=ntOpenNotification($root,$nid);ntAssert(($json['data']['target_type']??'')==='order'&&(int)($json['data']['target_id']??0)===$orderId,'wrong structured target');ntAssert(($json['data']['url']??'')==="/cargochina/procurement_drafts.php?order_id=$orderId",'wrong open URL');$read=$pdo->query("SELECT read_at FROM notifications WHERE id=$nid")->fetchColumn();ntAssert(!empty($read),'read status was not updated');}finally{$pdo->prepare('DELETE FROM notifications WHERE id=?')->execute([$nid]);}});
}finally{
    $pdo->prepare('DELETE FROM balance_transactions WHERE id=?')->execute([$balanceId]);
    $pdo->prepare('DELETE FROM supplier_payments WHERE id=?')->execute([$paymentId]);
    $pdo->prepare('DELETE FROM warehouse_receipts WHERE id=?')->execute([$receiptId]);
    $pdo->prepare('DELETE FROM shipment_drafts WHERE id=?')->execute([$shipmentDraftId]);
    $pdo->prepare('DELETE FROM orders WHERE id=?')->execute([$orderId]);
    $pdo->prepare('DELETE FROM containers WHERE id=?')->execute([$containerId]);
    $pdo->prepare('DELETE FROM suppliers WHERE id=?')->execute([$supplierId]);
    $pdo->prepare('DELETE FROM customers WHERE id=?')->execute([$customerId]);
    $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$restrictedUserId]);
}

echo"\nTotal: $passed passed, $failed failed\n";exit($failed?1:0);
