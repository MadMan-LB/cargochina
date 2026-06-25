<?php

/**
 * CLMS API Router - REST v1
 * Routes: /api/v1/{resource} -> backend/api/index.php
 */

require_once dirname(__DIR__, 2) . '/backend/config/runtime.php';

$GLOBALS['__clms_api_start'] = microtime(true);
$GLOBALS['__clms_api_method'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$GLOBALS['__clms_api_path'] = '/' . trim((string) ($_GET['path'] ?? ''), '/');
$GLOBALS['__clms_api_slow_threshold_ms'] = 800.0;
$GLOBALS['__clms_api_request_id'] = null;
$GLOBALS['__clms_api_timing_finalized'] = false;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CLMS-Debug-Timing');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$path = $_GET['path'] ?? '';
$path = trim($path, '/');
$parts = $path ? explode('/', $path) : [];

$resource = $parts[0] ?? '';
$id = $parts[1] ?? null;
$action = count($parts) > 2 ? implode('/', array_slice($parts, 2)) : null;

$handlerFile = __DIR__ . '/handlers/' . $resource . '.php';
if (!file_exists($handlerFile)) {
    http_response_code(404);
    echo json_encode(['error' => 'Not Found', 'message' => "Resource '$resource' not found"]);
    exit;
}

require_once dirname(__DIR__, 2) . '/backend/config/database.php';
require_once __DIR__ . '/helpers.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar_permissions.php';

$method = $_SERVER['REQUEST_METHOD'];
$input = [];
if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
    $raw = file_get_contents('php://input');
    $input = $raw ? (json_decode($raw, true) ?? []) : $_POST;
}

// RBAC: public resources skip auth
$rbac = require dirname(__DIR__, 2) . '/backend/config/rbac.php';
$publicResources = $rbac['public'] ?? [];
if (!in_array($resource, $publicResources)) {
    $userId = getAuthUserId();
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['error' => true, 'message' => 'Unauthorized']);
        exit;
    }
    $timingRequested = (string) ($_SERVER['HTTP_X_CLMS_DEBUG_TIMING'] ?? '') === '1'
        || (string) ($_GET['debug_timing'] ?? '') === '1';
    $GLOBALS['__clms_api_timing_debug'] = $timingRequested && hasAnyRole(['SuperAdmin']);
    if ($resource === 'orders' && $action === 'approve' && !hasPermission('orders.approve', $rbac['orders']['approve'] ?? [])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'orders' && $action === 'receive' && !hasPermission('orders.receive', $rbac['orders']['receive'] ?? [])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'orders' && $action === 'confirm' && !hasPermission('orders.confirm', $rbac['orders']['confirm'] ?? [])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'balances') {
        $userRoles = getUserRoles();
        if (!in_array('SuperAdmin', $userRoles, true) && !clmsCanRolesAccessPage($userRoles, 'balances', null, $userId)) {
            http_response_code(403);
            echo json_encode(['error' => true, 'message' => clmsT('You do not have permission')]);
            exit;
        }
    }

    $resourcePermissions = $rbac[$resource] ?? null;
    $permissionKey = $method === 'GET' ? 'read' : 'write';
    $skipGenericPermission = ($resource === 'orders' && in_array($action, ['approve', 'receive', 'confirm'], true))
        || ($resource === 'customers' && ($method === 'GET' || ($method === 'POST' && ($id === null || $id === 'import'))))
        || $resource === 'balances';
    if (
        !$skipGenericPermission &&
        is_array($resourcePermissions) &&
        array_key_exists($permissionKey, $resourcePermissions) &&
        !hasPermission($resource . '.' . $permissionKey, $resourcePermissions[$permissionKey] ?? [])
    ) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'users' && !hasAnyRole($rbac['users'] ?? [])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'roles' && !hasAnyRole($rbac['users'] ?? [])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'config' && $id !== 'receiving' && $id !== 'upload' && $id !== 'container-presets' && $id !== 'eta-offsets' && !hasAnyRole($rbac['config'] ?? [])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if (($resource === 'config' && ($id === 'container-presets' || $id === 'eta-offsets')) && !hasAnyRole($rbac['containers']['read'] ?? ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'config' && $id === 'receiving' && !hasPermission('page:receiving', ['WarehouseStaff', 'SuperAdmin'])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'customers' && $method === 'GET') {
        $userRoles = getUserRoles();
        $isLookup = $id === 'lookup' || $action === 'lookup';
        $allowed = $isLookup
            ? hasPermission('customers.lookup', $rbac['customers']['lookup'] ?? [])
            : (hasPermission('customers.read', $rbac['customers']['read'] ?? []) || clmsCanRolesAccessPage($userRoles, 'customers', null, $userId));
        if (!$allowed) {
            http_response_code(403);
            echo json_encode(['error' => true, 'message' => 'Forbidden']);
            exit;
        }
    }
    if ($resource === 'customers' && $method === 'POST' && $id === null && !hasPermission('customers.create', [])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'customers' && $id === 'import' && !hasPermission('customers.import', $rbac['customers']['import'] ?? ['ChinaAdmin', 'SuperAdmin'])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'suppliers' && $id === 'import' && !hasAnyRole(['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin'])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'products' && $id === 'import' && !hasAnyRole(['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin'])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'shipment-drafts' && $action === 'push' && !hasAnyRole($rbac['shipment-drafts']['push'] ?? [])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'shipment-drafts' && $action === 'finalize' && !hasAnyRole($rbac['shipment-drafts']['finalize'] ?? [])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'tracking-push-log' && !hasAnyRole(['LebanonAdmin', 'SuperAdmin'])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'diagnostics' && !hasAnyRole(['SuperAdmin'])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'receiving' && $id === 'import' && !hasPermission('receiving.import', ['WarehouseStaff', 'SuperAdmin'])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'receiving' && !hasPermission('page:receiving', ['WarehouseStaff', 'SuperAdmin'])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'expenses' && !hasAnyRole($rbac['expenses'] ?? ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'financials' && !hasAnyRole($rbac['financials'] ?? [])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'internal-messages' && !hasAnyRole($rbac['internal-messages'] ?? [])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'warehouse-stock' && !hasAnyRole($rbac['warehouse-stock'] ?? [])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'procurement-drafts' && !hasPermission('page:procurement_drafts', $rbac['procurement-drafts'] ?? [])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'business-settings' && !hasAnyRole($rbac['business-settings'] ?? [])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'customer-portal-tokens' && !hasAnyRole($rbac['customer-portal-tokens'] ?? [])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'design-attachments' && !hasAnyRole($rbac['design-attachments'] ?? [])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'draft-orders' && !hasPermission('page:procurement_drafts', $rbac['draft-orders'] ?? [])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
}

try {
    $handler = require $handlerFile;
    $handler($method, $id, $action, $input);
} catch (Throwable $e) {
    $requestId = bin2hex(random_bytes(8));
    $GLOBALS['__clms_api_request_id'] = $requestId;
    $logDir = dirname(__DIR__, 2) . '/logs';
    if (is_dir($logDir)) {
        @error_log(date('Y-m-d H:i:s') . " [{$requestId}] " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", 3, $logDir . '/php_errors.log');
    }
    $message = clmsIsDebugEnabled()
        ? $e->getMessage() . ' (ref: ' . $requestId . ')'
        : clmsT('An error occurred. Please try again or contact support. (ref: {ref})', ['ref' => $requestId]);
    http_response_code(500);
    clmsFinalizeApiTiming(500);
    echo json_encode([
        'error' => true,
        'message' => $message,
        'request_id' => $requestId,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
