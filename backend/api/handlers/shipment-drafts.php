<?php

/**
 * Shipment Drafts API - create, add orders, assign container, finalize
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 2) . '/backend/services/TrackingPushService.php';
require_once dirname(__DIR__, 2) . '/backend/services/NotificationService.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();
    $userId = getAuthUserId() ?? 1;

    switch ($method) {
        case 'GET':
            if ($id === null) {
                $stmt = $pdo->query("SELECT sd.*, c.code as container_code FROM shipment_drafts sd LEFT JOIN containers c ON sd.container_id = c.id ORDER BY sd.id DESC");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $svc = new TrackingPushService($pdo);
                foreach ($rows as &$r) {
                    $so = $pdo->prepare("SELECT order_id FROM shipment_draft_orders WHERE shipment_draft_id = ?");
                    $so->execute([$r['id']]);
                    $r['order_ids'] = array_column($so->fetchAll(PDO::FETCH_ASSOC), 'order_id');
                    $pushStatus = $svc->getPushStatus((int) $r['id']);
                    $r['push_status'] = $pushStatus ? $pushStatus['status'] : null;
                    $r['push_last_error'] = $pushStatus['last_error'] ?? null;
                }
                jsonResponse(['data' => $rows]);
            }
            $stmt = $pdo->prepare("SELECT sd.*, c.code as container_code, c.max_cbm, c.max_weight FROM shipment_drafts sd LEFT JOIN containers c ON sd.container_id = c.id WHERE sd.id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) jsonError('Shipment draft not found', 404);
            $so = $pdo->prepare("SELECT order_id FROM shipment_draft_orders WHERE shipment_draft_id = ?");
            $so->execute([$id]);
            $orderIds = array_column($so->fetchAll(PDO::FETCH_ASSOC), 'order_id');
            $row['order_ids'] = $orderIds;
            if (!empty($orderIds)) {
                $ph = implode(',', array_fill(0, count($orderIds), '?'));
                $tot = $pdo->prepare("SELECT COALESCE(SUM(declared_cbm),0), COALESCE(SUM(declared_weight),0) FROM order_items WHERE order_id IN ($ph)");
                $tot->execute($orderIds);
                $t = $tot->fetch(PDO::FETCH_NUM);
                $row['total_cbm'] = (float) $t[0];
                $row['total_weight'] = (float) $t[1];
            } else {
                $row['total_cbm'] = 0;
                $row['total_weight'] = 0;
            }
            jsonResponse(['data' => $row]);
            break;

        case 'POST':
            if ($id === null) {
                $pdo->prepare("INSERT INTO shipment_drafts (status) VALUES ('draft')")->execute();
                $newId = (int) $pdo->lastInsertId();
                $stmt = $pdo->prepare("SELECT * FROM shipment_drafts WHERE id = ?");
                $stmt->execute([$newId]);
                jsonResponse(['data' => array_merge($stmt->fetch(PDO::FETCH_ASSOC), ['order_ids' => []])], 201);
                break;
            }
            if ($action === 'add-orders') {
                $orderIds = $input['order_ids'] ?? [];
                $eligible = ['ReadyForConsolidation', 'Confirmed'];
                foreach ($orderIds as $oid) {
                    $st = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
                    $st->execute([$oid]);
                    $s = $st->fetchColumn();
                    if (!in_array($s, $eligible)) {
                        jsonError("Order $oid is not eligible (must be ReadyForConsolidation or Confirmed)", 400);
                    }
                }
                $ins = $pdo->prepare("INSERT IGNORE INTO shipment_draft_orders (shipment_draft_id, order_id) VALUES (?,?)");
                foreach ($orderIds as $oid) {
                    $ins->execute([$id, $oid]);
                }
                if (!empty($orderIds)) {
                    $ph = implode(',', array_fill(0, count($orderIds), '?'));
                    $pdo->prepare("UPDATE orders SET status='ConsolidatedIntoShipmentDraft' WHERE id IN ($ph)")->execute($orderIds);
                }
                $stmt = $pdo->prepare("SELECT * FROM shipment_drafts WHERE id = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $so = $pdo->prepare("SELECT order_id FROM shipment_draft_orders WHERE shipment_draft_id = ?");
                $so->execute([$id]);
                $row['order_ids'] = array_column($so->fetchAll(PDO::FETCH_ASSOC), 'order_id');
                jsonResponse(['data' => $row]);
                break;
            }
            if ($action === 'assign-container') {
                $containerId = (int) ($input['container_id'] ?? 0);
                if (!$containerId) jsonError('container_id required', 400);
                $so = $pdo->prepare("SELECT order_id FROM shipment_draft_orders WHERE shipment_draft_id = ?");
                $so->execute([$id]);
                $orderIds = array_column($so->fetchAll(PDO::FETCH_ASSOC), 'order_id');
                $totalCbm = 0.0;
                $totalWeight = 0.0;
                if (!empty($orderIds)) {
                    $ph = implode(',', array_fill(0, count($orderIds), '?'));
                    $totals = $pdo->prepare("SELECT COALESCE(SUM(declared_cbm),0), COALESCE(SUM(declared_weight),0) FROM order_items WHERE order_id IN ($ph)");
                    $totals->execute($orderIds);
                    $row = $totals->fetch(PDO::FETCH_NUM);
                    $totalCbm = (float) $row[0];
                    $totalWeight = (float) $row[1];
                }
                $c = $pdo->prepare("SELECT max_cbm, max_weight FROM containers WHERE id = ?");
                $c->execute([$containerId]);
                $cont = $c->fetch(PDO::FETCH_ASSOC);
                if (!$cont) jsonError('Container not found', 404);
                if ($totalCbm > $cont['max_cbm'] || $totalWeight > $cont['max_weight']) {
                    jsonError('Capacity exceeded: total CBM/weight exceeds container limits', 400);
                }
                $pdo->prepare("UPDATE shipment_drafts SET container_id = ? WHERE id = ?")->execute([$containerId, $id]);
                $ph = implode(',', array_fill(0, count($orderIds), '?'));
                $pdo->prepare("UPDATE orders SET status='AssignedToContainer' WHERE id IN ($ph)")->execute($orderIds);
                $stmt = $pdo->prepare("SELECT sd.*, c.code as container_code FROM shipment_drafts sd LEFT JOIN containers c ON sd.container_id = c.id WHERE sd.id = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $so->execute([$id]);
                $row['order_ids'] = array_column($so->fetchAll(PDO::FETCH_ASSOC), 'order_id');
                jsonResponse(['data' => $row]);
                break;
            }
            if ($action === 'finalize') {
                $stmt = $pdo->prepare("SELECT * FROM shipment_drafts WHERE id = ?");
                $stmt->execute([$id]);
                $sd = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$sd || $sd['status'] === 'finalized') jsonError('Invalid or already finalized', 400);
                $so = $pdo->prepare("SELECT order_id FROM shipment_draft_orders WHERE shipment_draft_id = ?");
                $so->execute([$id]);
                $orderIds = array_column($so->fetchAll(PDO::FETCH_ASSOC), 'order_id');
                if (empty($orderIds)) jsonError('Draft must have at least one order to finalize', 400);
                foreach ($orderIds as $oid) {
                    $st = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
                    $st->execute([$oid]);
                    if ($st->fetchColumn() !== 'AssignedToContainer') {
                        jsonError("Order $oid must be AssignedToContainer before finalizing", 400);
                    }
                }
                $pdo->prepare("UPDATE shipment_drafts SET status='finalized' WHERE id=?")->execute([$id]);
                $ph = implode(',', array_fill(0, count($orderIds), '?'));
                $pdo->prepare("UPDATE orders SET status='FinalizedAndPushedToTracking' WHERE id IN ($ph)")->execute($orderIds);
                $config = require dirname(__DIR__, 2) . '/backend/config/config.php';
                $pushEnabled = (int) ($config['tracking_push_enabled'] ?? 0);
                $trackingResult = null;
                if ($pushEnabled) {
                    try {
                        $svc = new TrackingPushService($pdo);
                        $trackingResult = $svc->push($id);
                    } catch (Throwable $e) {
                        $trackingResult = ['success' => false, 'message' => $e->getMessage(), 'push_failed' => true];
                    }
                }
                (new NotificationService($pdo))->notifyShipmentFinalized($id, count($orderIds));
                $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('shipment_draft',?,?,?,?)")
                    ->execute([$id, 'finalize', json_encode(['order_ids' => $orderIds, 'tracking_result' => $trackingResult]), $userId]);
                jsonResponse(['data' => ['status' => 'finalized', 'tracking_result' => $trackingResult]]);
                break;
            }
            if ($action === 'push') {
                $stmt = $pdo->prepare("SELECT * FROM shipment_drafts WHERE id = ?");
                $stmt->execute([$id]);
                $sd = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$sd) jsonError('Shipment draft not found', 404);
                if ($sd['status'] !== 'finalized') jsonError('Draft must be finalized before push/retry', 400);
                $svc = new TrackingPushService($pdo);
                $result = $svc->push($id);
                jsonResponse(['data' => $result]);
                break;
            }
            if ($action === 'remove-orders') {
                $orderIds = $input['order_ids'] ?? [];
                if (empty($orderIds)) jsonError('order_ids required', 400);
                $del = $pdo->prepare("DELETE FROM shipment_draft_orders WHERE shipment_draft_id = ? AND order_id = ?");
                foreach ($orderIds as $oid) {
                    $del->execute([$id, $oid]);
                    $pdo->prepare("UPDATE orders SET status='ReadyForConsolidation' WHERE id = ? AND status = 'ConsolidatedIntoShipmentDraft'")->execute([$oid]);
                }
                $stmt = $pdo->prepare("SELECT * FROM shipment_drafts WHERE id = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $so = $pdo->prepare("SELECT order_id FROM shipment_draft_orders WHERE shipment_draft_id = ?");
                $so->execute([$id]);
                $row['order_ids'] = array_column($so->fetchAll(PDO::FETCH_ASSOC), 'order_id');
                jsonResponse(['data' => $row]);
                break;
            }
            jsonError('Invalid action', 400);
            break;
    }

    jsonError('Method not allowed', 405);
};
