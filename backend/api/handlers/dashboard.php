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
    setCacheHeaders(15);
    $userRoles = getUserRoles();
    $userId = getAuthUserId() ?? 0;
    $canSeePage = static fn(string $pageId) => clmsCanRolesAccessPage($userRoles, $pageId, $pdo, $userId ?: null);

    $statusKeyMap = [
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
    ];
    $stats = array_fill_keys(array_values($statusKeyMap), 0);

    $customerScope = clmsCustomerVisibilityClause($pdo, 'c');
    $statusStmt = $pdo->prepare(
        "SELECT o.status, COUNT(*) AS total
         FROM orders o
         JOIN customers c ON o.customer_id = c.id
         WHERE {$customerScope['sql']}
         GROUP BY o.status"
    );
    $statusStmt->execute($customerScope['params']);
    foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status = $row['status'] ?? '';
        if (isset($statusKeyMap[$status])) {
            $stats[$statusKeyMap[$status]] = (int) ($row['total'] ?? 0);
        }
    }

    $stats['pending_receiving'] = ($stats['approved'] ?? 0) + ($stats['in_transit'] ?? 0);

    $stmt = $pdo->query("SELECT COUNT(*) FROM shipment_drafts WHERE status = 'draft'");
    $stats['draft_shipments'] = (int) $stmt->fetchColumn();

    $feedbackScope = clmsCustomerVisibilityClause($pdo, 'cf');
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM orders o
         JOIN customers cf ON o.customer_id = cf.id
         WHERE COALESCE(o.confirmation_token, '') <> ''
           AND {$feedbackScope['sql']}"
    );
    $stmt->execute($feedbackScope['params']);
    $stats['customer_feedback_pending'] = (int) $stmt->fetchColumn();

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
        $submitted = (int) ($stats['submitted'] ?? 0);
        if ($submitted > 0) {
            $stats['my_tasks'][] = ['label' => 'Orders to approve', 'count' => $submitted, 'url' => '/cargochina/orders.php?status=Submitted'];
        }
        $feedbackPending = (int) ($stats['customer_feedback_pending'] ?? 0);
        if ($feedbackPending > 0) {
            $stats['my_tasks'][] = ['label' => 'Customer feedback pending', 'count' => $feedbackPending, 'url' => '/cargochina/orders.php?customer_feedback=pending'];
        }
    }
    if ((in_array('WarehouseStaff', $userRoles) || in_array('SuperAdmin', $userRoles)) && $canSeePage('receiving')) {
        $toReceive = (int) ($stats['pending_receiving'] ?? 0);
        if ($toReceive > 0) {
            $stats['my_tasks'][] = ['label' => 'To receive', 'count' => $toReceive, 'url' => '/cargochina/receiving.php'];
        }
    }
    if ((in_array('ChinaAdmin', $userRoles) || in_array('LebanonAdmin', $userRoles) || in_array('SuperAdmin', $userRoles)) && $canSeePage('consolidation')) {
        $ready = (int) ($stats['ready_for_consolidation'] ?? 0);
        if ($ready > 0) {
            $stats['my_tasks'][] = ['label' => 'Ready to consolidate', 'count' => $ready, 'url' => '/cargochina/consolidation.php'];
        }
        $drafts = (int) ($stats['draft_shipments'] ?? 0);
        if ($drafts > 0) {
            $stats['my_tasks'][] = ['label' => 'Shipment drafts', 'count' => $drafts, 'url' => '/cargochina/consolidation.php'];
        }
    }
    if ((in_array('ChinaAdmin', $userRoles) || in_array('ChinaEmployee', $userRoles) || in_array('LebanonAdmin', $userRoles) || in_array('SuperAdmin', $userRoles)) && $canSeePage('orders')) {
        $declinedAfter = (int) ($stats['declined_after_auto_confirm'] ?? 0);
        if ($declinedAfter > 0) {
            $stats['my_tasks'][] = ['label' => 'Declined after auto-confirm', 'count' => $declinedAfter, 'url' => '/cargochina/orders.php?customer_feedback=declined_after_auto_confirm'];
        }
    }

    // Stale order counts — orders stuck in customer feedback/approval beyond configured threshold
    $threshold = (int) ($pdo->query("SELECT key_value FROM system_config WHERE key_name='STALE_ORDER_THRESHOLD_DAYS' LIMIT 1")->fetchColumn() ?: 3);
    $staleReceiptWhere = dashboardWarehouseReceiptHasColumn($pdo, 'voided_at')
        ? ' AND wr.voided_at IS NULL'
        : '';
    $staleConfirmScope = clmsCustomerVisibilityClause($pdo, 'csc');
    $staleConfirm = $pdo->prepare(
        "SELECT COUNT(*)
         FROM orders o
         JOIN customers csc ON o.customer_id = csc.id
         JOIN warehouse_receipts wr ON wr.order_id = o.id
         WHERE COALESCE(o.confirmation_token, '') <> ''
           $staleReceiptWhere
           AND wr.received_at < DATE_SUB(NOW(), INTERVAL ? DAY)
           AND {$staleConfirmScope['sql']}"
    );
    $staleConfirm->execute(array_merge([$threshold], $staleConfirmScope['params']));
    $stats['stale_customer_feedback'] = (int) $staleConfirm->fetchColumn();

    $staleApprovedScope = clmsCustomerVisibilityClause($pdo, 'csa');
    $staleApproved = $pdo->prepare(
        "SELECT COUNT(*)
         FROM orders o
         JOIN customers csa ON o.customer_id = csa.id
         WHERE o.status IN ('Approved','InTransitToWarehouse')
           AND o.expected_ready_date < DATE_SUB(CURDATE(), INTERVAL ? DAY)
           AND {$staleApprovedScope['sql']}"
    );
    $staleApproved->execute(array_merge([$threshold], $staleApprovedScope['params']));
    $stats['stale_overdue'] = (int) $staleApproved->fetchColumn();
    $stats['stale_threshold_days'] = $threshold;

    jsonResponse(['data' => $stats]);
};
