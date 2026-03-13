<?php

/**
 * Dashboard API - actionable counts, my tasks (minimum input, maximum output)
 * GET /dashboard/stats
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    if ($method !== 'GET' || $id !== 'stats') {
        jsonError('Not found', 404);
    }

    $pdo = getDb();
    $userRoles = getUserRoles();

    $stats = [];
    foreach (
        [
            'Draft' => 'draft',
            'Submitted' => 'submitted',
            'Approved' => 'approved',
            'InTransitToWarehouse' => 'in_transit',
            'ReceivedAtWarehouse' => 'received',
            'AwaitingCustomerConfirmation' => 'awaiting_confirmation',
            'Confirmed' => 'confirmed',
            'ReadyForConsolidation' => 'ready_for_consolidation',
            'ConsolidatedIntoShipmentDraft' => 'in_shipment_draft',
            'AssignedToContainer' => 'assigned_to_container',
            'FinalizedAndPushedToTracking' => 'finalized',
        ] as $status => $key
    ) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status = ?");
        $stmt->execute([$status]);
        $stats[$key] = (int) $stmt->fetchColumn();
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('Approved','InTransitToWarehouse')");
    $stats['pending_receiving'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM shipment_drafts WHERE status = 'draft'");
    $stats['draft_shipments'] = (int) $stmt->fetchColumn();

    $userId = getAuthUserId() ?? 0;
    if ($userId) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL");
        $stmt->execute([$userId]);
        $stats['unread_notifications'] = (int) $stmt->fetchColumn();
    } else {
        $stats['unread_notifications'] = 0;
    }

    // My tasks — role-scoped actionable counts
    $stats['my_tasks'] = [];
    if (in_array('ChinaAdmin', $userRoles) || in_array('ChinaEmployee', $userRoles) || in_array('SuperAdmin', $userRoles)) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'Submitted'");
        $submitted = (int) $stmt->fetchColumn();
        if ($submitted > 0) {
            $stats['my_tasks'][] = ['label' => 'Orders to approve', 'count' => $submitted, 'url' => '/cargochina/orders.php?status=Submitted'];
        }
        $stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'AwaitingCustomerConfirmation'");
        $awaitConfirm = (int) $stmt->fetchColumn();
        if ($awaitConfirm > 0) {
            $stats['my_tasks'][] = ['label' => 'Awaiting customer confirmation', 'count' => $awaitConfirm, 'url' => '/cargochina/orders.php?status=AwaitingCustomerConfirmation'];
        }
    }
    if (in_array('WarehouseStaff', $userRoles) || in_array('SuperAdmin', $userRoles)) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('Approved','InTransitToWarehouse')");
        $toReceive = (int) $stmt->fetchColumn();
        if ($toReceive > 0) {
            $stats['my_tasks'][] = ['label' => 'To receive', 'count' => $toReceive, 'url' => '/cargochina/receiving.php'];
        }
    }
    if (in_array('ChinaAdmin', $userRoles) || in_array('LebanonAdmin', $userRoles) || in_array('SuperAdmin', $userRoles)) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'ReadyForConsolidation'");
        $ready = (int) $stmt->fetchColumn();
        if ($ready > 0) {
            $stats['my_tasks'][] = ['label' => 'Ready to consolidate', 'count' => $ready, 'url' => '/cargochina/consolidation.php'];
        }
        $stmt = $pdo->query("SELECT COUNT(*) FROM shipment_drafts WHERE status = 'draft'");
        $drafts = (int) $stmt->fetchColumn();
        if ($drafts > 0) {
            $stats['my_tasks'][] = ['label' => 'Shipment drafts', 'count' => $drafts, 'url' => '/cargochina/consolidation.php'];
        }
    }

    // Stale order counts — orders stuck in confirmation/approval beyond configured threshold
    $threshold = (int) ($pdo->query("SELECT key_value FROM system_config WHERE key_name='STALE_ORDER_THRESHOLD_DAYS' LIMIT 1")->fetchColumn() ?: 3);
    $staleConfirm = $pdo->prepare("SELECT COUNT(*) FROM orders o JOIN warehouse_receipts wr ON wr.order_id = o.id WHERE o.status = 'AwaitingCustomerConfirmation' AND wr.received_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
    $staleConfirm->execute([$threshold]);
    $stats['stale_awaiting_confirmation'] = (int) $staleConfirm->fetchColumn();

    $staleApproved = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status IN ('Approved','InTransitToWarehouse') AND expected_ready_date < DATE_SUB(CURDATE(), INTERVAL ? DAY)");
    $staleApproved->execute([$threshold]);
    $stats['stale_overdue'] = (int) $staleApproved->fetchColumn();
    $stats['stale_threshold_days'] = $threshold;

    jsonResponse(['data' => $stats]);
};
