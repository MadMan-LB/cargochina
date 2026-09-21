<?php
/** Warehouse read projections: every fixture is rolled back, including export exits. */
require_once dirname(__DIR__).'/backend/config/database.php';
require_once dirname(__DIR__).'/backend/services/OrderReceivingService.php';
require_once dirname(__DIR__).'/backend/services/CargoMetricsService.php';
$pdo=getDb();
function stockAssert($ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
if (($argv[1]??'')==='--probe') {
    $pdo->beginTransaction();register_shutdown_function(static function()use($pdo){if($pdo->inTransaction())$pdo->rollBack();});
    $_SESSION=['user_id'=>1,'user_roles'=>['SuperAdmin']];
    $customer=(int)$pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn();
    $supplier=(int)$pdo->query('SELECT id FROM suppliers ORDER BY id LIMIT 1')->fetchColumn();
    $pdo->prepare("INSERT INTO orders(customer_id,supplier_id,status,created_by) VALUES (?,?,'Approved',1)")->execute([$customer,$supplier]);$order=(int)$pdo->lastInsertId();
    $items=[];foreach(['=2+3','Unreceived utensils'] as $description){
        $pdo->prepare("INSERT INTO order_items(order_id,cartons,qty_per_carton,quantity,unit,declared_cbm,declared_weight,description_en,item_height,item_width,item_length) VALUES (?,10,10,100,'pieces',1,100,?,99,99,99)")->execute([$order,$description]);$items[]=(int)$pdo->lastInsertId();
    }
    $svc=new OrderReceivingService();
    foreach([[2,20],[1,30]] as $i=>[$cartons,$height]) {
        $svc->receive($pdo,$order,['idempotency_key'=>'stock-ledger-'.$order.'-'.$i,'actual_cartons'=>$cartons,'actual_cbm'=>$cartons/10,'actual_weight'=>$cartons*10,'items'=>[['order_item_id'=>$items[0],'actual_cartons'=>$cartons,'actual_quantity'=>$cartons*10,'actual_cbm'=>$cartons/10,'actual_weight'=>$cartons*10,'actual_height'=>$height,'actual_width'=>20,'actual_length'=>50]]],1,false);
    }
    $case=$argv[2]??'partial';$_GET=['order_ids'=>(string)$order];
    if($case==='reserved') {
        $pdo->prepare("UPDATE orders SET status='AssignedToContainer' WHERE id=?")->execute([$order]);
        $pdo->prepare("INSERT INTO containers(code,max_cbm,max_weight,status) VALUES (?,28,28000,'planning')")->execute(['QA-STOCK-'.bin2hex(random_bytes(5))]);$container=(int)$pdo->lastInsertId();
        for($i=0;$i<2;$i++){$pdo->prepare("INSERT INTO shipment_drafts(status,container_id) VALUES ('draft',?)")->execute([$container]);$pdo->prepare('INSERT INTO shipment_draft_orders(shipment_draft_id,order_id) VALUES (?,?)')->execute([(int)$pdo->lastInsertId(),$order]);}
        $_GET['container_id']=$container;
    }
    if($case==='finalized')$pdo->prepare("UPDATE orders SET status='FinalizedAndPushedToTracking' WHERE id=?")->execute([$order]);
    if($case==='legacy'){
        $pdo->prepare('DELETE wri FROM warehouse_receipt_items wri JOIN warehouse_receipts wr ON wr.id=wri.receipt_id WHERE wr.order_id=?')->execute([$order]);
        require dirname(__DIR__).'/backend/api/handlers/containers.php';
        $totals=loadContainerOrderTotals($pdo,$order);stockAssert($totals['total_qty']===null && $totals['total_amount']===null,'Container fabricated unknown totals');
        require_once dirname(__DIR__).'/backend/services/TrackingPushService.php';
        $build=new ReflectionMethod(TrackingPushService::class,'buildPayload');$build->setAccessible(true);$rejected=false;
        try{$build->invoke(new TrackingPushService($pdo),0,[],[$order]);}catch(RuntimeException $e){$rejected=str_contains($e->getMessage(),'reconciliation');}
        stockAssert($rejected,'Unknown physical quantities entered tracking payload');
    }
    if($case==='void')$pdo->prepare("UPDATE warehouse_receipts SET voided_at=NOW(),void_reason='Rollback stock regression' WHERE order_id=?")->execute([$order]);
    if($case==='missing') {
        $pdo->prepare("UPDATE warehouse_receipts SET voided_at=NOW(),void_reason='Rollback missing ledger regression' WHERE order_id=?")->execute([$order]);
        $pdo->prepare("UPDATE orders SET status='ReadyForConsolidation' WHERE id=?")->execute([$order]);
        $summary=CargoMetricsService::totals($pdo,[$order])[$order];stockAssert(!$summary['quantity_complete'] && $summary['received_quantity']===null,'Missing ledger counted as known goods');
    }
    if(in_array($case,['InWarehouse','InTransit'],true))$_GET['status']=$case;
    if($case==='search')$_GET['q']='Unreceived utensils';
    if($case==='page')$_GET+=['limit'=>1,'offset'=>1];
    if($case==='invalid')$_GET['container_id']='1 OR 1=1';
    if($case==='unauthorized')$_SESSION=['user_id'=>0,'user_roles'=>[]];
    if($case==='forbidden')$_SESSION=['user_id'=>160,'user_roles'=>[]];
    if($case==='csv'||$case==='xlsx')$_GET['format']=$case;
    $handler=require dirname(__DIR__).'/backend/api/handlers/warehouse-stock.php';
    $handler('GET',in_array($case,['csv','xlsx'],true)?'export':null,null,[]);exit;
}
function stockProbe(string $case):string {
    $pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'--probe',$case],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);
    stockAssert($exit===0 && $err==='',"$case failed: $err $out");return $out;
}
foreach(['partial','reserved','finalized','legacy','missing','void','InWarehouse','InTransit','search','page','invalid','unauthorized'] as $case){
    $result=json_decode(stockProbe($case),true);stockAssert(is_array($result),"$case returned invalid JSON");$rows=$result['data']??[];
    if(in_array($case,['partial','reserved'],true)){
        stockAssert(count($rows)===2,'Duplicate or missing stock rows');
        stockAssert((float)$rows[0]['item_actual_quantity']===30.0 && (float)$rows[0]['remaining_quantity']===70.0,'Partial quantity mismatch');
        stockAssert(abs((float)$rows[0]['item_actual_cbm']-.3)<.000001 && (float)$rows[0]['item_actual_weight']===30.0,'Physical totals mismatch');
        stockAssert($rows[0]['item_actual_height']===null && (float)$rows[0]['item_actual_width']===20.0,'Mixed receipt dimensions must not invent a carton');
        stockAssert((float)$rows[1]['item_actual_quantity']===0.0 && (float)$rows[1]['item_actual_cbm']===0.0 && $rows[1]['warehouse_state']==='InTransit','Unreceived item became physical stock');
    }
    if($case==='finalized')stockAssert(!$rows,'Finalized goods still in stock');
    if(in_array($case,['legacy','missing'],true))foreach($rows as $row)stockAssert($row['item_actual_quantity']===null && $row['item_actual_cbm']===null && $row['reconciliation_required'],'Unknown allocation fabricated quantities');
    if($case==='void')foreach($rows as $row)stockAssert((float)$row['item_actual_quantity']===0.0 && $row['warehouse_state']==='InTransit','Voided receipt remains in stock');
    if(in_array($case,['InWarehouse','InTransit'],true))stockAssert(count($rows)===1 && $rows[0]['warehouse_state']===$case,'Item-level state filter mismatch');
    if($case==='search')stockAssert(count($rows)===1 && $rows[0]['description_en']==='Unreceived utensils','Search mismatch');
    if($case==='page')stockAssert(count($rows)===1 && (int)$result['meta']['total']===2 && !$result['meta']['has_more'],'Pagination mismatch');
    if(in_array($case,['invalid','unauthorized'],true))stockAssert(!empty($result['error']),'Invalid/unauthorized query accepted');
    echo "PASS: warehouse $case\n";
}
$csv=stockProbe('csv');$lines=array_map('str_getcsv',explode("\n",trim($csv)));stockAssert($lines[1][5]==="'=2+3" && (float)$lines[1][9]===30.0 && (float)$lines[2][9]===0.0,'CSV formula safety or actual quantities mismatch');
$xlsx=stockProbe('xlsx');$file=tempnam(sys_get_temp_dir(),'clms-stock-');file_put_contents($file,$xlsx);
try {require_once dirname(__DIR__).'/vendor/autoload.php';$sheet=\PhpOffice\PhpSpreadsheet\IOFactory::load($file)->getActiveSheet();$found=false;foreach($sheet->getRowIterator() as $row){$r=$row->getRowIndex();if($sheet->getCell('F'.$r)->getValue()==='=2+3'){$found=true;stockAssert($sheet->getCell('F'.$r)->getDataType()==='s' && (float)$sheet->getCell('K'.$r)->getValue()===30.0,'XLSX safety or actual quantities mismatch');}}stockAssert($found,'XLSX fixture missing');}finally{unlink($file);}
echo "PASS: warehouse CSV/XLSX canonical quantities and spreadsheet safety; all fixture transactions rolled back\n";

