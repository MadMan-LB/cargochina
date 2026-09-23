<?php
require_once dirname(__DIR__).'/backend/config/database.php';$p=getDb();
if($p->query('SELECT DATABASE()')->fetchColumn()!=='clms_hardening_20260919')throw new RuntimeException('Disposable schema required');
// Synthetic old audit events measure the range access path without real business data.
$p->beginTransaction();$s=$p->prepare("INSERT INTO audit_log(entity_type,entity_id,action,created_at,new_value,user_id) VALUES ('order',0,'approve','2010-01-01','{}',1)");for($i=0;$i<5000;$i++)$s->execute();$p->commit();
$query="SELECT a.id,a.entity_id,a.action,a.created_at,a.new_value FROM audit_log a WHERE a.entity_type='order' AND a.action IN ('approve','receive') AND a.created_at>='2026-09-01' AND a.created_at<'2026-10-01' LIMIT 2001";
$measure=static function()use($p,$query){$plan=$p->query('EXPLAIN '.$query)->fetch(PDO::FETCH_ASSOC);$start=microtime(true);for($i=0;$i<30;$i++)$p->query($query)->fetchAll();return ['key'=>$plan['key'],'access'=>$plan['type'],'estimated_rows'=>$plan['rows'],'mean_ms'=>round((microtime(true)-$start)*1000/30,3)];};
$before=$measure();
$sql=preg_replace('/^\s*--.*$/m','',file_get_contents(dirname(__DIR__).'/backend/migrations/088_calendar_range_indexes.sql'));
for($run=0;$run<2;$run++)foreach(array_filter(array_map('trim',explode(';',$sql))) as $q){$r=$p->query($q);if($r){do{$r->fetchAll();}while($r->nextRowset());$r->closeCursor();}}
$after=$measure();
if($after['key']!=='idx_calendar_event')throw new RuntimeException('Calendar audit range index not used');
$p->exec("DELETE FROM audit_log WHERE entity_type='order' AND entity_id=0 AND action='approve' AND created_at='2010-01-01' AND new_value='{}'");
echo 'PASS calendar range index + idempotent migration; before='.json_encode($before).' after='.json_encode($after).PHP_EOL;
