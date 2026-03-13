<?php

/**
 * Departments API - list (SuperAdmin, ChinaAdmin)
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    requireRole(['SuperAdmin', 'ChinaAdmin']);

    $pdo = getDb();

    if ($method === 'GET' && $id === null) {
        setCacheHeaders(60);
        $stmt = $pdo->query("SELECT id, code, name, description, is_active FROM departments WHERE is_active = 1 ORDER BY name");
        jsonResponse(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    jsonError('Method not allowed', 405);
};