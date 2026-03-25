<?php

/**
 * Countries API - GET list, GET search (for autocomplete)
 * Used by consolidation container edit (destination country dropdown)
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    requireRole(['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'ContainersStaff', 'SuperAdmin']);
    $pdo = getDb();

    if ($method !== 'GET') {
        jsonError('Method not allowed', 405);
    }

    if ($id === 'search') {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 1) {
            jsonResponse(['data' => []]);
        }
        $like = '%' . preg_replace('/\s+/', '%', $q) . '%';
        $stmt = $pdo->prepare(
            "SELECT id, code, name FROM countries WHERE code LIKE ? OR name LIKE ? ORDER BY name LIMIT 25"
        );
        $stmt->execute([$like, $like]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(['data' => $rows]);
    }

    if ($id === null) {
        $q = trim($_GET['q'] ?? '');
        $sql = "SELECT id, code, name FROM countries";
        $params = [];
        if (strlen($q) >= 1) {
            $like = '%' . preg_replace('/\s+/', '%', $q) . '%';
            $sql .= " WHERE code LIKE ? OR name LIKE ?";
            $params = [$like, $like];
        }
        $sql .= " ORDER BY name";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        jsonResponse(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    jsonError('Not found', 404);
};
