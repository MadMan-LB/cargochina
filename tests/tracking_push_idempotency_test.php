<?php
// Real finalized/received fixtures; simulated transport cannot contact customers.
define('CLMS_TRACKING_FIXTURES_ONLY',true);require __DIR__.'/tracking_domain_test.php';

try {
 $f=trackingFixture($pdo);$id=$f['drafts'][0];$svc=new TrackingTransportFake($pdo);
 $svc->responses=[trackingResponse(200,'{"success":true,"external_shipment_id":"accepted-qa"}')];
 $first=$svc->push($id);$second=$svc->push($id);
 assignmentAssert($first['success']&&$second['success']&&count($svc->calls)===1,'Accepted retry resent its payload');
 $s=$pdo->prepare("SELECT COUNT(*) FROM tracking_push_log WHERE entity_type='shipment_draft' AND entity_id=?");$s->execute([$id]);assignmentAssert((int)$s->fetchColumn()===1,'Retry duplicated tracking ledger');
 echo "PASS: accepted tracking replay sends once and keeps one ledger row; disposable fixture\n";
} finally {if($pdo->inTransaction())$pdo->rollBack();}
