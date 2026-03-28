<?php

/**
 * Dashboard API - actionable counts, my tasks (minimum input, maximum output)
 * GET /dashboard/stats
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 3) . '/includes/sidebar_permissions.php';

function dashboardWarehouseReceiptHasColumn(PDO $pdo, string $column): bool
{
    static $cache = [];
    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM warehouse_receipts LIKE ?");
        $stmt->execute([$column]);
        $cache[$column] = (bool) $stmt->rowCount();
    } catch (Throwable $e) {
        $cache[$column] = false;
    }

    return $cache[$column];
}

return function (string $method, ?string $id, ?string $action, array $input) {
    if ($method !== 'GET' || $id !== 'stats') {
        jsonError('Not found', 404);
    }

    $pdo = getDb();
    $userRoles = getUserRoles();
    $canSeePage = static fn(string $pageId) => clmsCanRolesAccessPage($userRoles, $pageId, $pdo);

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
            'CustomerDeclinedAfterAutoConfirm' => 'declined_after_auto_confirm',
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

    $stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE COALESCE(confirmation_token, '') <> ''");
    $stats['customer_feedback_pending'] = (int) $stmt->fetchColumn();

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
    if ((in_array('ChinaAdmin', $userRoles) || in_array('ChinaEmployee', $userRoles) || in_array('SuperAdmin', $userRoles)) && $canSeePage('orders')) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'Submitted'");
        $submitted = (int) $stmt->fetchColumn();
        if ($submitted > 0) {
            $stats['my_tasks'][] = ['label' => 'Orders to approve', 'count' => $submitted, 'url' => '/cargochina/orders.php?status=Submitted'];
        }
        $stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE COALESCE(confirmation_token, '') <> ''");
        $feedbackPending = (int) $stmt->fetchColumn();
        if ($feedbackPending > 0) {
            $stats['my_tasks'][] = ['label' => 'Customer feedback pending', 'count' => $feedbackPending, 'url' => '/cargochina/orders.php?customer_feedback=pending'];
        }
    }
    if ((in_array('WarehouseStaff', $userRoles) || in_array('SuperAdmin', $userRoles)) && $canSeePage('receiving')) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('Approved','InTransitToWarehouse')");
        $toReceive = (int) $stmt->fetchColumn();
        if ($toReceive > 0) {
            $stats['my_tasks'][] = ['label' => 'To receive', 'count' => $toReceive, 'url' => '/cargochina/receiving.php'];
        }
    }
    if ((in_array('ChinaAdmin', $userRoles) || in_array('LebanonAdmin', $userRoles) || in_array('SuperAdmin', $userRoles)) && $canSeePage('consolidation')) {
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
    if ((in_array('ChinaAdmin', $userRoles) || in_array('ChinaEmployee', $userRoles) || in_array('LebanonAdmin', $userRoles) || in_array('SuperAdmin', $userRoles)) && $canSeePage('orders')) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'CustomerDeclinedAfterAutoConfirm'");
        $declinedAfter = (int) $stmt->fetchColumn();
        if ($declinedAfter > 0) {
            $stats['my_tasks'][] = ['label' => 'Declined after auto-confirm', 'count' => $declinedAfter, 'url' => '/cargochina/orders.php?customer_feedback=declined_after_auto_confirm'];
        }
    }

    // Stale order counts — orders stuck in customer feedback/approval beyond configured threshold
    $threshold = (int) ($pdo->query("SELECT key_value FROM system_config WHERE key_name='STALE_ORDER_THRESHOLD_DAYS' LIMIT 1")->fetchColumn() ?: 3);
    $staleReceiptWhere = dashboardWarehouseReceiptHasColumn($pdo, 'voided_at')
        ? ' AND wr.voided_at IS NULL'
        : '';
    $staleConfirm = $pdo->prepare("SELECT COUNT(*) FROM orders o JOIN warehouse_receipts wr ON wr.order_id = o.id WHERE COALESCE(o.confirmation_token, '') <> ''$staleReceiptWhere AND wr.received_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
    $staleConfirm->execute([$threshold]);
    $stats['stale_customer_feedback'] = (int) $staleConfirm->fetchColumn();

    $staleApproved = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status IN ('Approved','InTransitToWarehouse') AND expected_ready_date < DATE_SUB(CURDATE(), INTERVAL ? DAY)");
    $staleApproved->execute([$threshold]);
    $stats['stale_overdue'] = (int) $staleApproved->fetchColumn();
    $stats['stale_threshold_days'] = $threshold;

    jsonResponse(['data' => $stats]);
};
