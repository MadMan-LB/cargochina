<?php

/**
 * Customer Portal Tokens API - generate one-time links for customer portal
 * Roles: ChinaAdmin, LebanonAdmin, SuperAdmin
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__,2).'/services/OperationReplayService.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    require_once __DIR__ . '/../authorization.php';
    clmsAuthorizeApiRequest('customer-portal-tokens', $method, $id, $action);
    $pdo = getDb();
    $userId = getAuthUserId();
    if (!$userId) jsonError('Unauthorized', 401);
    requirePermission('customer-portal-tokens');

    switch ($method) {
        case 'POST':
            $customerId = (int) OrderWriteService::number($input['customer_id']??null,'Customer',true,0,4294967295);
            if (!$customerId) jsonError('customer_id required', 400);
            clmsRequireCustomerAccess($pdo, $customerId);
            $hours = (int)OrderWriteService::number($input['hours']??24,'Hours',true,0,168);
            if($hours<1)jsonError('Hours must be between 1 and 168',422);
            $claim=OperationReplayService::claim($pdo,'customer_portal_token',$input,$userId);
            if($claim['previous_id'])jsonError('This link was already issued. Use the displayed link or generate a new one.',409);
            $pdo->beginTransaction();register_shutdown_function(static function()use($pdo){if($pdo->inTransaction())$pdo->rollBack();});
            $s=$pdo->prepare('SELECT id FROM customers WHERE id=? FOR UPDATE');$s->execute([$customerId]);if(!$s->fetchColumn())jsonError('Customer not found',404);
            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256', $token);
            $expires = date('Y-m-d H:i:s', strtotime("+{$hours} hours"));
            $pdo->prepare("INSERT INTO customer_portal_tokens (customer_id, token_hash, expires_at, created_by) VALUES (?,?,?,?)")
                ->execute([$customerId, $hash, $expires, $userId]);
            $portalTokenId = (int) $pdo->lastInsertId();
            OperationReplayService::record($pdo,'customer_portal_token',$portalTokenId,$claim,['customer_id'=>$customerId,'expires_at'=>$expires],$userId);$pdo->commit();
            $config = require dirname(__DIR__, 2) . '/config/config.php';
            $base = trim((string) ($config['app_url'] ?? ''));
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
            clmsRequireCustomerAccess($pdo, (int) $customerId);
            $stmt = $pdo->prepare("SELECT id, customer_id, expires_at, used_at, created_at FROM customer_portal_tokens WHERE customer_id = ? ORDER BY created_at DESC LIMIT 20");
            $stmt->execute([$customerId]);
            jsonResponse(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

        default:
            jsonError('Method not allowed', 405);
    }
};
