<?php

/**
 * Notifications API - list, mark read
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();
    $userId = getAuthUserId() ?? 1;

    switch ($method) {
        case 'GET':
            $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(['data' => $rows]);
            break;

        case 'POST':
            if ($id && $action === 'read') {
                $stmt = $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ?");
                $stmt->execute([$id, $userId]);
                jsonResponse(['data' => ['read' => true]]);
            }
            jsonError('Invalid action', 400);
            break;
    }

    jsonError('Method not allowed', 405);
};
