<?php

/**
 * Tracking Push Log API - list push attempts (admin)
 * GET /tracking-push-log?entity_type=shipment_draft&entity_id=1&failed_only=1
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    require_once __DIR__ . '/../authorization.php';
    clmsAuthorizeApiRequest('tracking-push-log', $method, $id, $action);
    $pdo = getDb();
    if ($method === 'GET') { require_once dirname(__DIR__, 2) . '/services/QueryFilterService.php'; QueryFilterService::validate($_GET, 'tracking-push-log'); }
    if(!hasAnyRole(['LebanonAdmin','SuperAdmin']))jsonError('Forbidden',403);

    if ($method !== 'GET') {
        jsonError('Method not allowed', 405);
    }

    $entityType = $_GET['entity_type'] ?? 'shipment_draft';
    $entityId = $_GET['entity_id'] ?? null;
    if($entityType!=='shipment_draft')jsonError('Invalid tracking entity type',422);
    foreach(['entity_id'=>1,'limit'=>1,'offset'=>0] as $field=>$minimum)if(isset($_GET[$field])&&$_GET[$field]!==''&&(filter_var($_GET[$field],FILTER_VALIDATE_INT)===false||(int)$_GET[$field]<$minimum))jsonError("Invalid $field",422);
    if(isset($_GET['failed_only'])&&(!is_scalar($_GET['failed_only'])||!in_array((string)$_GET['failed_only'],['0','1',''],true)))jsonError('Invalid failed-only filter',422);
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
    $count=$pdo->prepare("SELECT COUNT(*) FROM ($sql) tracking_filtered");$count->execute($params);$total=(int)$count->fetchColumn();
    $limit=clmsQueryLimit($_GET['limit']??null,50,200);$offset=clmsQueryOffset($_GET['offset']??null);
    $sql .= " ORDER BY updated_at DESC,id DESC LIMIT ".($limit+1).' OFFSET '.$offset;

    $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
    if ($params) $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $more=count($rows)>$limit;if($more)$rows=array_slice($rows,0,$limit);
    jsonResponse(['data' => $rows,'meta'=>['total'=>$total,'offset'=>$offset,'limit'=>$limit,'has_more'=>$more]]);
};
