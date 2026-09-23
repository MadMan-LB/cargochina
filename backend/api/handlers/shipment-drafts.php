<?php

/**
 * Shipment Drafts API - create, add orders, assign container, finalize
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 2) . '/services/TrackingPushService.php';
require_once dirname(__DIR__, 2) . '/services/NotificationService.php';
require_once dirname(__DIR__, 2) . '/services/OrderCountryService.php';
require_once dirname(__DIR__, 2) . '/services/CargoMetricsService.php';
require_once dirname(__DIR__, 2) . '/services/ContainerCapacityService.php';
require_once dirname(__DIR__, 2) . '/services/ShipmentAssignmentService.php';
require_once dirname(__DIR__, 2) . '/services/CargoStateService.php';
require_once dirname(__DIR__, 2) . '/services/ShipmentWriteService.php';
require_once dirname(__DIR__, 2) . '/services/RecycleBinService.php';

function shipmentDraftVisibleOrderIds(PDO $pdo, int $draftId): array
{
    $stmt = $pdo->prepare(
        "SELECT sdo.order_id
         FROM shipment_draft_orders sdo
         WHERE sdo.shipment_draft_id = ?
         ORDER BY sdo.order_id" . ($pdo->inTransaction() ? ' FOR UPDATE' : '')
    );
    $stmt->execute([$draftId]);
    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'order_id');
}

function shipmentDraftFetchVisibleOrder(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare(
        "SELECT o.id, o.status, o.destination_country_id, o.confirmation_token
         FROM orders o
         WHERE o.id = ?" . ($pdo->inTransaction() ? ' FOR UPDATE' : '')
    );
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        jsonError("Order $orderId not found", 404);
    }
    return $order;
}

function shipmentDraftRestoreOrderState(PDO $pdo, int $orderId): void
{
    $pdo->prepare("UPDATE orders SET status=CASE
        WHEN EXISTS (SELECT 1 FROM shipment_draft_orders sdo JOIN shipment_drafts sd ON sd.deleted_at IS NULL AND sd.id=sdo.shipment_draft_id WHERE sdo.order_id=? AND sd.container_id IS NOT NULL) THEN 'AssignedToContainer'
        WHEN EXISTS (SELECT 1 FROM shipment_draft_orders WHERE order_id=?) THEN 'ConsolidatedIntoShipmentDraft'
        ELSE 'ReadyForConsolidation' END
        WHERE id=? AND status IN ('ConsolidatedIntoShipmentDraft','AssignedToContainer')")->execute([$orderId,$orderId,$orderId]);
}

return function (string $method, ?string $id, ?string $action, array $input) {
    require_once __DIR__ . '/../authorization.php';
    clmsAuthorizeApiRequest('shipment-drafts', $method, $id, $action);
    requirePermission($method === 'GET' ? 'shipment-drafts.read' : 'shipment-drafts.write');
    $pdo = getDb();
    if ($method === 'GET') { require_once dirname(__DIR__, 2) . '/services/QueryFilterService.php'; QueryFilterService::validate($_GET, 'shipment-drafts'); }
    $userId = getAuthUserId() ?? 1;
    try {
    $startedTransaction = false;
    if ($id !== null && ($method === 'DELETE' || $method === 'PUT' || ($method === 'POST' && in_array($action, ['add-orders', 'assign-container', 'remove-orders', 'finalize', 'documents', 'remove-document'], true)))) {
        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) {
            $pdo->beginTransaction();
            register_shutdown_function(static function () use ($pdo) { if ($pdo->inTransaction()) $pdo->rollBack(); });
        }
        // Consistent lock order: containers, draft, orders, then receipt ledger.
        $before=$pdo->prepare('SELECT container_id FROM shipment_drafts WHERE deleted_at IS NULL AND id=?');$before->execute([$id]);$beforeContainer=$before->fetchColumn();
        $containerLocks=array_values(array_unique(array_filter([(int)$beforeContainer,$action==='assign-container'?(int)($input['container_id']??0):0])));sort($containerLocks,SORT_NUMERIC);
        foreach($containerLocks as $containerLock){
            $lock=$pdo->prepare('SELECT * FROM containers WHERE id=? FOR UPDATE');$lock->execute([$containerLock]);$lockedContainer=$lock->fetch(PDO::FETCH_ASSOC);
            if(!$lockedContainer)jsonError('Container not found',404);
            if ($action !== 'finalize') ShipmentAssignmentService::assertContainerOpen($pdo,$lockedContainer);
        }
        $lock = $pdo->prepare('SELECT container_id FROM shipment_drafts WHERE deleted_at IS NULL AND id=? FOR UPDATE');
        $lock->execute([$id]);
        if((int)$lock->fetchColumn()!==(int)$beforeContainer)jsonError('Draft container changed concurrently; reload and retry',409);
        $lockedDraftStmt=$pdo->prepare('SELECT * FROM shipment_drafts WHERE deleted_at IS NULL AND id=? FOR UPDATE');$lockedDraftStmt->execute([$id]);$lockedDraft=$lockedDraftStmt->fetch(PDO::FETCH_ASSOC);
        if(!$lockedDraft)jsonError('Shipment draft not found',404);
        if($action!=='finalize')CargoStateService::assertDraftMutable($lockedDraft);
    }

    switch ($method) {
        case 'DELETE':
            if ($id === null) jsonError('Draft ID required', 400);
            $stmt = $pdo->prepare("SELECT * FROM shipment_drafts WHERE deleted_at IS NULL AND id = ?");
            $stmt->execute([$id]);
            $sd = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sd) jsonError('Shipment draft not found', 404);
            if ($sd['status'] === 'finalized') jsonError('Cannot delete finalized draft', 400);
            $so = $pdo->prepare("SELECT order_id FROM shipment_draft_orders WHERE shipment_draft_id = ?");
            $so->execute([$id]);
            $orderIds = array_column($so->fetchAll(PDO::FETCH_ASSOC), 'order_id');
            sort($orderIds,SORT_NUMERIC);
            if(!is_string($input['deletion_revision']??null)||!hash_equals(RecycleBinService::shipmentDeletionRevision($sd,$orderIds),$input['deletion_revision']))jsonError('Draft or cargo changed; reopen the delete confirmation',409);
            foreach($orderIds as $oid)shipmentDraftFetchVisibleOrder($pdo,(int)$oid);
            if(!empty($sd['container_id']))requirePermission('containers.assign');
            try {
                $pdo->prepare("DELETE FROM shipment_draft_orders WHERE shipment_draft_id = ?")->execute([$id]);
                RecycleBinService::mark($pdo,'shipment_draft',(int)$id,$userId,$input['delete_reason']??null,$orderIds);
                foreach ($orderIds as $oid) {
                    shipmentDraftRestoreOrderState($pdo, (int) $oid);
                }
                if ($startedTransaction) $pdo->commit();
                jsonResponse(['data' => ['deleted' => true]]);
            } catch (Exception $e) {
                if ($startedTransaction && $pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            break;

        case 'GET':
            if ($id === null) {
                foreach(['limit'=>1,'offset'=>0,'container_id'=>1] as $field=>$minimum)if(isset($_GET[$field])&&(filter_var($_GET[$field],FILTER_VALIDATE_INT)===false||(int)$_GET[$field]<$minimum))jsonError("Invalid $field filter",422);
                $where=['sd.deleted_at IS NULL'];$params=[];$status=$_GET['status']??'';$q=$_GET['q']??'';
                if(!is_string($q)||!is_string($status))jsonError('Invalid shipment filter',422);
                if($status!==''){if(!in_array($status,['draft','finalized'],true))jsonError('Invalid shipment status',422);$where[]='sd.status=?';$params[]=$status;}
                if(isset($_GET['container_id'])){$where[]='sd.container_id=?';$params[]=(int)$_GET['container_id'];}
                if($q!==''){$where[]='(sd.id=? OR c.code LIKE ? OR sd.booking_number LIKE ? OR sd.container_number LIKE ?)';$like=clmsSearchLike($q);array_push($params,ctype_digit($q)?(int)$q:0,$like,$like,$like);}
                $sql='SELECT sd.*, c.code as container_code FROM shipment_drafts sd LEFT JOIN containers c ON sd.container_id=c.id'.($where?' WHERE '.implode(' AND ',$where):'');
                $count=$pdo->prepare("SELECT COUNT(*) FROM ($sql) draft_filtered");$count->execute($params);$total=(int)$count->fetchColumn();
                $limit=clmsQueryLimit($_GET['limit']??null,50,200);$offset=clmsQueryOffset($_GET['offset']??null);
                $stmt=$pdo->prepare($sql.' ORDER BY sd.id DESC LIMIT '.($limit+1).' OFFSET '.$offset);$stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $more=count($rows)>$limit;if($more)$rows=array_slice($rows,0,$limit);
                $svc = new TrackingPushService($pdo);
                foreach ($rows as &$r) {
                    $r['revision']=ShipmentWriteService::revision($r);
                    $r['order_ids'] = shipmentDraftVisibleOrderIds($pdo, (int) $r['id']);
                    $r['deletion_revision']=RecycleBinService::shipmentDeletionRevision($r,$r['order_ids']);
                    $pushStatus = $svc->getPushStatus((int) $r['id']);
                    $r['push_status'] = $pushStatus ? $pushStatus['status'] : null;
                    $r['push_last_error'] = $pushStatus['last_error'] ?? null;
                }
                jsonResponse(['data' => $rows,'meta'=>['total'=>$total,'offset'=>$offset,'limit'=>$limit,'has_more'=>$more]]);
            }
            $stmt = $pdo->prepare("SELECT sd.*, c.code as container_code, c.max_cbm, c.max_weight FROM shipment_drafts sd LEFT JOIN containers c ON sd.container_id = c.id WHERE sd.deleted_at IS NULL AND sd.id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) jsonError('Shipment draft not found', 404);
            $orderIds = shipmentDraftVisibleOrderIds($pdo, (int) $id);
            $row['revision']=ShipmentWriteService::revision($row);
            $row['order_ids'] = $orderIds;
            $row['deletion_revision']=RecycleBinService::shipmentDeletionRevision($row,$orderIds);
            if (!empty($orderIds)) {
                $ph = implode(',', array_fill(0, count($orderIds), '?'));
                $cargoSql = CargoMetricsService::orderTotalsSql($pdo);
                $tot = $pdo->prepare("SELECT COALESCE(SUM(cbm),0), COALESCE(SUM(weight),0), COALESCE(SUM(measured_cargo_complete=0),0)=0 FROM ($cargoSql) cargo WHERE order_id IN ($ph)");
                $tot->execute($orderIds);
                $t = $tot->fetch(PDO::FETCH_NUM);
                $row['total_cbm'] = $t[2]?(float)$t[0]:null;
                $row['total_weight'] = $t[2]?(float)$t[1]:null;
                $row['capacity_known']=(bool)$t[2];
            } else {
                $row['total_cbm'] = 0;
                $row['total_weight'] = 0;
            }
            $docs = $pdo->prepare("SELECT id, file_path, doc_type, created_at FROM shipment_draft_documents WHERE shipment_draft_id = ? ORDER BY created_at");
            $docs->execute([$id]);
            $row['documents'] = array_map(function (array $doc) {
                $doc['file_path'] = normalizeStoredUploadPath((string) $doc['file_path'], false);
                return $doc;
            }, $docs->fetchAll(PDO::FETCH_ASSOC));
            $row['tracking_mode']=(new TrackingPushService($pdo))->mode();
            jsonResponse(['data' => $row]);
            break;

        case 'PUT':
            if ($id === null) jsonError('Draft ID required', 400);
            if(!is_string($input['revision']??null)||!hash_equals(ShipmentWriteService::revision($lockedDraft),$input['revision']))jsonError('Shipment references changed or revision is missing; reopen before saving',409);
            $input=ShipmentWriteService::refs($input);
            $stmt = $pdo->prepare("SELECT id FROM shipment_drafts WHERE deleted_at IS NULL AND id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) jsonError('Shipment draft not found', 404);
            $updates = [];
            $params = [];
            if (array_key_exists('container_number', $input)) {
                $updates[] = 'container_number = ?';
                $params[] = trim($input['container_number'] ?? '') ?: null;
            }
            if (array_key_exists('booking_number', $input)) {
                $updates[] = 'booking_number = ?';
                $params[] = trim($input['booking_number'] ?? '') ?: null;
            }
            if (array_key_exists('tracking_url', $input)) {
                $updates[] = 'tracking_url = ?';
                $params[] = trim($input['tracking_url'] ?? '') ?: null;
            }
            if (!empty($updates)) {
                $params[] = $id;
                $pdo->prepare("UPDATE shipment_drafts SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
            }
            $stmt = $pdo->prepare("SELECT sd.*, c.code as container_code FROM shipment_drafts sd LEFT JOIN containers c ON sd.container_id = c.id WHERE sd.deleted_at IS NULL AND sd.id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $row['revision']=ShipmentWriteService::revision($row);
            $row['order_ids'] = shipmentDraftVisibleOrderIds($pdo, (int) $id);
            $docs = $pdo->prepare("SELECT id, file_path, doc_type, created_at FROM shipment_draft_documents WHERE shipment_draft_id = ? ORDER BY created_at");
            $docs->execute([$id]);
            $row['documents'] = array_map(function (array $doc) {
                $doc['file_path'] = normalizeStoredUploadPath((string) $doc['file_path'], false);
                return $doc;
            }, $docs->fetchAll(PDO::FETCH_ASSOC));
            ShipmentAssignmentService::audit($pdo,'update_refs',(int)$id,$lockedDraft,$input,$userId);
            if ($startedTransaction) $pdo->commit();
            jsonResponse(['data' => $row]);
            break;

        case 'POST':
            if ($id === null) {
                $row=ShipmentWriteService::create($pdo,$input,$userId);$row['order_ids']=shipmentDraftVisibleOrderIds($pdo,(int)$row['id']);
                jsonResponse(['data'=>$row],$row['already_applied']?200:201);
                break;
            }
            if ($action === 'add-orders') {
                $orderIds = ShipmentAssignmentService::ids($input['order_ids'] ?? null);
                $eligible = ['ReadyForConsolidation', 'Confirmed'];
                $draftStmt = $pdo->prepare("SELECT * FROM shipment_drafts WHERE deleted_at IS NULL AND id = ? FOR UPDATE");
                $draftStmt->execute([$id]);
                $draft = $draftStmt->fetch(PDO::FETCH_ASSOC);
                if (!$draft) jsonError('Shipment draft not found', 404);
                if ($draft['status'] === 'finalized') jsonError('Cannot change orders in a finalized draft', 400);
                $container = null;
                if (!empty($draft['container_id'])) {
                    requirePermission('containers.assign');
                    $containerStmt = $pdo->prepare("SELECT * FROM containers WHERE id = ? FOR UPDATE");
                    $containerStmt->execute([(int) $draft['container_id']]);
                    $container = $containerStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                }
                $newOrderIds=[];
                foreach ($orderIds as $oid) {
                    $order = shipmentDraftFetchVisibleOrder($pdo, (int) $oid);
                    $memberships=ShipmentAssignmentService::memberships($pdo,(int)$oid);
                    if($memberships){
                        if((int)$memberships[0]['shipment_draft_id']===(int)$id && in_array($order['status'],['ConsolidatedIntoShipmentDraft','AssignedToContainer'],true))continue;
                        throw new ShipmentAssignmentException("Order #$oid is already reserved in another shipment draft");
                    }
                    $s = $order['status'] ?? null;
                    if (!in_array($s, $eligible, true)) {
                        jsonError("Order $oid is not eligible (must be ReadyForConsolidation or Confirmed)", 400);
                    }
                    if (trim((string) ($order['confirmation_token'] ?? '')) !== '') {
                        jsonError("Order $oid is still waiting for customer feedback and cannot be added to a shipment draft yet.", 400);
                    }
                    if ($container && !OrderCountryService::orderMatchesContainer($pdo, $order, $container)) {
                        jsonError("Order $oid destination country does not match the assigned container destination.", 400);
                    }
                    ShipmentAssignmentService::assertReceived($pdo,(int)$oid);
                    $newOrderIds[]=$oid;
                }
                $orderIds=$newOrderIds;
                if ($container) {
                    ContainerCapacityService::check($pdo,$container,$orderIds);
                }
                $ins = $pdo->prepare("INSERT IGNORE INTO shipment_draft_orders (shipment_draft_id, order_id) VALUES (?,?)");
                foreach ($orderIds as $oid) {
                    $ins->execute([$id, $oid]);
                }
                if (!empty($orderIds)) {
                    $ph = implode(',', array_fill(0, count($orderIds), '?'));
                    $pdo->prepare("UPDATE orders SET status='ConsolidatedIntoShipmentDraft' WHERE id IN ($ph)")->execute($orderIds);
                    // If draft already has a container assigned, new orders must be AssignedToContainer for finalize to succeed
                    $chk = $pdo->prepare("SELECT container_id FROM shipment_drafts WHERE deleted_at IS NULL AND id = ?");
                    $chk->execute([$id]);
                    if ($chk->fetchColumn()) {
                        $pdo->prepare("UPDATE orders SET status='AssignedToContainer' WHERE id IN ($ph)")->execute($orderIds);
                    }
                }
                $stmt = $pdo->prepare("SELECT * FROM shipment_drafts WHERE deleted_at IS NULL AND id = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $row['order_ids'] = shipmentDraftVisibleOrderIds($pdo, (int) $id);
                if($orderIds)ShipmentAssignmentService::audit($pdo,'add_orders',(int)$id,[],['order_ids'=>$orderIds,'container_id'=>$draft['container_id']],$userId);
                $row['already_applied']=!$orderIds;
                if ($startedTransaction) $pdo->commit();
                jsonResponse(['data' => $row]);
                break;
            }
            if ($action === 'assign-container') {
                requirePermission('containers.assign');
                $draftStmt = $pdo->prepare('SELECT status FROM shipment_drafts WHERE deleted_at IS NULL AND id=? FOR UPDATE');
                $draftStmt->execute([$id]);
                $draftStatus = $draftStmt->fetchColumn();
                if ($draftStatus === false) jsonError('Shipment draft not found', 404);
                if ($draftStatus === 'finalized') jsonError('Cannot reassign a finalized draft', 400);
                $containerId = (int) ($input['container_id'] ?? 0);
                if (!$containerId) jsonError('container_id required', 400);
                $orderIds = shipmentDraftVisibleOrderIds($pdo, (int) $id);
                $containerStmt = $pdo->prepare("SELECT * FROM containers WHERE id = ? FOR UPDATE");
                $containerStmt->execute([$containerId]);
                $container = $containerStmt->fetch(PDO::FETCH_ASSOC);
                if (!$container) jsonError('Container not found', 404);
                foreach ($orderIds as $oid) {
                    $order = shipmentDraftFetchVisibleOrder($pdo, (int) $oid);
                    if(!in_array($order['status'],['ConsolidatedIntoShipmentDraft','AssignedToContainer'],true))throw new ShipmentAssignmentException("Order #$oid has an invalid reservation state");
                    $membership=ShipmentAssignmentService::memberships($pdo,(int)$oid);
                    if(!$membership || (int)$membership[0]['shipment_draft_id']!==(int)$id)throw new ShipmentAssignmentException("Order #$oid reservation changed concurrently");
                    ShipmentAssignmentService::assertReceived($pdo,(int)$oid);
                    if (trim((string) ($order['confirmation_token'] ?? '')) !== '') {
                        jsonError("Order $oid is still waiting for customer feedback and cannot be assigned to a container yet.", 400);
                    }
                    if (!OrderCountryService::orderMatchesContainer($pdo, $order, $container)) {
                        jsonError("Order $oid destination country does not match container destination.", 400);
                    }
                }
                ContainerCapacityService::check($pdo,$container,$orderIds);
                $pdo->prepare("UPDATE shipment_drafts SET container_id = ? WHERE id = ?")->execute([$containerId, $id]);
                if (!empty($orderIds)) {
                    $ph = implode(',', array_fill(0, count($orderIds), '?'));
                    $pdo->prepare("UPDATE orders SET status='AssignedToContainer' WHERE id IN ($ph)")->execute($orderIds);
                }
                $stmt = $pdo->prepare("SELECT sd.*, c.code as container_code FROM shipment_drafts sd LEFT JOIN containers c ON sd.container_id = c.id WHERE sd.deleted_at IS NULL AND sd.id = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $row['order_ids'] = shipmentDraftVisibleOrderIds($pdo, (int) $id);
                if((int)$beforeContainer!==$containerId)ShipmentAssignmentService::audit($pdo,'assign_container',(int)$id,['container_id'=>$beforeContainer?:null],['container_id'=>$containerId,'order_ids'=>$orderIds],$userId);
                $row['already_applied']=(int)$beforeContainer===$containerId;
                if ($startedTransaction) $pdo->commit();
                jsonResponse(['data' => $row]);
                break;
            }
            if ($action === 'finalize') {
                requirePermission('shipment-drafts.finalize');
                $stmt = $pdo->prepare("SELECT * FROM shipment_drafts WHERE deleted_at IS NULL AND id = ? FOR UPDATE");
                $stmt->execute([$id]);
                $sd = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$sd) jsonError('Shipment draft not found', 404);
                if ($sd['status'] === 'finalized') {
                    if ($startedTransaction) $pdo->commit();
                    jsonResponse(['data'=>['status'=>'finalized','already_applied'=>true,'tracking_result'=>TrackingPushService::statusResult((new TrackingPushService($pdo))->getPushStatus((int)$id))]]);
                }
                CargoStateService::assertDraftMutable($sd);
                requirePermission('containers.assign');
                $containerStmt=$pdo->prepare('SELECT * FROM containers WHERE id=? FOR UPDATE');$containerStmt->execute([$sd['container_id']]);$container=$containerStmt->fetch(PDO::FETCH_ASSOC);
                if(!$container)jsonError('Draft must have a container before finalizing',400);
                if(!in_array($container['status'],['planning','to_go'],true))throw new ShipmentAssignmentException('Departed containers cannot be finalized');
                ContainerCapacityService::check($pdo,$container);
                $orderIds = shipmentDraftVisibleOrderIds($pdo, (int) $id);
                if (empty($orderIds)) jsonError('Draft must have at least one order to finalize', 400);
                foreach ($orderIds as $oid) {
                    $order = shipmentDraftFetchVisibleOrder($pdo, (int) $oid);
                    if (($order['status'] ?? null) !== 'AssignedToContainer') {
                        jsonError("Order $oid must be AssignedToContainer before finalizing", 400);
                    }
                    ShipmentAssignmentService::assertFeedbackResolved($order);
                    ShipmentAssignmentService::assertReceived($pdo,(int)$oid);
                    $membership=ShipmentAssignmentService::memberships($pdo,(int)$oid);
                    if(!$membership || (int)$membership[0]['shipment_draft_id']!==(int)$id)throw new ShipmentAssignmentException('Order reservation does not match this shipment');
                    if(!OrderCountryService::orderMatchesContainer($pdo,$order,$container))throw new ShipmentAssignmentException('Order destination does not match container');
                }
                $pdo->prepare("UPDATE shipment_drafts SET status='finalized' WHERE id=?")->execute([$id]);
                $ph = implode(',', array_fill(0, count($orderIds), '?'));
                $pdo->prepare("UPDATE orders SET status='FinalizedAndPushedToTracking' WHERE id IN ($ph)")->execute($orderIds);
                (new TrackingPushService($pdo))->prepare((int)$id);
                ShipmentAssignmentService::audit($pdo,'finalize',(int)$id,['status'=>'draft'],['status'=>'finalized','order_ids'=>$orderIds],$userId);
                (new NotificationService($pdo))->notifyShipmentFinalized($id, count($orderIds));
                if ($startedTransaction) $pdo->commit();
                $trackingResult = null;
                if (!$pdo->inTransaction()) try {
                    $svc = new TrackingPushService($pdo);
                    $trackingResult = $svc->push((int) $id);
                } catch (TrackingPushBusyException $e) {
                    $trackingResult=['success'=>false,'status'=>'pending','message'=>$e->getMessage()];
                } catch (Throwable $e) {
                    $trackingResult = ['success' => false, 'status' => 'failed', 'message' => $e->getMessage(), 'push_failed' => true];
                }
                jsonResponse(['data' => ['status' => 'finalized', 'tracking_result' => $trackingResult]]);
                break;
            }
            if ($action === 'push') {
                requirePermission('shipment-drafts.push');
                $stmt = $pdo->prepare("SELECT * FROM shipment_drafts WHERE deleted_at IS NULL AND id = ?");
                $stmt->execute([$id]);
                $sd = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$sd) jsonError('Shipment draft not found', 404);
                if ($sd['status'] !== 'finalized') jsonError('Draft must be finalized before push/retry', 400);
                $svc = new TrackingPushService($pdo);
                try {
                    $result = $svc->push((int) $id);
                } catch (TrackingPushBusyException $e) {
                    jsonError($e->getMessage(),409);
                } catch (Throwable $e) {
                    $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('shipment_draft',?,?,?,?)")
                        ->execute([$id, 'tracking_push', json_encode(['success' => false, 'message' => $e->getMessage()]), $userId]);
                    jsonError('Tracking push failed: ' . $e->getMessage(), 502);
                }
                $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('shipment_draft',?,?,?,?)")
                    ->execute([$id, 'tracking_push', json_encode($result), $userId]);
                jsonResponse(['data' => $result]);
                break;
            }
            if ($action === 'documents') {
                $stmt = $pdo->prepare("SELECT id FROM shipment_drafts WHERE deleted_at IS NULL AND id = ?");
                $stmt->execute([$id]);
                if (!$stmt->fetch()) jsonError('Shipment draft not found', 404);
                if(!is_string($input['file_path']??null))jsonError('Document path must be text',422);
                $filePath = normalizeStoredUploadPath($input['file_path']);
                $docType=$input['doc_type']??'other';if(!in_array($docType,['bol','booking_confirmation','invoice','other'],true))jsonError('Invalid shipment document type',422);
                if (!$filePath) jsonError('file_path required', 400);
                $existingDoc=$pdo->prepare('SELECT id,file_path,doc_type,created_at FROM shipment_draft_documents WHERE shipment_draft_id=? AND file_path=? AND doc_type=? FOR UPDATE');$existingDoc->execute([$id,$filePath,$docType]);$doc=$existingDoc->fetch(PDO::FETCH_ASSOC);
                if($doc){if($startedTransaction)$pdo->commit();jsonResponse(['data'=>$doc,'already_applied'=>true]);}
                $pdo->prepare("INSERT INTO shipment_draft_documents (shipment_draft_id, file_path, doc_type) VALUES (?,?,?)")->execute([$id, $filePath, $docType]);
                $newId = (int) $pdo->lastInsertId();
                $row = $pdo->prepare("SELECT id, file_path, doc_type, created_at FROM shipment_draft_documents WHERE id = ?");
                $row->execute([$newId]);
                $doc = $row->fetch(PDO::FETCH_ASSOC);
                $doc['file_path'] = normalizeStoredUploadPath((string) $doc['file_path']);
                ShipmentAssignmentService::audit($pdo,'add_document',(int)$id,[],$doc,$userId);
                if ($startedTransaction) $pdo->commit();
                jsonResponse(['data' => $doc], 201);
                break;
            }
            if ($action === 'remove-document') {
                $docId = (int) ($input['document_id'] ?? 0);
                if (!$docId) jsonError('document_id required', 400);
                $stmt = $pdo->prepare("SELECT id FROM shipment_draft_documents WHERE id = ? AND shipment_draft_id = ?");
                $stmt->execute([$docId, $id]);
                if (!$stmt->fetch()) {if($startedTransaction)$pdo->commit();jsonResponse(['data'=>['deleted'=>true,'already_applied'=>true]]);}
                $pdo->prepare("DELETE FROM shipment_draft_documents WHERE id = ?")->execute([$docId]);
                ShipmentAssignmentService::audit($pdo,'remove_document',(int)$id,['document_id'=>$docId],[],$userId);
                if ($startedTransaction) $pdo->commit();
                jsonResponse(['data' => ['deleted' => true]]);
                break;
            }
            if ($action === 'remove-orders') {
                $draftStmt = $pdo->prepare('SELECT status FROM shipment_drafts WHERE deleted_at IS NULL AND id=? FOR UPDATE');
                $draftStmt->execute([$id]);
                $draftStatus = $draftStmt->fetchColumn();
                if ($draftStatus === false) jsonError('Shipment draft not found', 404);
                if ($draftStatus === 'finalized') jsonError('Cannot change orders in a finalized draft', 400);
                $orderIds = ShipmentAssignmentService::ids($input['order_ids'] ?? null);
                if($beforeContainer)requirePermission('containers.assign');
                if (empty($orderIds)) jsonError('order_ids required', 400);
                $del = $pdo->prepare("DELETE FROM shipment_draft_orders WHERE shipment_draft_id = ? AND order_id = ?");
                $removed=[];
                foreach ($orderIds as $oid) {
                    shipmentDraftFetchVisibleOrder($pdo,(int)$oid);
                    $del->execute([$id, $oid]);
                    if ($del->rowCount() > 0) {
                        $removed[]=$oid;
                        shipmentDraftRestoreOrderState($pdo, (int) $oid);
                    }
                }
                $stmt = $pdo->prepare("SELECT * FROM shipment_drafts WHERE deleted_at IS NULL AND id = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $so = $pdo->prepare("SELECT order_id FROM shipment_draft_orders WHERE shipment_draft_id = ?");
                $so->execute([$id]);
                $row['order_ids'] = array_column($so->fetchAll(PDO::FETCH_ASSOC), 'order_id');
                if($removed)ShipmentAssignmentService::audit($pdo,'remove_orders',(int)$id,['order_ids'=>$removed],[],$userId);
                if ($startedTransaction) $pdo->commit();
                jsonResponse(['data' => $row]);
                break;
            }
            jsonError('Invalid action', 400);
            break;
    } } catch (ContainerCapacityException $e) {
        jsonResponse(['error'=>true,'over_capacity'=>$e->overCapacity,'message'=>$e->getMessage(),'details'=>$e->details],$e->httpStatus);
    } catch (ShipmentAssignmentException $e) {
        jsonError($e->getMessage(),409);
    } catch (DomainException $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        jsonError($e->getMessage(),$e->getCode()?:409);
    }
    jsonError('Method not allowed', 405);
};
