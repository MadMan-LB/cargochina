<?php

/**
 * Roles API - list (SuperAdmin only)
 * Returns id, code, name for role assignment in admin users.
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    requireRole(['SuperAdmin']);

    $pdo = getDb();

    if ($method === 'GET' && $id === null) {
        setCacheHeaders(60);
        $stmt = $pdo->query("SELECT id, code, name FROM roles ORDER BY name");
        jsonResponse(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    jsonError('Method not allowed', 405);
};
