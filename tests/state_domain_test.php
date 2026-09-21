<?php
define('CLMS_ASSIGNMENT_FIXTURES_ONLY',true);
require __DIR__.'/assignment_domain_test.php';
require_once dirname(__DIR__).'/backend/services/CargoStateService.php';
require_once dirname(__DIR__).'/backend/services/OrderStateService.php';
require_once dirname(__DIR__).'/backend/services/OrderReceiptWorkflowService.php';
require_once dirname(__DIR__).'/backend/services/TrackingPushService.php';
assignmentAssert($pdo->query('SELECT DATABASE()')->fetchColumn()==='clms_hardening_20260919','State fixtures require isolated database');
$config=require dirname(__DIR__).'/backend/config/config.php';
assignmentAssert(empty($config['tracking_push_enabled']) && $config['notification_channels']===['dashboard'],'State QA forbids external delivery');
if(($argv[1]??'')==='--ui'){
    $f=assignmentFixture($pdo);
    foreach($f['containers'] as $id)$pdo->prepare('UPDATE containers SET code=? WHERE id=?')->execute(['SG-BEY-STATE-'.$id,$id]);
    echo json_encode($f);exit;
}
if(in_array($argv[1]??'',['--probe','--worker'],true)){
    if(session_status()===PHP_SESSION_NONE)session_start();$_SESSION=['user_id'=>1,'user_roles'=>['SuperAdmin']];
    $probe=$argv[1]==='--probe';if($probe)$pdo->beginTransaction();
    $f=$probe?assignmentFixture($pdo):json_decode($argv[3],true);$case=$argv[2];
    if($probe)register_shutdown_function(static function()use($pdo,$f){
        $state=['draft'=>$pdo->query('SELECT status FROM shipment_drafts WHERE id='.$f['drafts'][0])->fetchColumn(),'order'=>$pdo->query('SELECT status FROM orders WHERE id='.$f['orders'][0])->fetchColumn(),'container'=>$pdo->query('SELECT status FROM containers WHERE id='.$f['containers'][0])->fetchColumn(),'finalizations'=>(int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE entity_type='shipment_draft' AND action='finalize' AND entity_id=".$f['drafts'][0])->fetchColumn(),'tracking'=>(int)$pdo->query('SELECT COUNT(*) FROM tracking_push_log WHERE entity_id='.$f['drafts'][0])->fetchColumn()];
        if($pdo->inTransaction())$pdo->rollBack();echo "\n__STATE__".json_encode($state);
    });
    $containers=require dirname(__DIR__).'/backend/api/handlers/containers.php';$drafts=require dirname(__DIR__).'/backend/api/handlers/shipment-drafts.php';
    require_once __DIR__.'/support/container_test_handler.php';$containers=containerTestHandler($pdo,$containers);
    if(in_array($case,['retry','refs','document','remove-document','backward','depart-complete','locked-capacity','sibling'],true)){
        $pdo->prepare("UPDATE shipment_drafts SET status='finalized' WHERE id=?")->execute([$f['drafts'][0]]);
        $pdo->prepare("UPDATE orders SET status='FinalizedAndPushedToTracking' WHERE id=?")->execute([$f['orders'][0]]);
    }
    try{
        if(in_array($case,['finalize','retry','race','fault'],true))$drafts('POST',(string)$f['drafts'][0],'finalize',[]);
        if($case==='refs')$drafts('PUT',(string)$f['drafts'][0],null,['booking_number'=>'AMENDED']);
        if($case==='document')$drafts('POST',(string)$f['drafts'][0],'documents',['file_path'=>'uploads/not-a-document.pdf']);
        if($case==='remove-document')$drafts('POST',(string)$f['drafts'][0],'remove-document',['document_id'=>1]);
        if($case==='locked-capacity')$containers('PUT',(string)$f['containers'][0],null,['max_cbm'=>3]);
        if($case==='backward'){$pdo->prepare("UPDATE containers SET status='arrived' WHERE id=?")->execute([$f['containers'][0]]);$containers('PUT',(string)$f['containers'][0],null,['status'=>'planning']);}
        if($case==='create-skipped')$containers('POST',null,null,['code'=>'SG-INVALID-STATE','max_cbm'=>1,'max_weight'=>100,'status'=>'arrived']);
        if(in_array($case,['depart-incomplete','depart-complete'],true)){$pdo->prepare("UPDATE containers SET status='to_go' WHERE id=?")->execute([$f['containers'][0]]);$containers('PUT',(string)$f['containers'][0],null,['status'=>'on_route']);}
        if($case==='sibling'){
            $pdo->prepare('UPDATE shipment_drafts SET container_id=? WHERE id=?')->execute([$f['containers'][0],$f['drafts'][1]]);
            $pdo->prepare('INSERT INTO shipment_draft_orders(shipment_draft_id,order_id) VALUES (?,?)')->execute([$f['drafts'][1],$f['orders'][1]]);
            $pdo->prepare("UPDATE orders SET status='AssignedToContainer' WHERE id=?")->execute([$f['orders'][1]]);
            $drafts('POST',(string)$f['drafts'][1],'finalize',[]);
        }
        if(in_array($case,['partial','pending'],true)){
            if($case==='partial')$pdo->prepare('UPDATE warehouse_receipt_items SET actual_quantity=5 WHERE receipt_id=?')->execute([$f['receipts'][0]]);
            else $pdo->prepare("UPDATE orders SET confirmation_token='unresolved-feedback' WHERE id=?")->execute([$f['orders'][0]]);
            $drafts('POST',(string)$f['drafts'][0],'finalize',[]);
        }
        if($case==='accept-partial'){
            $pdo->prepare("UPDATE orders SET status='Confirmed',confirmation_token='unresolved-feedback' WHERE id=?")->execute([$f['orders'][1]]);
            $pdo->prepare('UPDATE warehouse_receipt_items SET actual_quantity=5 WHERE receipt_id=?')->execute([$f['receipts'][1]]);
            OrderReceiptWorkflowService::acceptAutoConfirmedOrder($pdo,$f['orders'][1],1);
            jsonResponse(['data'=>['accepted'=>true]]);
        }
        if($case==='stale-token'){
            $pdo->prepare("UPDATE orders SET status='Confirmed',confirmation_token='replacement-token' WHERE id=?")->execute([$f['orders'][1]]);
            OrderReceiptWorkflowService::acceptAutoConfirmedOrder($pdo,$f['orders'][1],null,'confirm_by_token','prior-token');
            jsonResponse(['data'=>['accepted'=>true]]);
        }
        if($case==='review-cumulative'){
            $pdo->prepare("UPDATE orders SET status='Confirmed',confirmation_token='review-cumulative' WHERE id=?")->execute([$f['orders'][1]]);
            $pdo->prepare('UPDATE warehouse_receipts SET actual_cbm=.1,actual_weight=10,actual_cartons=1 WHERE id=?')->execute([$f['receipts'][1]]);
            $pdo->prepare('UPDATE warehouse_receipt_items SET actual_quantity=5,actual_cbm=.1,actual_weight=10,actual_cartons=1 WHERE receipt_id=?')->execute([$f['receipts'][1]]);
            $pdo->prepare("INSERT INTO warehouse_receipts(order_id,actual_cbm,actual_weight,actual_cartons,receipt_condition,received_by) VALUES (?,.1,10,1,'good',1)")->execute([$f['orders'][1]]);$receipt=(int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO warehouse_receipt_items(receipt_id,order_item_id,actual_quantity,actual_cbm,actual_weight,actual_cartons,receipt_condition) VALUES (?,?,5,.1,10,1,'good')")->execute([$receipt,$f['items'][1]]);
            $_GET=['token'=>'review-cumulative'];$handler=require dirname(__DIR__).'/backend/api/handlers/confirm.php';$handler('GET',null,null,[]);
        }
        if(in_array($case,['push-draft','push'],true)){
            if($pdo->inTransaction())$pdo->rollBack();
            // A committed worker fixture is used for service-level preconditions.
            (new TrackingPushService($pdo))->push($f['drafts'][0]);jsonResponse(['data'=>['pushed'=>true]]);
        }
        if($case==='submit'){
            $handler=require dirname(__DIR__).'/backend/api/handlers/orders.php';$handler('POST',(string)$f['orders'][1],'submit',[]);
        }
    }catch(Throwable $e){jsonError($e->getMessage(),409);}
    throw new RuntimeException('Unknown state case');
}
function stateStart(array $args):array{$pipes=[];$p=proc_open(array_merge([PHP_BINARY,__FILE__],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);return [$p,$pipes];}
function stateFinish(array $worker):array{[$p,$pipes]=$worker;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);assignmentAssert($code===0 && $err==='',"State probe failure: $err $out");$parts=explode("\n__STATE__",$out);$result=json_decode($parts[0],true);assignmentAssert(is_array($result),'Invalid state response: '.$out);return [$result,isset($parts[1])?json_decode($parts[1],true):null];}
foreach(['finalize','retry','refs','document','remove-document','locked-capacity','backward','create-skipped','depart-incomplete','depart-complete','sibling','partial','pending','accept-partial','stale-token'] as $case){
    [$result,$state]=stateFinish(stateStart(['--probe',$case]));$reject=!in_array($case,['finalize','retry','depart-complete','sibling'],true);
    assignmentAssert(!empty($result['error'])===$reject,"Unexpected $case response: ".json_encode($result));
    if($case==='finalize')assignmentAssert($state['draft']==='finalized' && $state['order']==='FinalizedAndPushedToTracking' && $state['finalizations']===1 && $state['tracking']===1,'Finalization must atomically persist delivery snapshot');
    if($case==='retry')assignmentAssert(!empty($result['data']['already_applied']) && $state['finalizations']===0,'Finalization retry repeated side effects');
    if(in_array($case,['partial','pending'],true))assignmentAssert($state['draft']==='draft' && $state['finalizations']===0,'Invalid finalization changed state');
    echo "PASS: state $case\n";
}
[$review]=stateFinish(stateStart(['--probe','review-cumulative']));assignmentAssert((float)$review['data']['actual_cbm']===.2 && (float)$review['data']['actual_weight']===20.0 && (float)$review['data']['received_quantity']===10.0,'Customer review differs from cumulative acceptance');
echo "PASS: customer confirmation review uses cumulative physical totals\n";
$f=assignmentFixture($pdo);$a=stateStart(['--worker','race',json_encode($f)]);$b=stateStart(['--worker','race',json_encode($f)]);[$ra]=stateFinish($a);[$rb]=stateFinish($b);
assignmentAssert(empty($ra['error']) && empty($rb['error']),'Concurrent finalize failed: '.json_encode([$ra,$rb]));
assignmentAssert((int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE entity_type='shipment_draft' AND action='finalize' AND entity_id=".$f['drafts'][0])->fetchColumn()===1,'Concurrent duplicate finalization');
assignmentAssert($pdo->query('SELECT status FROM tracking_push_log WHERE entity_id='.$f['drafts'][0])->fetchColumn()==='disabled','QA must not send tracking');
echo "PASS: concurrent finalization is repeatable with one audit and disabled tracking\n";
$f=assignmentFixture($pdo);$created=false;
try{
    $pdo->exec("CREATE TRIGGER qa_finalize_rollback BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.entity_type='shipment_draft' AND NEW.action='finalize' AND NEW.entity_id=".$f['drafts'][0]." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected finalization audit failure'; END IF; END");$created=true;
    [$result]=stateFinish(stateStart(['--worker','fault',json_encode($f)]));assignmentAssert(!empty($result['error']),'Fault not reproduced');
    assignmentAssert($pdo->query('SELECT status FROM shipment_drafts WHERE id='.$f['drafts'][0])->fetchColumn()==='draft','Failed finalization committed');
    assignmentAssert($pdo->query('SELECT status FROM orders WHERE id='.$f['orders'][0])->fetchColumn()==='AssignedToContainer','Failed finalization changed order');
    assignmentAssert((int)$pdo->query('SELECT COUNT(*) FROM tracking_push_log WHERE entity_id='.$f['drafts'][0])->fetchColumn()===0,'Failed finalization dispatched tracking');
    echo "PASS: finalization audit failure rolls back order, draft and delivery\n";
}finally{if($created)$pdo->exec('DROP TRIGGER qa_finalize_rollback');}
[$result]=stateFinish(stateStart(['--worker','push-draft',json_encode($f)]));assignmentAssert(!empty($result['error']) && str_contains($result['message'],'must be finalized'),'Tracking service accepted draft');
$f=assignmentFixture($pdo);$pdo->prepare("UPDATE orders SET status='Draft',order_type='draft_procurement' WHERE id=?")->execute([$f['orders'][1]]);$pdo->prepare('DELETE FROM warehouse_receipt_items WHERE receipt_id=?')->execute([$f['receipts'][1]]);$pdo->prepare('DELETE FROM warehouse_receipts WHERE id=?')->execute([$f['receipts'][1]]);
$a=stateStart(['--worker','submit',json_encode($f)]);$b=stateStart(['--worker','submit',json_encode($f)]);[$ra]=stateFinish($a);[$rb]=stateFinish($b);
assignmentAssert(empty($ra['error']) && empty($rb['error']),'Concurrent submit failed');
assignmentAssert((int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE entity_type='order' AND action='submit' AND entity_id=".$f['orders'][1])->fetchColumn()===1,'Concurrent duplicate submission');
echo "PASS: concurrent submission is repeatable and tracking rejects unfinalized drafts\n";
$f=assignmentFixture($pdo);$pdo->prepare("UPDATE shipment_drafts SET status='finalized' WHERE id=?")->execute([$f['drafts'][0]]);
$lockName='clms-push-'.substr(hash('sha256',(string)$pdo->query('SELECT DATABASE()')->fetchColumn()),0,16).'-'.$f['drafts'][0];
$lock=$pdo->prepare('SELECT GET_LOCK(?,0)');$lock->execute([$lockName]);assignmentAssert((int)$lock->fetchColumn()===1,'Could not acquire delivery claim');
try{[$busy]=stateFinish(stateStart(['--worker','push',json_encode($f)]));assignmentAssert(!empty($busy['error']) && str_contains($busy['message'],'already in progress'),'Concurrent tracking request bypassed claim');}
finally{$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);}
// The push endpoint helper returns only after the service; use a service call for a safe disabled delivery.
$tracking=new TrackingPushService($pdo);$disabled=$tracking->push($f['drafts'][0]);assignmentAssert($disabled['status']==='disabled','Unexpected tracking delivery');
$pdo->prepare("UPDATE tracking_push_log SET status='success',external_id='recorded-remote-acceptance' WHERE entity_id=?")->execute([$f['drafts'][0]]);
$replayed=$tracking->push($f['drafts'][0]);assignmentAssert($replayed['success']===true && $replayed['external_id']==='recorded-remote-acceptance','Successful tracking state was downgraded');
echo "PASS: tracking claim serializes retries and success is terminal\n";
assignmentAssert(OrderStateService::canTransition('Approved','Confirmed') && OrderStateService::canTransition('AssignedToContainer','ReadyForConsolidation') && !OrderStateService::canTransition('FinalizedAndPushedToTracking','Submitted'),'Order graph disagrees with receipt/reservation lifecycle');
