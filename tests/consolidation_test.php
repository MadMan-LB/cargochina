<?php
// Consolidation acceptance uses canonical received fixtures and never real delivery.
define('CLMS_TRACKING_FIXTURES_ONLY',true);require __DIR__.'/tracking_domain_test.php';
$pdo->beginTransaction();
try {
 $f=assignmentFixture($pdo);$svc=new TrackingTransportFake($pdo);$rejected=false;
 try{$svc->prepare($f['drafts'][0]);}catch(RuntimeException $e){$rejected=true;}
 assignmentAssert($rejected&&!$svc->calls,'Unfinalized draft reached transport');
 $pdo->prepare("UPDATE shipment_drafts SET status='finalized' WHERE id=?")->execute([$f['drafts'][1]]);$rejected=false;
 try{$svc->prepare($f['drafts'][1]);}catch(RuntimeException $e){$rejected=true;}
 assignmentAssert($rejected,'Empty finalized shipment produced a payload');
 $valid=trackingFixture($pdo);$snapshot=$svc->prepare($valid['drafts'][0]);$payload=json_decode($snapshot['request_payload'],true);
 assignmentAssert(count($payload['items'])===1&&!$svc->calls,'Valid snapshot did not preserve cargo or sent transport');
 echo "PASS: draft/empty shipment rejection and received-cargo snapshot generation without delivery\n";
}finally{if($pdo->inTransaction())$pdo->rollBack();}
