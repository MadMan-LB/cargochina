<?php

/**
 * Public customer confirmation handler (no auth required)
 * GET  /confirm?token=  — Return order summary for confirmation UI
 * POST /confirm         — Body: {token} — Confirm the order
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();

    if ($method === 'GET') {
        $token = trim($_GET['token'] ?? '');
        if (!$token) jsonError('Token required', 400);

        $stmt = $pdo->prepare(
            "SELECT o.id, o.status, o.expected_ready_date, o.currency,
             c.name as customer_name, s.name as supplier_name,
             wr.actual_cbm, wr.actual_weight, wr.actual_cartons, wr.receipt_condition
             FROM orders o
             JOIN customers c ON o.customer_id = c.id
             LEFT JOIN suppliers s ON o.supplier_id = s.id
             LEFT JOIN warehouse_receipts wr ON wr.order_id = o.id
             WHERE o.confirmation_token = ?
             ORDER BY wr.received_at DESC
             LIMIT 1"
        );
        $stmt->execute([$token]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) jsonError('Invalid or expired confirmation link', 404);
        if ($order['status'] !== 'AwaitingCustomerConfirmation') {
            jsonError('This order has already been confirmed or is no longer awaiting confirmation', 400);
        }

        $items = $pdo->prepare(
            "SELECT description_cn, description_en, cartons, quantity, unit,
             declared_cbm, declared_weight, item_no, shipping_code
             FROM order_items WHERE order_id = ? ORDER BY id"
        );
        $items->execute([$order['id']]);
        $order['items'] = $items->fetchAll(PDO::FETCH_ASSOC);

        $photos = $pdo->prepare(
            "SELECT wrp.file_path FROM warehouse_receipt_photos wrp
             JOIN warehouse_receipts wr ON wrp.receipt_id = wr.id
             WHERE wr.order_id = ? ORDER BY wrp.id LIMIT 10"
        );
        $photos->execute([$order['id']]);
        $order['receipt_photos'] = array_column($photos->fetchAll(PDO::FETCH_ASSOC), 'file_path');

        jsonResponse(['data' => $order]);
    }

    if ($method === 'POST') {
        $token = trim($input['token'] ?? '');
        if (!$token) jsonError('Token required', 400);

        $decline = !empty($input['decline']);
        $declineReason = trim($input['decline_reason'] ?? '');

        if ($decline && strlen($declineReason) < 5) {
            jsonError('Decline reason is required (minimum 5 characters)', 400);
        }

        $stmt = $pdo->prepare("SELECT id, status, confirmation_token, customer_id FROM orders WHERE confirmation_token = ?");
        $stmt->execute([$token]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) jsonError('Invalid or expired confirmation link', 404);
        if ($order['status'] !== 'AwaitingCustomerConfirmation') {
            jsonError('This order has already been confirmed or is no longer awaiting confirmation', 400);
        }
        if (!hash_equals((string) $order['confirmation_token'], $token)) {
            jsonError('Invalid confirmation token', 403);
        }

        if ($decline) {
            $pdo->prepare("UPDATE orders SET status='CustomerDeclined', confirmation_token=NULL WHERE id=?")->execute([$order['id']]);
            $chk = $pdo->query("SHOW COLUMNS FROM customer_confirmations LIKE 'declined_at'");
            if ($chk && $chk->rowCount() > 0) {
                $pdo->prepare("INSERT INTO customer_confirmations (order_id, confirmed_by, confirmed_at, accepted_actuals, declined_at, decline_reason) VALUES (?,NULL,NULL,NULL,NOW(),?)")
                    ->execute([$order['id'], $declineReason]);
            } else {
                $pdo->prepare("INSERT INTO customer_confirmations (order_id, confirmed_by, accepted_actuals) VALUES (?,NULL,?)")
                    ->execute([$order['id'], json_encode(['declined' => true, 'reason' => $declineReason])]);
            }
            $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('order',?,'decline_by_token',?,NULL)")
                ->execute([$order['id'], json_encode(['token_used' => true, 'reason' => $declineReason])]);

            require_once dirname(__DIR__, 2) . '/services/NotificationService.php';
            NotificationService::notifyOrderDeclined((int) $order['id'], $declineReason);

            jsonResponse(['data' => ['status' => 'CustomerDeclined', 'order_id' => (int) $order['id']]]);
        } else {
            $wr = $pdo->prepare("SELECT * FROM warehouse_receipts WHERE order_id = ? ORDER BY received_at DESC LIMIT 1");
            $wr->execute([$order['id']]);
            $receipt = $wr->fetch(PDO::FETCH_ASSOC);
            $accepted = $receipt ? [
                'actual_cbm' => $receipt['actual_cbm'],
                'actual_weight' => $receipt['actual_weight'],
                'actual_cartons' => $receipt['actual_cartons']
            ] : [];

            $pdo->prepare("UPDATE orders SET status='Confirmed', confirmation_token=NULL WHERE id=?")->execute([$order['id']]);
            $pdo->prepare("INSERT INTO customer_confirmations (order_id, confirmed_by, accepted_actuals) VALUES (?,NULL,?)")
                ->execute([$order['id'], json_encode($accepted)]);
            $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('order',?,'confirm_by_token',?,NULL)")
                ->execute([$order['id'], json_encode(['token_used' => true])]);

            jsonResponse(['data' => ['status' => 'Confirmed', 'order_id' => (int) $order['id']]]);
        }
    }

    jsonError('Method not allowed', 405);
};
