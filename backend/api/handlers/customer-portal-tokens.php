<?php

/**
 * Customer Portal Tokens API - generate one-time links for customer portal
 * Roles: ChinaAdmin, LebanonAdmin, SuperAdmin
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();
    $userId = getAuthUserId();
    if (!$userId) jsonError('Unauthorized', 401);
    if (!hasAnyRole(['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'])) jsonError('Forbidden', 403);

    switch ($method) {
        case 'POST':
            $customerId = (int) ($input['customer_id'] ?? 0);
            if (!$customerId) jsonError('customer_id required', 400);
            $hours = (int) ($input['hours'] ?? 24);
            if ($hours < 1 || $hours > 168) $hours = 24;
            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256', $token);
            $expires = date('Y-m-d H:i:s', strtotime("+{$hours} hours"));
            $pdo->prepare("INSERT INTO customer_portal_tokens (customer_id, token_hash, expires_at, created_by) VALUES (?,?,?,?)")
                ->execute([$customerId, $hash, $expires, $userId]);
            $portalTokenId = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('customer_portal_token', ?, 'create', ?, ?)")
                ->execute([$portalTokenId, json_encode(['customer_id' => $customerId, 'expires_at' => $expires]), $userId]);
            $base = $_ENV['APP_URL'] ?? null;
            if (!$base) {
                $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $base = $proto . '://' . $host . (strpos($_SERVER['REQUEST_URI'] ?? '', '/cargochina') === 0 ? '/cargochina' : '');
            }
            $link = rtrim($base, '/') . '/customer_portal.php?token=' . $token;
            jsonResponse(['data' => ['token' => $token, 'link' => $link, 'expires_at' => $expires]], 201);

        case 'GET':
            $customerId = $_GET['customer_id'] ?? null;
            if (!$customerId) jsonError('customer_id required', 400);
            $stmt = $pdo->prepare("SELECT id, customer_id, expires_at, used_at, created_at FROM customer_portal_tokens WHERE customer_id = ? ORDER BY created_at DESC LIMIT 20");
            $stmt->execute([$customerId]);
            jsonResponse(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

        default:
            jsonError('Method not allowed', 405);
    }
};
