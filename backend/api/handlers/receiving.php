<?php

/**
 * Receiving API - queue (pending orders), receipts (history), receipt detail
 * GET /receiving/queue?status=&customer_id=&supplier_id=&order_id=&date_from=&date_to=
 * GET /receiving/receipts?order_id=&customer_id=&date_from=&date_to=
 * GET /receiving/receipts/{id}
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    requireRole(['WarehouseStaff', 'SuperAdmin']);
    if ($method !== 'GET') {
        jsonError('Method not allowed', 405);
    }
    $pdo = getDb();

    if ($id === 'queue') {
        $statuses = $_GET['status'] ?? null;
        if (!$statuses) {
            $statuses = ['Approved', 'InTransitToWarehouse'];
        } else {
            $statuses = is_array($statuses) ? $statuses : explode(',', $statuses);
        }
        $customerId = $_GET['customer_id'] ?? null;
        $supplierId = $_GET['supplier_id'] ?? null;
        $orderId = $_GET['order_id'] ?? null;
        $dateFrom = $_GET['date_from'] ?? null;
        $dateTo = $_GET['date_to'] ?? null;

        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $sql = "SELECT o.id, o.customer_id, o.supplier_id, o.expected_ready_date, o.status, o.created_at,
            c.name as customer_name, s.name as supplier_name
            FROM orders o
            JOIN customers c ON o.customer_id = c.id
            JOIN suppliers s ON o.supplier_id = s.id
            WHERE o.status IN ($placeholders)";
        $params = $statuses;
        if ($customerId) {
            $sql .= " AND o.customer_id = ?";
            $params[] = $customerId;
        }
        if ($supplierId) {
            $sql .= " AND o.supplier_id = ?";
            $params[] = $supplierId;
        }
        if ($orderId) {
            $sql .= " AND o.id = ?";
            $params[] = $orderId;
        }
        if ($dateFrom) {
            $sql .= " AND o.expected_ready_date >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo) {
            $sql .= " AND o.expected_ready_date <= ?";
            $params[] = $dateTo;
        }
        $sql .= " ORDER BY o.expected_ready_date ASC, o.id ASC LIMIT 200";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $items = $pdo->prepare("SELECT id, declared_cbm, declared_weight, description_cn, description_en FROM order_items WHERE order_id = ?");
            $items->execute([$r['id']]);
            $r['items'] = $items->fetchAll(PDO::FETCH_ASSOC);
            $r['declared_cbm'] = array_sum(array_column($r['items'], 'declared_cbm'));
            $r['declared_weight'] = array_sum(array_column($r['items'], 'declared_weight'));
        }
        jsonResponse(['data' => $rows]);
    }

    if ($id === 'receipts') {
        if (is_numeric($action)) {
            $receiptId = (int) $action;
            $stmt = $pdo->prepare("SELECT wr.*, o.id as order_id, o.customer_id, o.supplier_id, o.expected_ready_date, o.status as order_status,
                c.name as customer_name, s.name as supplier_name, u.full_name as received_by_name
                FROM warehouse_receipts wr
                JOIN orders o ON wr.order_id = o.id
                JOIN customers c ON o.customer_id = c.id
                JOIN suppliers s ON o.supplier_id = s.id
                LEFT JOIN users u ON wr.received_by = u.id
                WHERE wr.id = ?");
            $stmt->execute([$receiptId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) jsonError('Receipt not found', 404);
            $rip = $pdo->prepare("SELECT * FROM warehouse_receipt_photos WHERE receipt_id = ?");
            $rip->execute([$receiptId]);
            $row['photos'] = $rip->fetchAll(PDO::FETCH_ASSOC);
            $rii = $pdo->prepare("SELECT wri.*, oi.declared_cbm, oi.declared_weight, oi.description_cn, oi.description_en FROM warehouse_receipt_items wri JOIN order_items oi ON wri.order_item_id = oi.id WHERE wri.receipt_id = ?");
            $rii->execute([$receiptId]);
            $row['items'] = $rii->fetchAll(PDO::FETCH_ASSOC);
            $rip2 = $pdo->prepare("SELECT wrp.*, wri.id as receipt_item_id FROM warehouse_receipt_item_photos wrp JOIN warehouse_receipt_items wri ON wrp.receipt_item_id = wri.id WHERE wri.receipt_id = ?");
            $rip2->execute([$receiptId]);
            $itemPhotos = $rip2->fetchAll(PDO::FETCH_ASSOC);
            foreach ($row['items'] as &$it) {
                $it['photos'] = array_values(array_filter($itemPhotos, fn($p) => (int)$p['receipt_item_id'] === (int)$it['id']));
            }
            jsonResponse(['data' => $row]);
        }

        $orderId = $_GET['order_id'] ?? null;
        $customerId = $_GET['customer_id'] ?? null;
        $dateFrom = $_GET['date_from'] ?? null;
        $dateTo = $_GET['date_to'] ?? null;
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        $sql = "SELECT wr.id, wr.order_id, wr.actual_cartons, wr.actual_cbm, wr.actual_weight, wr.received_at, wr.receipt_condition,
            o.expected_ready_date, o.status as order_status, c.name as customer_name, s.name as supplier_name
            FROM warehouse_receipts wr
            JOIN orders o ON wr.order_id = o.id
            JOIN customers c ON o.customer_id = c.id
            JOIN suppliers s ON o.supplier_id = s.id
            WHERE 1=1";
        $params = [];
        if ($orderId) {
            $sql .= " AND wr.order_id = ?";
            $params[] = $orderId;
        }
        if ($customerId) {
            $sql .= " AND o.customer_id = ?";
            $params[] = $customerId;
        }
        if ($dateFrom) {
            $sql .= " AND wr.received_at >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo) {
            $sql .= " AND wr.received_at <= ?";
            $params[] = $dateTo . ' 23:59:59';
        }
        $sql .= " ORDER BY wr.received_at DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

        $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
        if ($params) $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(['data' => $rows]);
    }

    jsonError('Not found', 404);
};
