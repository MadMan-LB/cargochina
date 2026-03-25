<?php

/**
 * Public customer warehouse-receipt review handler (no auth required)
 * GET  /confirm?token=  — Return order summary for customer follow-up UI
 * POST /confirm         — Body: {token} — Accept or decline the auto-confirmed receipt
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 2) . '/services/OrderReceiptWorkflowService.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();

    if ($method === 'GET') {
        $token = trim($_GET['token'] ?? '');
        if (!$token) jsonError('Token required', 400);

        $stmt = $pdo->prepare(
            "SELECT o.id, o.status, o.expected_ready_date, o.currency, o.confirmation_token,
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
        if (!$order) jsonError('Invalid or expired review link', 404);
        if (trim((string) ($order['confirmation_token'] ?? '')) === '') {
            jsonError('This order no longer has a pending customer response', 400);
        }
        if (($order['status'] ?? '') === 'FinalizedAndPushedToTracking') {
            jsonError('This order has already been finalized and can no longer be updated from the portal', 400);
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
        if (!$order) jsonError('Invalid or expired review link', 404);
        if (($order['status'] ?? '') === 'FinalizedAndPushedToTracking') {
            jsonError('This order has already been finalized and can no longer be updated from the portal', 400);
        }
        if (!hash_equals((string) $order['confirmation_token'], $token)) {
            jsonError('Invalid customer follow-up token', 403);
        }

        if ($decline) {
            OrderReceiptWorkflowService::declineAutoConfirmedOrder($pdo, (int) $order['id'], $declineReason, null, 'decline_by_token');
            jsonResponse(['data' => ['status' => 'CustomerDeclinedAfterAutoConfirm', 'order_id' => (int) $order['id']]]);
        } else {
            OrderReceiptWorkflowService::acceptAutoConfirmedOrder($pdo, (int) $order['id'], null, 'confirm_by_token');
            jsonResponse(['data' => ['status' => 'ReadyForConsolidation', 'order_id' => (int) $order['id']]]);
        }
    }

    jsonError('Method not allowed', 405);
};
