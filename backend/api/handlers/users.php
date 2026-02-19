<?php

/**
 * Users API - list (SuperAdmin only)
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    requireRole(['SuperAdmin']);

    $pdo = getDb();

    if ($method === 'GET') {
        $stmt = $pdo->query("SELECT u.id, u.email, u.full_name, u.is_active, u.created_at FROM users u ORDER BY u.id");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $roleStmt = $pdo->prepare("SELECT r.code FROM roles r JOIN user_roles ur ON r.id = ur.role_id WHERE ur.user_id = ?");
            $roleStmt->execute([$r['id']]);
            $r['roles'] = array_column($roleStmt->fetchAll(PDO::FETCH_ASSOC), 'code');
        }
        jsonResponse(['data' => $rows]);
    }

    jsonError('Method not allowed', 405);
};
