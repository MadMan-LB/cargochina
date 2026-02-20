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
$action = $parts[2] ?? null;

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
    if ($resource === 'containers' && !hasAnyRole($rbac['containers'] ?? [])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
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
    if ($resource === 'users' && !hasAnyRole($rbac['users'] ?? [])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'config' && $id !== 'receiving' && !hasAnyRole($rbac['config'] ?? [])) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
    if ($resource === 'config' && $id === 'receiving' && !hasAnyRole(['WarehouseStaff', 'SuperAdmin'])) {
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
}

$handler = require $handlerFile;
$handler($method, $id, $action, $input);
