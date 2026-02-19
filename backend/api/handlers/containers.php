<?php

/**
 * Containers API - CRUD
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();

    switch ($method) {
        case 'GET':
            if ($id === null) {
                $stmt = $pdo->query("SELECT * FROM containers ORDER BY id DESC");
                jsonResponse(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            }
            $stmt = $pdo->prepare("SELECT * FROM containers WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) jsonError('Container not found', 404);
            jsonResponse(['data' => $row]);
            break;

        case 'POST':
            $code = trim($input['code'] ?? '');
            $maxCbm = (float) ($input['max_cbm'] ?? 0);
            $maxWeight = (float) ($input['max_weight'] ?? 0);
            if (!$code || $maxCbm <= 0 || $maxWeight <= 0) {
                jsonError('code, max_cbm, max_weight required and positive', 400);
            }
            $pdo->prepare("INSERT INTO containers (code, max_cbm, max_weight) VALUES (?,?,?)")
                ->execute([$code, $maxCbm, $maxWeight]);
            $newId = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare("SELECT * FROM containers WHERE id = ?");
            $stmt->execute([$newId]);
            jsonResponse(['data' => $stmt->fetch(PDO::FETCH_ASSOC)], 201);
            break;
    }

    jsonError('Method not allowed', 405);
};
