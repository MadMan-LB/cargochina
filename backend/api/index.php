<?php

/**
 * CLMS API Router - REST v1
 * Routes: /api/v1/{resource} -> backend/api/index.php
 */

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
$logDir = dirname(__DIR__, 2) . '/logs';
if (is_dir($logDir) || @mkdir($logDir, 0755, true)) {
    @ini_set('error_log', $logDir . '/php_errors.log');
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

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
    if ($resource === 'orders' && $action === 'approve' && !hasAnyRole($rbac['orders']['approve'] ?? [])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'orders' && $action === 'receive' && !hasAnyRole($rbac['orders']['receive'] ?? [])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'orders' && $action === 'confirm' && !hasAnyRole($rbac['orders']['confirm'] ?? [])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    $resourcePermissions = $rbac[$resource] ?? null;
    $permissionKey = $method === 'GET' ? 'read' : 'write';
    $skipGenericPermission = $resource === 'orders' && in_array($action, ['approve', 'receive', 'confirm'], true);
    if (
        !$skipGenericPermission &&
        is_array($resourcePermissions) &&
        array_key_exists($permissionKey, $resourcePermissions) &&
        !hasAnyRole($resourcePermissions[$permissionKey] ?? [])
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
    if ($resource === 'config' && $id === 'receiving' && !hasAnyRole(['WarehouseStaff', 'SuperAdmin'])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'customers' && $id === 'import' && !hasAnyRole(['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin'])) {
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
    if ($resource === 'receiving' && !hasAnyRole(['WarehouseStaff', 'SuperAdmin'])) {
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
    if ($resource === 'procurement-drafts' && !hasAnyRole($rbac['procurement-drafts'] ?? [])) {
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
    if ($resource === 'draft-orders' && !hasAnyRole($rbac['draft-orders'] ?? [])) {
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
    $logDir = dirname(__DIR__, 2) . '/logs';
    if (is_dir($logDir)) {
        @error_log(date('Y-m-d H:i:s') . " [{$requestId}] " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", 3, $logDir . '/php_errors.log');
    }
    $isDev = (($_SERVER['SERVER_NAME'] ?? '') === 'localhost' || ($_ENV['APP_ENV'] ?? '') === 'development');
    $message = $isDev ? $e->getMessage() . ' (ref: ' . $requestId . ')' : 'An error occurred. Please try again or contact support. (ref: ' . $requestId . ')';
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => $message,
        'request_id' => $requestId,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
