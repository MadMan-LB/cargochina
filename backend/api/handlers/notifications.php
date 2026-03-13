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
            foreach ($rows as &$r) {
                if ($r['type'] === 'variance_confirmation') {
                    if (preg_match('/Confirmation link:\s*(\S+)/', $r['body'] ?? '', $m)) {
                        $r['confirmation_link'] = trim($m[1]);
                    }
                    if (preg_match('/Order #(\d+)/', $r['title'] ?? '', $m)) {
                        $orderId = (int) $m[1];
                        $r['order_id'] = $orderId;
                        $cust = $pdo->prepare("SELECT c.phone FROM orders o JOIN customers c ON o.customer_id = c.id WHERE o.id = ?");
                        $cust->execute([$orderId]);
                        $phone = $cust->fetchColumn();
                        $r['customer_phone'] = $phone ? preg_replace('/\D/', '', $phone) : null;
                    }
                }
            }
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
