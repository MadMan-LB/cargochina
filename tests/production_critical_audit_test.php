<?php

$root = dirname(__DIR__);
require_once $root . '/backend/config/database.php';
require_once $root . '/backend/api/helpers.php';
require_once $root . '/backend/services/TranslationService.php';
require_once $root . '/backend/services/ItemClassificationService.php';
require_once $root . '/backend/services/DraftOrderCostService.php';
require_once $root . '/backend/services/OrderItemNumberingService.php';
require_once $root . '/includes/customer_visibility.php';

$pdo = getDb();
$passed = 0; $failed = 0;
function auditTest(string $name, callable $fn): void { global $passed,$failed; try{$fn();echo "PASS: $name\n";$passed++;}catch(Throwable $e){echo "FAIL: $name - {$e->getMessage()}\n";$failed++;} }
function auditAssert(bool $ok, string $message): void { if(!$ok) throw new Exception($message); }

auditTest('fresh safety migrations and structured tables exist', function() use($pdo){
    foreach(['translation_jobs','translation_manual_corrections','bilingual_text_registry','item_types','item_classifications','draft_order_costs','draft_order_cost_history'] as $table) {
        auditAssert((bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn(), "Missing $table");
    }
    foreach(['actual_height','actual_width','actual_length'] as $column) {
        auditAssert((bool)$pdo->query("SHOW COLUMNS FROM warehouse_receipt_items LIKE " . $pdo->quote($column))->fetchColumn(), "Missing $column");
    }
});

auditTest('shared search helpers preserve mixed bilingual input and safe bounds', function(){
    auditAssert(clmsNormalizeSearchQuery("  ACME   上海  ") === 'ACME 上海', 'Search whitespace normalization failed');
    auditAssert(clmsSearchLike('ACME 上海') === '%ACME%上海%', 'Mixed-language LIKE pattern failed');
    auditAssert(clmsQueryLimit('9999',50,100) === 100 && clmsQueryOffset('0') === 0, 'Pagination bounds failed');
});

auditTest('balances party search works across legacy utf8 and utf8mb4 collations', function() use($pdo,$root){
    $handler = require $root . '/backend/api/handlers/balances.php';
    $rows = balancesFetchParties($pdo, 'customer', 'a', 10);
    auditAssert(is_array($rows), 'Balances customer search did not return an array');
});

auditTest('translation outage queues without fabricating text and manual correction wins', function() use($pdo){
    $text = 'Audit translation ' . bin2hex(random_bytes(4));
    $service = new TranslationService($pdo);
    $result = $service->translateDetailed($text, 'en', 'zh');
    auditAssert(in_array($result['status'], ['pending','failed','translated'], true), 'Unexpected translation status');
    auditAssert(!preg_match('/^\[(?:EN|ZH)\]\s/', (string)$result['translated_text']), 'Placeholder translation returned');
    $manual = '人工修正';
    $service->saveManualCorrection($text, 'en', 'zh', $manual, null);
    $again = $service->translateDetailed($text, 'en', 'zh');
    auditAssert($again['status']==='manual' && $again['translated_text']===$manual, 'Manual correction was not preserved');
    $hash=hash('sha256',$text);
    $pdo->prepare('DELETE FROM translation_manual_corrections WHERE source_hash=?')->execute([$hash]);
    $pdo->prepare('DELETE FROM translation_jobs WHERE source_hash=?')->execute([$hash]);
});

auditTest('classification suggests known risks and never silently defaults unknown items to normal', function() use($pdo){
    $service = new ItemClassificationService($pdo);
    auditAssert($service->suggest(['description_en'=>'lithium battery pack'])['item_type_code']==='dangerous', 'Dangerous item suggestion failed');
    auditAssert($service->suggest(['description_cn'=>'化妆品口红'])['item_type_code']==='cosmetics', 'Chinese cosmetics suggestion failed');
    $unknown=$service->suggest(['description_en'=>'generic object']);
    auditAssert($unknown['item_type_code']==='unclassified' && $unknown['requires_confirmation'], 'Unknown item silently classified');
});

auditTest('item numbering follows supplier groups and returns to prior supplier', function(){
    $items=OrderItemNumberingService::assignItemNumbers([
        ['supplier_id'=>1,'shipping_code'=>'CUST'],
        ['supplier_id'=>1,'shipping_code'=>'CUST'],
        ['supplier_id'=>2,'shipping_code'=>'CUST'],
        ['supplier_id'=>2,'shipping_code'=>'CUST'],
        ['supplier_id'=>1,'shipping_code'=>'CUST'],
    ],'CUST');
    $actual=array_column($items,'item_no');
    auditAssert($actual===['CUST-1-1','CUST-1-2','CUST-2-1','CUST-2-2','CUST-1-3'], 'Unexpected sequence: '.implode(',',$actual));
});

auditTest('draft cost decimal calculation, audit update, and soft delete are transaction-safe', function() use($pdo){
    $customerId=(int)$pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn();
    $supplierId=(int)$pdo->query('SELECT id FROM suppliers ORDER BY id LIMIT 1')->fetchColumn();
    $userId=(int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
    auditAssert($customerId>0 && $userId>0, 'Seed customer/user missing');
    $pdo->prepare("INSERT INTO orders (customer_id,supplier_id,status,order_type,currency,created_by) VALUES (?,?,'Draft','draft_procurement','USD',?)")->execute([$customerId,$supplierId?:null,$userId]);
    $orderId=(int)$pdo->lastInsertId(); $costId=0;
    try {
        $pdo->beginTransaction();
        $service=new DraftOrderCostService($pdo);
        $row=$service->create($orderId,['cost_type_code'=>'transportation','amount'=>'12.3456','currency'=>'RMB','exchange_rate'=>'0.14000000','base_currency'=>'USD','responsible_payer'=>'company','allocation_method'=>'none','idempotency_key'=>'critical-cost-'.bin2hex(random_bytes(8))],$userId);
        $costId=(int)$row['id'];
        auditAssert($row['base_amount']==='1.7284','Exact rounded base amount mismatch: '.$row['base_amount']);
        $service->update($costId,['cost_type_code'=>'transportation','amount'=>'20.0000','currency'=>'RMB','exchange_rate'=>'0.14000000','base_currency'=>'USD','responsible_payer'=>'customer','allocation_method'=>'by_quantity','lock_version'=>$row['lock_version']],$userId);
        $service->delete($costId,$userId);
        $pdo->commit();
        $actions=$pdo->query("SELECT GROUP_CONCAT(action ORDER BY id) FROM draft_order_cost_history WHERE cost_id=$costId")->fetchColumn();
        auditAssert($actions==='create,update,delete','Cost audit history incomplete: '.$actions);
        $stored=$pdo->query("SELECT responsible_payer,allocation_method,posting_status FROM draft_order_costs WHERE id=$costId")->fetch(PDO::FETCH_ASSOC);
        auditAssert($stored['responsible_payer']==='customer'&&$stored['allocation_method']==='none'&&$stored['posting_status']==='archived','Approved shipment treatment not enforced');
    } finally {
        if($pdo->inTransaction())$pdo->rollBack();
        $pdo->exec("DELETE FROM shipment_financial_entries WHERE order_id=$orderId");
        if($costId){$pdo->exec("DELETE FROM draft_order_cost_history WHERE cost_id=$costId");$pdo->exec("DELETE FROM draft_order_costs WHERE id=$costId");}
        $pdo->exec("DELETE FROM orders WHERE id=$orderId");
    }
});

auditTest('documented lifecycle permissions are least-privilege defaults', function() use($root){
    $rbac=require $root.'/backend/config/rbac.php';
    auditAssert(!in_array('ChinaEmployee',$rbac['orders']['approve'],true),'ChinaEmployee can approve by default');
    auditAssert($rbac['orders']['receive']===['WarehouseStaff','SuperAdmin'],'Receiving roles are broader than documented');
    auditAssert(!in_array('WarehouseStaff',$rbac['orders']['write'],true),'WarehouseStaff can edit commercial orders');
});

auditTest('customer management visibility excludes unattributed and other-owner records', function() use($pdo){
    $hash=password_hash('AuditOnly!12345',PASSWORD_DEFAULT);
    $pdo->prepare('INSERT INTO users(email,password_hash,full_name,is_active) VALUES (?,?,?,1)')->execute(['visibility-a-'.bin2hex(random_bytes(3)).'@invalid.local',$hash,'Visibility A']);
    $userA=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO users(email,password_hash,full_name,is_active) VALUES (?,?,?,1)')->execute(['visibility-b-'.bin2hex(random_bytes(3)).'@invalid.local',$hash,'Visibility B']);
    $userB=(int)$pdo->lastInsertId();
    $ids=[];
    try {
        foreach([['AUD-OWN-'.bin2hex(random_bytes(2)),'Owned',$userA],['AUD-HIDDEN-'.bin2hex(random_bytes(2)),'Hidden',$userB],['AUD-NULL-'.bin2hex(random_bytes(2)),'Unattributed',null],['AUD-EX-'.bin2hex(random_bytes(2)),'Allowed creator',1]] as $row){
            $pdo->prepare('INSERT INTO customers(code,name,created_by) VALUES (?,?,?)')->execute($row); $ids[]=(int)$pdo->lastInsertId();
        }
        $pdo->prepare('INSERT INTO customer_visibility_allowed_creators(user_id,allowed_creator_user_id,created_by) VALUES (?,?,1)')->execute([$userA,1]);
        auditAssert(clmsCanAccessCustomer($pdo,$ids[0],$userA,[]),'Owner could not access own customer');
        auditAssert(!clmsCanAccessCustomer($pdo,$ids[1],$userA,[]),'Other owner customer leaked');
        auditAssert(!clmsCanAccessCustomer($pdo,$ids[2],$userA,[]),'Unattributed legacy customer leaked');
        auditAssert(clmsCanAccessCustomer($pdo,$ids[3],$userA,[]),'Allowed-creator exception was ignored');
        auditAssert(clmsCanAccessCustomer($pdo,$ids[1],$userA,['SuperAdmin']),'All-customer role could not access record');
    } finally {
        $pdo->prepare('DELETE FROM customer_visibility_allowed_creators WHERE user_id=? OR allowed_creator_user_id IN (?,?)')->execute([$userA,$userA,$userB]);
        if($ids)$pdo->exec('DELETE FROM customers WHERE id IN ('.implode(',',array_map('intval',$ids)).')');
        $pdo->exec("DELETE FROM users WHERE id IN ($userA,$userB)");
    }
});

echo "\nTotal: $passed passed, $failed failed\n";
exit($failed?1:0);
