<?php

/**
 * Audit Log API - list with filters (SuperAdmin, ChinaAdmin)
 * GET /audit-log?entity_type=&entity_id=&user_id=&action=&date_from=&date_to=&limit=&offset=
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    if ($method !== 'GET') {
        jsonError('Method not allowed', 405);
    }
    requireRole(['SuperAdmin', 'ChinaAdmin']);

    $pdo = getDb();

    if ($id === 'users') {
        $stmt = $pdo->query("SELECT id, email, full_name FROM users WHERE is_active = 1 ORDER BY full_name, email");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(['data' => $rows]);
    }
    $entityType = trim($_GET['entity_type'] ?? '');
    $entityId = $_GET['entity_id'] ?? null;
    $userId = $_GET['user_id'] ?? null;
    $actionFilter = trim($_GET['action'] ?? '');
    $dateFrom = trim($_GET['date_from'] ?? '');
    $dateTo = trim($_GET['date_to'] ?? '');
    $limit = min(100, max(1, (int) ($_GET['limit'] ?? 50)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));

    $sql = "SELECT a.id, a.entity_type, a.entity_id, a.action, a.old_value, a.new_value, a.user_id, a.created_at, u.full_name as user_name
        FROM audit_log a
        LEFT JOIN users u ON a.user_id = u.id
        WHERE 1=1";
    $params = [];

    if ($entityType !== '') {
        $sql .= " AND a.entity_type = ?";
        $params[] = $entityType;
    }
    if ($entityId !== null && $entityId !== '') {
        $sql .= " AND a.entity_id = ?";
        $params[] = $entityId;
    }
    if ($userId !== null && $userId !== '') {
        $sql .= " AND a.user_id = ?";
        $params[] = $userId;
    }
    if ($actionFilter !== '') {
        $sql .= " AND a.action = ?";
        $params[] = $actionFilter;
    }
    if ($dateFrom !== '') {
        $sql .= " AND DATE(a.created_at) >= ?";
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $sql .= " AND DATE(a.created_at) <= ?";
        $params[] = $dateTo;
    }

    $sql .= " ORDER BY a.created_at DESC LIMIT " . ($limit + 1) . " OFFSET " . $offset;
    $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
    if ($params) {
        $stmt->execute($params);
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);
    }

    jsonResponse(['data' => $rows, 'has_more' => $hasMore]);
};
