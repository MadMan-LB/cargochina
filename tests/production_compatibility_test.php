<?php
require_once dirname(__DIR__).'/backend/config/database.php';
require_once __DIR__.'/support/legacy_no_json_pdo.php';
require_once dirname(__DIR__).'/backend/services/AuditReplayLookupService.php';
require_once dirname(__DIR__).'/backend/services/ShipmentWriteService.php';
require_once dirname(__DIR__).'/backend/services/SupplierPaymentService.php';
require_once dirname(__DIR__).'/backend/api/helpers.php';
$pdo=getDb();
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='clms_hardening_20260919')throw new RuntimeException('Disposable schema required');
$legacy=new LegacyNoJsonPDO($pdo);
$assertions=0;
function compatAssert(bool $ok,string $reason):void{global $assertions;if(!$ok)throw new RuntimeException($reason);$assertions++;}
$key='compat_'.bin2hex(random_bytes(7));
$neighbor=str_replace('_','x',$key);
$insert=$pdo->prepare("INSERT INTO audit_log(entity_type,entity_id,action,new_value,user_id) VALUES (?,?,'create',?,1)");
$insert->execute(['expense',920,json_encode(['idempotency_key'=>$neighbor,'request_hash'=>'wrong'])]);
$insert->execute(['expense',921,'{broken']);
$insert->execute(['expense',921,json_encode(['nested'=>['idempotency_key'=>$key]])]);
$insert->execute(['expense',922,json_encode(['idempotency_key'=>$key,'request_hash'=>'correct'])]);
$insert->execute(['other',923,json_encode(['idempotency_key'=>$key])]);
$found=AuditReplayLookupService::find($legacy,'expense',$key);
compatAssert((int)($found['entity_id']??0)===922,'Exact idempotency key must survive SQL LIKE wildcard and malformed neighbors');
compatAssert(AuditReplayLookupService::find($legacy,'expense','missing_'.bin2hex(random_bytes(4)))===null,'Unknown request key');
$pdo->beginTransaction();
compatAssert((int)AuditReplayLookupService::find($legacy,'expense',$key,true)['entity_id']===922,'Locked audit replay lookup');
$pdo->rollBack();
$shipmentKey='shipment-'.bin2hex(random_bytes(9));
$created=ShipmentWriteService::create($pdo,['idempotency_key'=>$shipmentKey],1);
$replayed=ShipmentWriteService::create($pdo,['idempotency_key'=>$shipmentKey],1);
compatAssert((int)$created['id']===(int)$replayed['id']&&!empty($replayed['already_applied']),'Shipment creation replay preserves one draft');
$customer=(int)$pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn();
$supplier=(int)$pdo->query('SELECT id FROM suppliers ORDER BY id LIMIT 1')->fetchColumn();
$pdo->prepare("INSERT INTO orders(customer_id,status,created_by) VALUES (?,'Draft',1)")->execute([$customer]);$order=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO order_items(order_id,quantity,unit,declared_cbm,declared_weight,shared_carton_contents) VALUES (?,1,'pieces',1,10,?)")
    ->execute([$order,json_encode([['supplier_id'=>$supplier,'description_en'=>'Bamboo serving trays']])]);
$pdo->beginTransaction();
SupplierPaymentService::lockPartyAndOrder($legacy,$supplier,$order);
compatAssert($pdo->inTransaction(),'Shared-carton supplier link validates without JSON SQL');
$pdo->rollBack();
$files=[
    'backend/services/OperationReplayService.php',
    'backend/services/ShipmentWriteService.php',
    'backend/services/SupplierPaymentService.php',
    'backend/api/handlers/procurement-drafts.php',
    'backend/api/handlers/balances.php',
];
foreach($files as $file)compatAssert(!preg_match('/\bJSON_(?:EXTRACT|UNQUOTE|SEARCH|CONTAINS|VALID|ARRAY|OBJECT|QUOTE|SET)\s*\(/i',file_get_contents(dirname(__DIR__).'/'.$file)),'Unguarded MySQL JSON SQL in '.$file);
// Guard future endpoints: only these legacy-tested helpers may construct native
// JSON SQL, and each must select its PHP fallback on MySQL 5.5.
$nativeJsonSql='/\bJSON_(?:EXTRACT|UNQUOTE|SEARCH|CONTAINS|VALID|ARRAY|OBJECT|QUOTE|SET)\s*\(/i';
$allowed=['backend/api/helpers.php','backend/services/PackingListItemNumber.php','backend/services/UploadAccessService.php'];
foreach (['backend/api','backend/services'] as $directory) {
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__).'/'.$directory));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension()!=='php') continue;
        $relative=str_replace('\\','/',substr($file->getPathname(),strlen(dirname(__DIR__))+1));
        if (preg_match($nativeJsonSql,file_get_contents($file->getPathname()))) {
            compatAssert(in_array($relative,$allowed,true),'New unguarded JSON SQL in '.$relative);
        }
    }
}
foreach(glob(dirname(__DIR__).'/backend/migrations/*.sql') as $file) {
    $name=basename($file);if ($name==='031_seed_dummy_data.sql') continue; // Explicitly disabled in production.
    $sql=preg_replace('/^\s*--.*$/m','',file_get_contents($file));
    if(in_array($name,['084_release_policy_controls.sql','085_owner_controls.sql'],true))continue; // CHECK clauses need live 5.5 verification.
    compatAssert(!preg_match($nativeJsonSql,$sql),'JSON SQL in production migration '.$name);
    compatAssert(!preg_match('/\b(?:ADD|MODIFY)\s+COLUMN\s+\w+\s+JSON\b|^\s*\w+\s+JSON\s*[,)]/mi',$sql),'Native JSON column in migration '.$name);
}
echo "PASS: $assertions production SQL compatibility checks (local database, MySQL 5.5 rejecting adapter)\n";
if (($argv[1] ?? '') === '--baseline') {
    echo json_encode($pdo->query('SELECT VERSION() AS db_version, @@sql_mode AS sql_mode, @@character_set_server AS charset, @@collation_server AS collation, @@default_storage_engine AS engine, @@tx_isolation AS isolation_level, @@time_zone AS time_zone')->fetch(PDO::FETCH_ASSOC),JSON_UNESCAPED_UNICODE),"\n";
    echo 'PHP '.PHP_VERSION.' | PDO client '.$pdo->getAttribute(PDO::ATTR_CLIENT_VERSION)."\n";
}
