<?php

/**
 * Tracking Push Log API - list push attempts (admin)
 * GET /tracking-push-log?entity_type=shipment_draft&entity_id=1&failed_only=1
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();

    if ($method !== 'GET') {
        jsonError('Method not allowed', 405);
    }

    $entityType = $_GET['entity_type'] ?? 'shipment_draft';
    $entityId = $_GET['entity_id'] ?? null;
    $failedOnly = isset($_GET['failed_only']) && $_GET['failed_only'] !== '0' && $_GET['failed_only'] !== '';

    $sql = "SELECT id, entity_type, entity_id, idempotency_key, status, response_code, external_id, attempt_count, last_error, created_at, updated_at FROM tracking_push_log WHERE 1=1";
    $params = [];
    if ($entityType) {
        $sql .= " AND entity_type = ?";
        $params[] = $entityType;
    }
    if ($entityId !== null && $entityId !== '') {
        $sql .= " AND entity_id = ?";
        $params[] = $entityId;
    }
    if ($failedOnly) {
        $sql .= " AND status = 'failed'";
    }
    $sql .= " ORDER BY updated_at DESC LIMIT 50";

    $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
    if ($params) $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(['data' => $rows]);
};
