<?php

/**
 * CLMS API Router - REST v1
 * Routes: /api/v1/{resource} -> backend/api/handlers/{resource}.php
 */

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

$method = $_SERVER['REQUEST_METHOD'];
$input = [];
if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
    $raw = file_get_contents('php://input');
    $input = $raw ? (json_decode($raw, true) ?? []) : $_POST;
}

$handler = require $handlerFile;
$handler($method, $id, $action, $input);
