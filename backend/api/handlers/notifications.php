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
            if ($id === 'unread-count') {
                setCacheHeaders(15);
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL");
                $stmt->execute([$userId]);
                jsonResponse(['data' => ['unread_count' => (int) $stmt->fetchColumn()]]);
            }

            setCacheHeaders(15);
            $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $varianceOrderIds = [];
            foreach ($rows as &$row) {
                if (($row['type'] ?? '') !== 'variance_confirmation') {
                    continue;
                }
                if (preg_match('/Confirmation link:\s*(\S+)/', $row['body'] ?? '', $match)) {
                    $row['confirmation_link'] = trim($match[1]);
                }
                if (preg_match('/Order #(\d+)/', $row['title'] ?? '', $match)) {
                    $row['order_id'] = (int) $match[1];
                    $varianceOrderIds[] = (int) $match[1];
                }
            }
            unset($row);

            $orderPhones = [];
            $varianceOrderIds = array_values(array_unique(array_filter($varianceOrderIds)));
            if ($varianceOrderIds) {
                $placeholders = implode(',', array_fill(0, count($varianceOrderIds), '?'));
                $phoneStmt = $pdo->prepare("SELECT o.id, c.phone FROM orders o JOIN customers c ON o.customer_id = c.id WHERE o.id IN ($placeholders)");
                $phoneStmt->execute($varianceOrderIds);
                foreach ($phoneStmt->fetchAll(PDO::FETCH_ASSOC) as $phoneRow) {
                    $orderPhones[(int) $phoneRow['id']] = $phoneRow['phone'] ? preg_replace('/\D/', '', (string) $phoneRow['phone']) : null;
                }
            }

            foreach ($rows as &$row) {
                if (!empty($row['order_id'])) {
                    $row['customer_phone'] = $orderPhones[(int) $row['order_id']] ?? null;
                }
            }
            unset($row);

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
