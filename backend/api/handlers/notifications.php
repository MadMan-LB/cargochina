<?php

/**
 * Notifications API - list, mark read
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 2) . '/services/NotificationTargetService.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    require_once __DIR__ . '/../authorization.php';
    clmsAuthorizeApiRequest('notifications', $method, $id, $action);
    $pdo = getDb();
    $userId = (int) (getAuthUserId() ?? 0);
    if ($userId <= 0) jsonError('Unauthorized', 401);
    $targetService = new NotificationTargetService($pdo);

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
                $row['target'] = $targetService->resolve($row, $userId, getUserRoles());
                if (($row['type'] ?? '') !== 'variance_confirmation') {
                    continue;
                }
                if (preg_match('/(?:Confirmation|Customer review) link:\s*(\S+)/', $row['body'] ?? '', $match)) {
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
            if ($id && $action === 'open') {
                $stmt=$pdo->prepare('SELECT * FROM notifications WHERE id=? AND user_id=?');$stmt->execute([(int)$id,$userId]);$notification=$stmt->fetch(PDO::FETCH_ASSOC);
                if(!$notification)jsonError('Notification not found',404);
                $target=$targetService->resolve($notification,$userId,getUserRoles());
                if(empty($target['available']))jsonError($target['reason']??'The related record is unavailable.',403,['target'=>'unavailable']);
                $pdo->prepare('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE id=? AND user_id=?')->execute([(int)$id,$userId]);
                jsonResponse(['data'=>['url'=>$target['url'],'read'=>true,'target_type'=>$target['target_type'],'target_id'=>$target['target_id']]]);
            }
            if ($id && $action === 'read') {
                $stmt = $pdo->prepare("UPDATE notifications SET read_at = COALESCE(read_at,NOW()) WHERE id = ? AND user_id = ?");
                $stmt->execute([$id, $userId]);
                jsonResponse(['data' => ['read' => true]]);
            }
            jsonError('Invalid action', 400);
            break;
    }

    jsonError('Method not allowed', 405);
};
