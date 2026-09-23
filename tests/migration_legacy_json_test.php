<?php
require_once dirname(__DIR__).'/backend/config/database.php';
require_once dirname(__DIR__).'/backend/migrations/SidebarConfigMigrationService.php';
$pdo=getDb();
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='clms_hardening_20260919')throw new RuntimeException('Disposable schema required');
$assertions=0;
function checkCompat(bool $ok,string $reason):void{global $assertions;if(!$ok)throw new RuntimeException($reason);$assertions++;}
$dir=dirname(__DIR__).'/backend/migrations/';
checkCompat((bool)preg_match('/email\s+VARCHAR\s*\(255\)\s+CHARACTER SET utf8\s+COLLATE utf8_general_ci\s+NOT NULL UNIQUE/i',file_get_contents($dir.'001_create_master_tables.sql')),'Full email unique key fits legacy 767-byte index');
foreach(['001_create_master_tables.sql','002_create_orders.sql','004_notifications_confirmations.sql','008_suppliers_contact.sql','009_item_capture.sql','010_supplier_store_payments.sql','013_tracking_push_log.sql','026_products_customers_enhancements.sql','052_supplier_payment_extensions.sql','062_balance_sidebar_defaults.sql','065_balances_deployment_hardening.sql'] as $file){
    $sql=file_get_contents($dir.$file);
    $sql=preg_replace('/^\s*--.*$/m','',$sql);
    checkCompat(!preg_match('/\bJSON\s*(?:[,\n\r\s]|NULL|NOT)/i',$sql),'Native JSON column: '.$file);
    checkCompat(!preg_match('/\bJSON_[A-Z_]+\s*\(/i',$sql),'JSON SQL function: '.$file);
}
$s=$pdo->prepare("SELECT key_value FROM system_config WHERE key_name='ROLE_SIDEBAR_PAGES_JSON'");$s->execute();$old=$s->fetchColumn();
$write=$pdo->prepare("UPDATE system_config SET key_value=? WHERE key_name='ROLE_SIDEBAR_PAGES_JSON'");
try {
    $write->execute([json_encode(['ChinaAdmin'=>['orders','custom_page'],'ChinaEmployee'=>['orders'],'LebanonAdmin'=>[],'FieldStaff'=>['suppliers']],JSON_THROW_ON_ERROR)]);
    SidebarConfigMigrationService::apply($pdo,false);
    $read=fn()=>json_decode((string)$pdo->query("SELECT key_value FROM system_config WHERE key_name='ROLE_SIDEBAR_PAGES_JSON'")->fetchColumn(),true);
    $v=$read();checkCompat(in_array('balances',$v['ChinaAdmin'],true)&&in_array('custom_page',$v['ChinaAdmin'],true),'Custom ChinaAdmin grant preserved');
    checkCompat(in_array('balances',$v['ChinaEmployee'],true)&&in_array('balances',$v['LebanonAdmin'],true),'Existing finance role grants included');
    checkCompat($v['FieldStaff']===['suppliers'],'Unrelated role grants unchanged');
    $snapshot=json_encode($v,JSON_THROW_ON_ERROR);SidebarConfigMigrationService::apply($pdo,true);
    checkCompat(json_encode($read(),JSON_THROW_ON_ERROR)===$snapshot,'Repeated migration does not duplicate grants');
    $write->execute(['{invalid']);SidebarConfigMigrationService::apply($pdo,false);
    checkCompat($pdo->query("SELECT key_value FROM system_config WHERE key_name='ROLE_SIDEBAR_PAGES_JSON'")->fetchColumn()==='{invalid','062 does not replace bad settings');
    SidebarConfigMigrationService::apply($pdo,true);
    $v=$read();checkCompat(in_array('balances',$v['ChinaAdmin'],true)&&in_array('suppliers',$v['FieldStaff'],true),'065 installs valid defaults');
    foreach(['062_balance_sidebar_defaults.sql','065_balances_deployment_hardening.sql'] as $file){
        $sql=preg_replace('/^\s*--.*$/m','',file_get_contents($dir.$file));
        foreach(array_filter(array_map('trim',explode(';',$sql))) as $statement){$result=$pdo->query($statement.';');if($result instanceof PDOStatement){$result->fetchAll();$result->closeCursor();}}
    }
    checkCompat(true,'Portable sidebar SQL applied without JSON functions');
} finally { $write->execute([$old]); }
echo "PASS: $assertions legacy migration and sidebar checks (disposable schema)\n";
