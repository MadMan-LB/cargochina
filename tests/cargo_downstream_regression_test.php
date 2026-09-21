<?php
/** All fixtures live inside rollback-only transactions. No tracking or notifications sent. */
require_once dirname(__DIR__) . '/backend/config/database.php';
require_once dirname(__DIR__) . '/backend/services/CargoMetricsService.php';
require_once dirname(__DIR__) . '/backend/services/ReceivingExcelImportService.php';
require_once dirname(__DIR__) . '/backend/services/OrderReceivingService.php';
require_once dirname(__DIR__) . '/backend/services/TrackingPushService.php';
require_once dirname(__DIR__) . '/backend/services/OrderReceiptWorkflowService.php';
$containers = require dirname(__DIR__) . '/backend/api/handlers/containers.php';
require_once __DIR__.'/support/container_test_handler.php';
$containers=containerTestHandler(getDb(),$containers);

function cargoAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function cargoNear($value, float $expected, string $name): void { cargoAssert(abs((float)$value-$expected)<0.000001, "$name: expected $expected, got $value"); }
function cargoFixture(PDO $pdo): array
{
    $customer = (int)$pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn();
    $supplier = (int)$pdo->query('SELECT id FROM suppliers ORDER BY id LIMIT 1')->fetchColumn();
    $user = (int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
    $ids = []; $items = [];
    foreach ([1.0, 0.2] as $cbm) {
        $pdo->prepare("INSERT INTO orders(customer_id,supplier_id,status,created_by) VALUES (?,?,'ReadyForConsolidation',?)")->execute([$customer,$supplier,$user]);
        $id=(int)$pdo->lastInsertId(); $ids[]=$id;
        $pdo->prepare("INSERT INTO order_items(order_id,cartons,qty_per_carton,quantity,unit,declared_cbm,declared_weight,unit_price,total_amount,description_en) VALUES (?,10,10,100,'pieces',?,100,2,200,'Stainless steel kitchen utensil sets')")->execute([$id,$cbm]);
        $items[]=(int)$pdo->lastInsertId();
    }
    foreach ([[0,3,.3,30,30,'partial'],[0,8,1.1,95,80,'good'],[1,5,.9,60,50,'good']] as [$index,$cartons,$cbm,$weight,$qty,$condition]) {
        $pdo->prepare('INSERT INTO warehouse_receipts(order_id,actual_cartons,actual_cbm,actual_weight,receipt_condition,received_by) VALUES (?,?,?,?,?,?)')->execute([$ids[$index],$cartons,$cbm,$weight,$condition,$user]);
        $receipt=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO warehouse_receipt_items(receipt_id,order_item_id,actual_cartons,actual_cbm,actual_weight,actual_quantity,receipt_condition) VALUES (?,?,?,?,?,?,?)')->execute([$receipt,$items[$index],$cartons,$cbm,$weight,$qty,$condition]);
    }
    $code='SG-BEY-REVIEW-'.bin2hex(random_bytes(4));
    $pdo->prepare("INSERT INTO containers(code,max_cbm,max_weight,status) VALUES (?,2,1000,'planning')")->execute([$code]); $container=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO shipment_drafts(status,container_id) VALUES ('draft',?)")->execute([$container]);$draft=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO shipment_draft_orders(shipment_draft_id,order_id) VALUES (?,?)')->execute([$draft,$ids[0]]);
    $pdo->prepare("UPDATE orders SET status='AssignedToContainer' WHERE id=?")->execute([$ids[0]]);
    $pdo->exec("INSERT INTO shipment_drafts(status) VALUES ('draft')");$other=(int)$pdo->lastInsertId();
    return compact('ids','items','user','container','draft','other','code','customer','supplier');
}

if (($argv[1]??'')==='--probe') {
    if(session_status()===PHP_SESSION_NONE)session_start();$_SESSION['user_id']=1;$_SESSION['user_roles']=['SuperAdmin'];
    $pdo=getDb();$pdo->beginTransaction();$f=cargoFixture($pdo);$case=$argv[2];
    // Assignment fixtures must be fully received; separate ledger cases below
    // deliberately exercise partial and inconsistent historical measurements.
    $pdo->prepare('UPDATE order_items SET quantity=50,cartons=5,total_amount=100 WHERE id=?')->execute([$f['items'][1]]);
    if($case==='container-xlsx')ob_start(static fn($data)=>base64_encode($data));
    register_shutdown_function(static function()use($pdo,$f,$case){
        $state=['http'=>http_response_code(), 'drafts'=>(int)$pdo->query('SELECT COUNT(*) FROM shipment_drafts WHERE container_id='.$f['container'])->fetchColumn(),
            'first_status'=>$pdo->query('SELECT status FROM orders WHERE id='.$f['ids'][0])->fetchColumn(),
            'second_status'=>$pdo->query('SELECT status FROM orders WHERE id='.$f['ids'][1])->fetchColumn(),
            'members'=>(int)$pdo->query('SELECT COUNT(*) FROM shipment_draft_orders WHERE shipment_draft_id='.$f['draft'])->fetchColumn()];
        if($pdo->inTransaction())$pdo->rollBack();echo "\n__STATE__".json_encode($state);
    });
    $drafts=require dirname(__DIR__).'/backend/api/handlers/shipment-drafts.php';
    if($case==='direct-over')$containers('POST',(string)$f['container'],'assign-orders',['order_ids'=>[$f['ids'][1]]]);
    if($case==='direct-empty-over'){
        $pdo->prepare('DELETE FROM shipment_draft_orders WHERE shipment_draft_id=?')->execute([$f['draft']]);
        $pdo->prepare('DELETE FROM shipment_drafts WHERE id=?')->execute([$f['draft']]);
        $pdo->prepare('UPDATE containers SET max_cbm=.5 WHERE id=?')->execute([$f['container']]);
        $containers('POST',(string)$f['container'],'assign-orders',['order_ids'=>[$f['ids'][1]]]);
    }
    if($case==='direct-valid'){$pdo->prepare('UPDATE containers SET max_cbm=3 WHERE id=?')->execute([$f['container']]);$containers('POST',(string)$f['container'],'assign-orders',['order_ids'=>[$f['ids'][1],$f['ids'][1]]]);}
    if($case==='draft-add-over')$drafts('POST',(string)$f['draft'],'add-orders',['order_ids'=>[$f['ids'][1]]]);
    if($case==='draft-assign-over'){
        $pdo->prepare('INSERT INTO shipment_draft_orders(shipment_draft_id,order_id) VALUES (?,?)')->execute([$f['other'],$f['ids'][1]]);
        $pdo->prepare("UPDATE orders SET status='ConsolidatedIntoShipmentDraft' WHERE id=?")->execute([$f['ids'][1]]);
        $drafts('POST',(string)$f['other'],'assign-container',['container_id'=>$f['container']]);
    }
    if($case==='remove-foreign')$drafts('POST',(string)$f['other'],'remove-orders',['order_ids'=>[$f['ids'][0]]]);
    if($case==='remove-valid')$drafts('POST',(string)$f['draft'],'remove-orders',['order_ids'=>[$f['ids'][0]]]);
    if($case==='delete-valid')$drafts('DELETE',(string)$f['draft'],null,[]);
    if($case==='delete-shared'){
        $pdo->prepare('INSERT INTO shipment_draft_orders(shipment_draft_id,order_id) VALUES (?,?)')->execute([$f['other'],$f['ids'][0]]);
        $drafts('DELETE',(string)$f['draft'],null,[]);
    }
    if(str_starts_with($case,'finalized-')){
        $pdo->prepare("UPDATE shipment_drafts SET status='finalized' WHERE id=?")->execute([$f['draft']]);
        $action=substr($case,10);$drafts($action==='delete'?'DELETE':'POST',(string)$f['draft'],$action==='delete'?null:$action,['order_ids'=>[$f['ids'][1]],'container_id'=>$f['container']]);
    }
    if($case==='container-list'){$_GET=['q'=>$f['code']];$containers('GET',null,null,[]);}
    if($case==='container-filter-almost'){$pdo->prepare('UPDATE containers SET max_cbm=1.5 WHERE id=?')->execute([$f['container']]);$_GET=['q'=>$f['code'],'fill'=>'almost'];$containers('GET',null,null,[]);}
    if($case==='container-edit-cbm')$containers('PUT',(string)$f['container'],null,['max_cbm'=>1.2]);
    if($case==='container-edit-weight')$containers('PUT',(string)$f['container'],null,['max_weight'=>120]);
    if($case==='container-orders')$containers('GET',(string)$f['container'],'orders',[]);
    if($case==='container-csv'){$pdo->prepare("UPDATE order_items SET description_en='=2+3' WHERE id=?")->execute([$f['items'][0]]);$_GET=['format'=>'csv'];$containers('GET',(string)$f['container'],'export',[]);}
    if($case==='container-xlsx'){$_GET=['format'=>'xlsx'];$containers('GET',(string)$f['container'],'export',[]);}
    throw new RuntimeException('Unknown probe');
}

$pdo=getDb();$baseline=[];
foreach(['orders','order_items','warehouse_receipts','warehouse_receipt_items','shipment_drafts','shipment_draft_orders','notifications']as$table)$baseline[$table]=(int)$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
$baselineChannels=$pdo->query("SELECT key_value FROM system_config WHERE key_name='NOTIFICATION_CHANNELS'")->fetchColumn();
$pdo->beginTransaction();
try {
    $f=cargoFixture($pdo);$totals=CargoMetricsService::totals($pdo,$f['ids']);
    foreach(['cbm'=>1.4,'weight'=>125,'cartons'=>11,'quantity'=>110]as$key=>$expected)cargoNear($totals[$f['ids'][0]][$key],$expected,'cumulative '.$key);
    $pdo->prepare('UPDATE warehouse_receipts SET actual_cbm=0,actual_weight=0,actual_cartons=0 WHERE order_id=?')->execute([$f['ids'][1]]);
    $pdo->prepare('UPDATE warehouse_receipt_items SET actual_cbm=0,actual_weight=0,actual_cartons=0,actual_quantity=0 WHERE order_item_id=?')->execute([$f['items'][1]]);
    $zero=CargoMetricsService::totals($pdo,[$f['ids'][1]])[$f['ids'][1]];
    foreach(['cbm','weight','cartons','quantity']as$key)cargoNear($zero[$key],0,'zero actual '.$key);
    $t=loadContainerOrderTotals($pdo,$f['ids'][0]);cargoNear($t['total_cbm'],1.4,'container CBM');cargoNear($t['total_ctns'],11,'container cartons');cargoNear($t['total_qty'],110,'container quantity');cargoNear($t['total_amount'],220,'sell-side amount');
    $pdo->prepare('INSERT INTO shipment_draft_orders(shipment_draft_id,order_id) VALUES (?,?)')->execute([$f['other'],$f['ids'][0]]);
    $pdo->prepare('UPDATE shipment_drafts SET container_id=? WHERE id=?')->execute([$f['container'],$f['other']]);
    cargoNear(fetchContainerUsage($pdo,$f['container'])['used_cbm'],1.4,'distinct container load');
    $stmt=$pdo->prepare('SELECT * FROM order_items WHERE id=?');$stmt->execute([$f['items'][0]]);$declared=$stmt->fetch(PDO::FETCH_ASSOC);
    $shipping=CargoMetricsService::shippingItems($pdo,[$declared])[0];cargoNear($shipping['declared_cbm'],1.4,'shipping export CBM');cargoNear($shipping['quantity'],110,'shipping export quantity');cargoNear($declared['declared_cbm'],1,'procurement preserved');
    $method=new ReflectionMethod(TrackingPushService::class,'buildPayload');
    $payload=$method->invoke(new TrackingPushService($pdo),$f['draft'],['id'=>$f['draft']],[$f['ids'][0]]);
    cargoNear($payload['items'][0]['declared_cbm'],1.4,'tracking actual CBM');cargoNear($payload['items'][0]['quantity'],110,'tracking actual quantity');
    $pdo->prepare('UPDATE warehouse_receipts SET receiving_operation_id=? WHERE order_id=? LIMIT 1')->execute(['review-cross-order-key',$f['ids'][0]]);
    try{(new OrderReceivingService())->receive($pdo,$f['ids'][1],['idempotency_key'=>'review-cross-order-key'],$f['user'],false);throw new RuntimeException('Cross-order replay accepted');}
    catch(OrderReceivingValidationException $e){cargoAssert($e->getStatusCode()===409,'Cross-order replay did not conflict');}
    $pdo->prepare('UPDATE warehouse_receipts SET voided_at=NOW() WHERE order_id=?')->execute([$f['ids'][0]]);
    cargoNear(CargoMetricsService::totals($pdo,[$f['ids'][0]])[$f['ids'][0]]['cbm'],1,'void exclusion with declared fallback');
    cargoAssert(CargoMetricsService::shippingItems($pdo,[$declared])[0]['quantity']===null,'Assigned order without active ledger requires reconciliation');
    $pdo->prepare("UPDATE orders SET status='Approved' WHERE id=?")->execute([$f['ids'][0]]);
    $custom=$declared;$custom['total_amount']=180;
    cargoNear(CargoMetricsService::shippingItems($pdo,[$custom])[0]['total_amount'],180,'original custom amount without actuals');
    $raw=['_row'=>2,'order_id'=>$f['ids'][0],'order_item_id'=>$f['items'][0],'actual_cartons'=>3,'actual_pieces_per_carton'=>10,'actual_quantity'=>30,'actual_cbm'=>.3,'actual_weight'=>30,'condition'=>'partial'];
    $import=new ReceivingExcelImportService();$preview=$import->validateRows($pdo,[$raw]);cargoAssert(empty($preview['errors']), 'Partial import rejected: '.json_encode($preview['errors']));
    $pdo->prepare('UPDATE warehouse_receipts SET voided_at=NULL WHERE order_id=? AND receipt_condition=?')->execute([$f['ids'][0],'partial']);
    $raw['condition']='good';$raw['actual_cbm']=.7;
    $preview=$import->validateRows($pdo,[$raw]);cargoAssert(empty($preview['errors']),'Cumulative completion import rejected: '.json_encode($preview['errors']));
    $direct=['_row'=>2,'customer_id'=>$f['customer'],'supplier_id'=>$f['supplier'],'actual_cartons'=>3,'actual_pieces_per_carton'=>10,'actual_quantity'=>30,'actual_cbm'=>.3,'actual_weight'=>30,'description_en'=>'Stainless steel kitchen utensil sets','condition'=>'good'];
    cargoAssert(empty($import->validateDirectIntakeRows($pdo,[$direct])['errors']),'Normal direct intake rejected');
    foreach(['partial','damaged']as$condition){$direct['condition']=$condition;cargoAssert(!empty($import->validateDirectIntakeRows($pdo,[$direct])['errors']),'Unsafe direct intake accepted '.$condition);}
    // Force dashboard-only delivery within this uncommitted transaction.
    $pdo->exec("UPDATE system_config SET key_value='dashboard' WHERE key_name='NOTIFICATION_CHANNELS'");
    $config=require dirname(__DIR__).'/backend/config/config.php';
    cargoAssert($config['notification_channels']===['dashboard'],'Unsafe external notification configuration for receiving test');
    $input=['idempotency_key'=>'review-complete-receipt','condition'=>'good','actual_cartons'=>7,'actual_cbm'=>.7,'actual_weight'=>70,
        'items'=>[['order_item_id'=>$f['items'][0],'actual_cartons'=>7,'actual_quantity'=>70,'actual_cbm'=>.7,'actual_weight'=>70]]];
    $service=new OrderReceivingService();$receipt=$service->receive($pdo,$f['ids'][0],$input,$f['user'],false);
    cargoAssert($receipt['status']==='ReadyForConsolidation' && !$receipt['variance_detected'],'Completion did not reconcile prior partial receipt');
    cargoNear(CargoMetricsService::totals($pdo,[$f['ids'][0]])[$f['ids'][0]]['cbm'],1,'received cumulative completion');
    $replay=$service->receive($pdo,$f['ids'][0],$input,$f['user'],false);cargoAssert(!empty($replay['idempotent_replay']),'Retry created a duplicate receipt');
    $pdo->prepare("UPDATE orders SET confirmation_token='review-pending',status='Confirmed' WHERE id=?")->execute([$f['ids'][0]]);
    OrderReceiptWorkflowService::acceptAutoConfirmedOrder($pdo,$f['ids'][0],$f['user']);
    $accepted=json_decode($pdo->query('SELECT accepted_actuals FROM customer_confirmations WHERE order_id='.$f['ids'][0].' ORDER BY id DESC LIMIT 1')->fetchColumn(),true);
    cargoNear($accepted['actual_cbm'],1,'customer accepted cumulative actuals');
    $pdo->prepare('UPDATE warehouse_receipts SET voided_at=NOW() WHERE order_id=?')->execute([$f['ids'][1]]);
    $pdo->prepare("UPDATE orders SET status='Approved' WHERE id=?")->execute([$f['ids'][1]]);
    $partial=$service->receive($pdo,$f['ids'][1],['idempotency_key'=>'review-partial-receipt','condition'=>'partial','actual_cartons'=>5,'actual_cbm'=>.1,'actual_weight'=>50,
        'items'=>[['order_item_id'=>$f['items'][1],'actual_cartons'=>5,'actual_quantity'=>50,'actual_cbm'=>.1,'actual_weight'=>50]]],$f['user'],false);
    cargoAssert($partial['status']==='InTransitToWarehouse' && !$partial['variance_detected'],'Partial delivery incorrectly released for assignment');
    cargoAssert((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE type='partial_order_received' AND target_id=".$f['ids'][1])->fetchColumn()>0,'Partial notification missing');
    $itemPartial=$service->receive($pdo,$f['ids'][1],['idempotency_key'=>'review-item-partial','condition'=>'good','actual_cartons'=>2,'actual_cbm'=>.05,'actual_weight'=>20,
        'items'=>[['order_item_id'=>$f['items'][1],'condition'=>'partial','actual_cartons'=>2,'actual_quantity'=>20,'actual_cbm'=>.05,'actual_weight'=>20]]],$f['user'],false);
    cargoAssert($itemPartial['status']==='InTransitToWarehouse','Partial item incorrectly released as complete');
    echo "PASS: cumulative cargo, quantity/amount, distinct membership, procurement preservation, void exclusion and partial/completion import\n";
} finally { if($pdo->inTransaction())$pdo->rollBack(); }

foreach(['direct-over','direct-empty-over','direct-valid','draft-add-over','draft-assign-over','remove-foreign','remove-valid','delete-valid','delete-shared','finalized-add-orders','finalized-remove-orders','finalized-assign-container','finalized-delete','container-list','container-filter-almost','container-edit-cbm','container-edit-weight','container-orders','container-csv','container-xlsx']as$case){
    $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --probe '.escapeshellarg($case);$lines=[];$exit=0;exec($command,$lines,$exit);cargoAssert($exit===0,'Probe failed: '.$case.' '.implode("\n",$lines));
    $output=implode("\n",$lines);if($case==='container-xlsx')$output=base64_decode($output);[$body,$stateJson]=explode("\n__STATE__",$output);$state=json_decode($stateJson,true);$json=json_decode($body,true);
    if(in_array($case,['direct-over','direct-empty-over','draft-add-over','draft-assign-over','container-edit-cbm','container-edit-weight'])||str_starts_with($case,'finalized-')){
        cargoAssert($state['http']>=400 && (!empty($json['error'])||!empty($json['over_capacity'])),'Expected rejection '.$case);cargoAssert($state['second_status']===($case==='draft-assign-over'?'ConsolidatedIntoShipmentDraft':'ReadyForConsolidation'),'Rejected action changed order '.$case);
        cargoAssert($state['drafts']===($case==='direct-empty-over'?0:1),'Rejected action left draft '.$case);
    }
    if($case==='direct-valid'){cargoNear($json['data']['used_cbm'],2.3,'assignment actual load');cargoAssert($json['data']['orders_added']===1,'Duplicate IDs counted twice');cargoAssert($state['members']===2,'Assignment not persisted within transaction');}
    if($case==='remove-foreign')cargoAssert($state['first_status']==='AssignedToContainer','Foreign removal changed order');
    if($case==='remove-valid'){cargoAssert($state['first_status']==='ReadyForConsolidation','Removal did not restore eligibility');cargoAssert($state['members']===0,'Removal did not remove membership');}
    if($case==='delete-valid')cargoAssert($state['first_status']==='ReadyForConsolidation' && $state['drafts']===0,'Draft deletion did not release cargo');
    if($case==='delete-shared')cargoAssert($state['first_status']==='ConsolidatedIntoShipmentDraft','Draft deletion corrupted remaining membership');
    if($case==='container-list')cargoNear($json['data'][0]['used_cbm'],1.4,'list received load');
    if($case==='container-filter-almost'){cargoAssert(count($json['data'])===1,'Capacity filter still used declared load');cargoNear($json['data'][0]['fill_pct_cbm'],93.3,'almost-full actual fill');}
    if($case==='container-orders')cargoNear($json['data']['totals']['cbm'],1.4,'detail received load');
    if($case==='container-csv'){cargoAssert(str_contains($body,'Cargo CBM'),'CSV measurement label');cargoAssert(str_contains($body,'1.4'),'CSV actual measurement');cargoAssert(str_contains($body,"'=2+3"),'CSV formula input not neutralized');cargoAssert(str_contains($body,'Currency')&&str_contains($body,'USD'),'CSV omitted monetary currency');}
    if($case==='container-xlsx'){
        $tmp=tempnam(sys_get_temp_dir(),'cargo_export_');file_put_contents($tmp,$body);
        try{$book=\PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);$cells=array_merge(...$book->getActiveSheet()->toArray());
            cargoAssert(in_array(1.4,$cells,true)||in_array('1.4',$cells,true),'XLSX lacks actual CBM');cargoAssert(in_array(110,$cells,true)||in_array('110',$cells,true),'XLSX lacks actual quantity');
            cargoAssert(count(array_filter($cells,static fn($v)=>is_string($v)&&str_starts_with($v,'SG-BEY-REVIEW-')))>0,'XLSX lacks container identity');
        }finally{unlink($tmp);}
    }
    echo 'PASS: rollback-only endpoint '.$case."\n";
}
foreach($baseline as$table=>$count)cargoAssert((int)$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn()===$count,'Fixture leaked into '.$table);
cargoAssert($pdo->query("SELECT key_value FROM system_config WHERE key_name='NOTIFICATION_CHANNELS'")->fetchColumn()===$baselineChannels,'Notification configuration was not restored');
echo "PASS: fixture rows and notification configuration fully rolled back\n";
