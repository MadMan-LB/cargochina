<?php
require_once dirname(__DIR__).'/backend/config/database.php';
require_once dirname(__DIR__).'/backend/services/CatalogRevisionService.php';
require_once dirname(__DIR__).'/backend/services/SupplierDeletionService.php';
require_once __DIR__.'/support/legacy_no_json_pdo.php';
$pdo = getDb();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'clms_hardening_20260919') throw new RuntimeException('Disposable schema required');
if (($argv[1] ?? '') === '--worker') {
    session_start(); $request = json_decode($argv[2], true);
    $_SESSION = ['user_id'=>1, 'user_roles'=>['SuperAdmin']];
    if (!empty($request['reader'])) $_SESSION=['user_id'=>$request['reader'], 'user_roles'=>[]];
    $handler = require dirname(__DIR__).'/backend/api/handlers/suppliers.php';
    try { $handler($request['method'] ?? 'DELETE', (string)$request['id'], null, ['revision'=>$request['revision'] ?? '']); }
    catch (Throwable $e) { jsonError($e->getMessage(),500); }
    exit;
}
$count = 0;
function verify(bool $condition, string $message): void { global $count; if (!$condition) throw new RuntimeException($message); $count++; }
function workerStart(array $request): array {
    $pipes=[]; $process=proc_open([PHP_BINARY,__FILE__,'--worker',json_encode($request)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]); return [$process,$pipes];
}
function workerFinish(array $worker): array {
    [$process,$pipes]=$worker; $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]);fclose($pipes[2]);
    if (proc_close($process)!==0 || $err!=='') throw new RuntimeException($err.$out);
    $json=json_decode($out,true); if (!is_array($json)) throw new RuntimeException($out); return $json;
}
function callSupplier(array $request): array { return workerFinish(workerStart($request)); }
function supplierFixture(PDO $pdo): int {
    $pdo->prepare('INSERT INTO suppliers(code,name) VALUES (?,?)')->execute(['DEL-'.bin2hex(random_bytes(5)),'Supplier deletion regression']); return (int)$pdo->lastInsertId();
}
function existsSupplier(PDO $pdo, int $id): bool { $s=$pdo->prepare('SELECT 1 FROM suppliers WHERE id=?');$s->execute([$id]);return (bool)$s->fetchColumn(); }
$customer=(int)$pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn();
$pdo->prepare("INSERT INTO orders(customer_id,status,created_by) VALUES (?,'Draft',1)")->execute([$customer]);$order=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO order_templates(name) VALUES ('Supplier deletion regression')");$template=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO expense_categories(code,name) VALUES (?, 'Supplier deletion regression')")->execute(['DEL-'.bin2hex(random_bytes(5))]);$category=(int)$pdo->lastInsertId();
$fixtures = [
    'orders'=>['INSERT INTO orders(customer_id,supplier_id,status,created_by) VALUES (?, ?, \'Confirmed\',1)', [$customer]],
    'products'=>['INSERT INTO products(supplier_id) VALUES (?)', []],
    'order_template_items'=>['INSERT INTO order_template_items(template_id,supplier_id) VALUES (?,?)', [$template]],
    'expenses'=>["INSERT INTO expenses(category_id,amount,expense_date,supplier_id) VALUES (?,5,'2026-09-22',?)", [$category]],
    'order_items'=>["INSERT INTO order_items(order_id,quantity,unit,declared_cbm,declared_weight,supplier_id) VALUES (?,1,'pieces',1,10,?)", [$order]],
    'supplier_payments'=>["INSERT INTO supplier_payments(amount,currency,supplier_id) VALUES (25,'USD',?)", []],
    'supplier_interactions'=>["INSERT INTO supplier_interactions(interaction_type,supplier_id) VALUES ('note',?)", []],
    'procurement_drafts'=>["INSERT INTO procurement_drafts(name,supplier_id) VALUES ('Supplier deletion regression',?)", []],
    'draft_order_costs'=>["INSERT INTO draft_order_costs(order_id,cost_type_code,amount,currency,base_currency,base_amount,supplier_id) VALUES (?,'other',5,'USD','USD',5,?)", [$order]],
    'item_number_reservations'=>["INSERT INTO item_number_reservations(customer_id,display_item_no,normalized_item_no,supplier_id) VALUES (?,?,?,?)", [$customer,$reservation='DEL-'.bin2hex(random_bytes(6)),$reservation]],
];
foreach ($fixtures as $table=>[$sql,$params]) {
    $id=supplierFixture($pdo);$params[]=$id;$pdo->prepare($sql)->execute($params);$linkedId=(int)$pdo->lastInsertId();
    if ($table==='orders') {
        $pdo->beginTransaction();
        try { $pdo->prepare('DELETE FROM suppliers WHERE id=?')->execute([$id]); throw new RuntimeException('Expected restrictive FK'); }
        catch (PDOException $e) { verify((int)$e->errorInfo[1]===1451,'Reproduce original foreign-key failure'); }
        finally { $pdo->rollBack(); }
    }
    $pdo->beginTransaction();verify(SupplierDeletionService::blockingReference(new LegacyNoJsonPDO($pdo),$id)!==null,"Legacy guard: $table");$pdo->rollBack();
    $result=callSupplier(['id'=>$id,'revision'=>CatalogRevisionService::revision($pdo,'suppliers',$id)]);
    verify(!empty($result['error']) && str_contains($result['message'],'Cannot delete this supplier:'),"Readable conflict: $table");
    verify(existsSupplier($pdo,$id),"Supplier preserved: $table");
    $s=$pdo->prepare("SELECT supplier_id FROM `$table` WHERE id=?");$s->execute([$linkedId]);verify((int)$s->fetchColumn()===$id,"Link preserved: $table");
}
$id=supplierFixture($pdo);
$pdo->prepare("INSERT INTO design_attachments(entity_type,entity_id,file_path) VALUES ('supplier',?,'uploads/deletion-regression.pdf')")->execute([$id]);
$result=callSupplier(['id'=>$id,'revision'=>CatalogRevisionService::revision($pdo,'suppliers',$id)]);
verify(!empty($result['error'])&&str_contains($result['message'],'supplier attachments')&&existsSupplier($pdo,$id),'Supplier attachments protected');
$id=supplierFixture($pdo);
$pdo->prepare("INSERT INTO order_items(order_id,quantity,unit,declared_cbm,declared_weight,shared_carton_contents) VALUES (?,1,'pieces',1,10,?)")->execute([$order,json_encode([['supplier_id'=>(string)$id,'description_en'=>'Bamboo tray']])]);
$result=callSupplier(['id'=>$id,'revision'=>CatalogRevisionService::revision($pdo,'suppliers',$id)]);
verify(!empty($result['error'])&&str_contains($result['message'],'shared-carton'),'Shared-only supplier protected');
$id=supplierFixture($pdo);$revision=CatalogRevisionService::revision($pdo,'suppliers',$id);
$result=callSupplier(['id'=>$id,'revision'=>'stale']);verify(!empty($result['error'])&&existsSupplier($pdo,$id),'Stale deletion rejected');
$pdo->prepare("INSERT INTO users(email,password_hash,full_name,is_active) VALUES (?,'unusable','Read-only regression',1)")->execute(['delete-'.bin2hex(random_bytes(5)).'@example.invalid']);$reader=(int)$pdo->lastInsertId();
$result=callSupplier(['id'=>$id,'revision'=>$revision,'reader'=>$reader]);verify(!empty($result['error'])&&existsSupplier($pdo,$id),'Unauthorized deletion rejected');
$pdo->exec("CREATE TRIGGER qa_supplier_delete_audit BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.entity_type='supplier' AND NEW.action='delete' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected audit failure'; END IF; END");
try { $result=callSupplier(['id'=>$id,'revision'=>$revision]);verify(!empty($result['error'])&&existsSupplier($pdo,$id),'Audit failure rolls back deletion'); }
finally { $pdo->exec('DROP TRIGGER qa_supplier_delete_audit'); }
$request=['id'=>$id,'revision'=>$revision];$a=workerStart($request);$b=workerStart($request);$results=[workerFinish($a),workerFinish($b)];
verify(count(array_filter($results,fn($r)=>empty($r['error'])))===1&&!existsSupplier($pdo,$id),'Concurrent delete commits once');
$s=$pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE entity_type='supplier' AND entity_id=? AND action='delete'");$s->execute([$id]);verify((int)$s->fetchColumn()===1,'One deletion audit event');
verify(!empty(callSupplier(['method'=>'GET','id'=>$id])['error']),'Reopen confirms deletion');
echo "PASS: $count supplier deletion checks (disposable schema)\n";
