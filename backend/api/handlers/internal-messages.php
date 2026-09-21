<?php

/**
 * Internal Messages API - chat between staff and customer context
 * Roles: ChinaAdmin, ChinaEmployee, LebanonAdmin, SuperAdmin, WarehouseStaff
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__,2).'/services/OperationReplayService.php';
require_once dirname(__DIR__,2).'/services/CustomerDepositService.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    require_once __DIR__ . '/../authorization.php';
    clmsAuthorizeApiRequest('internal-messages', $method, $id, $action);
    $pdo = getDb();
    $userId = getAuthUserId();
    if (!$userId) jsonError('Unauthorized', 401);
    requirePermission('internal-messages');

    switch ($method) {
        case 'GET':
            require_once dirname(__DIR__,2).'/services/QueryFilterService.php';
            QueryFilterService::validate($_GET,'internal-messages');
            $customerId = $_GET['customer_id'] ?? null;
            $orderId = $_GET['order_id'] ?? null;
            if (!$customerId) jsonError('customer_id required', 400);
            clmsRequireCustomerAccess($pdo, (int) $customerId);
            $sql = "SELECT m.*, u.full_name as sender_name FROM internal_messages m LEFT JOIN users u ON m.sender_id = u.id WHERE m.customer_id = ?";
            $params = [$customerId];
            if ($orderId) {
                $sql .= " AND (m.order_id = ? OR m.order_id IS NULL)";
                $params[] = $orderId;
            }
            $sql .= " ORDER BY m.created_at ASC, m.id ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $readIds=array_column($rows,'id');
            if($readIds){$marks=implode(',',array_fill(0,count($readIds),'?'));$pdo->prepare("UPDATE internal_messages SET read_at=NOW() WHERE id IN ($marks) AND read_at IS NULL AND sender_id != ?")->execute([...$readIds,$userId]);}
            jsonResponse(['data' => $rows]);

        case 'POST':
            $customerId = (int)OrderWriteService::number($input['customer_id']??null,'Customer',true,0,4294967295);
            if (!$customerId) jsonError('customer_id required', 400);
            clmsRequireCustomerAccess($pdo, $customerId);
            if(!is_string($input['body']??null)||strlen($input['body'])>65535)jsonError('Message must be text within the supported length',422);
            $body = trim($input['body']);
            if (strlen($body) < 1) jsonError('Message body required', 400);
            $orderId = OrderWriteService::number($input['order_id']??null,'Order',true,0,4294967295)?:null;
            $containerId = OrderWriteService::number($input['container_id']??null,'Container',true,0,4294967295)?:null;
            $claim=OperationReplayService::claim($pdo,'internal_message',$input,$userId);
            if($claim['previous_id']){$s=$pdo->prepare('SELECT * FROM internal_messages WHERE id=?');$s->execute([$claim['previous_id']]);jsonResponse(['data'=>$s->fetch(PDO::FETCH_ASSOC),'idempotent_replay'=>true]);}
            $pdo->beginTransaction();register_shutdown_function(static function()use($pdo){if($pdo->inTransaction())$pdo->rollBack();});
            if($containerId){$s=$pdo->prepare('SELECT id FROM containers WHERE id=? FOR UPDATE');$s->execute([$containerId]);if(!$s->fetchColumn())jsonError('Container not found',404);$s=$pdo->prepare('SELECT o.id FROM orders o JOIN shipment_draft_orders sdo ON sdo.order_id=o.id JOIN shipment_drafts sd ON sd.id=sdo.shipment_draft_id WHERE sd.container_id=? AND o.customer_id=?'.($orderId?' AND o.id=?':'').' LIMIT 1');$s->execute($orderId?[$containerId,$customerId,$orderId]:[$containerId,$customerId]);if(!$s->fetchColumn())jsonError('Container has no matching customer cargo',422);}
            CustomerDepositService::lockPartyAndOrder($pdo,$customerId,$orderId);
            $pdo->prepare("INSERT INTO internal_messages (customer_id, order_id, container_id, sender_id, body) VALUES (?,?,?,?,?)")
                ->execute([$customerId, $orderId, $containerId, $userId, $body]);
            $newId = (int) $pdo->lastInsertId();
            OperationReplayService::record($pdo,'internal_message',$newId,$claim,['customer_id'=>$customerId,'order_id'=>$orderId,'container_id'=>$containerId],$userId);$pdo->commit();
            $stmt = $pdo->prepare("SELECT m.*, u.full_name as sender_name FROM internal_messages m LEFT JOIN users u ON m.sender_id = u.id WHERE m.id = ?");
            $stmt->execute([$newId]);
            jsonResponse(['data' => $stmt->fetch(PDO::FETCH_ASSOC)], 201);

        default:
            jsonError('Method not allowed', 405);
    }
};
