<?php

/**
 * Receiving API - queue (pending orders), search, receipts (history), receipt detail
 * GET /receiving/search?q= — Search orders by id, customer/supplier name, phone, shipping code (Approved/InTransit)
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

    if ($id === 'search') {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 1) {
            jsonResponse(['data' => []]);
        }
        $statuses = ['Approved', 'InTransitToWarehouse'];
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $like = '%' . preg_replace('/\s+/', '%', $q) . '%';
        $custCols = 'c.name as customer_name, c.phone as customer_phone, c.code as customer_code';
        $chkPrio = @$pdo->query("SHOW COLUMNS FROM customers LIKE 'priority_level'");
        if ($chkPrio && $chkPrio->rowCount() > 0) $custCols .= ', c.priority_level as customer_priority_level, c.priority_note as customer_priority_note';
        $sql = "SELECT o.id, o.customer_id, o.supplier_id, o.expected_ready_date, o.status, o.high_alert_notes,
            $custCols,
            s.name as supplier_name, s.phone as supplier_phone
            FROM orders o
            JOIN customers c ON o.customer_id = c.id
            LEFT JOIN suppliers s ON o.supplier_id = s.id
            WHERE o.status IN ($placeholders)
            AND (
                o.id = ?
                OR c.name LIKE ?
                OR c.code LIKE ?
                OR (c.phone IS NOT NULL AND c.phone LIKE ?)
                OR s.name LIKE ?
                OR s.code LIKE ?
                OR (s.phone IS NOT NULL AND s.phone LIKE ?)
                OR EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND (oi.shipping_code LIKE ? OR oi.item_no LIKE ? OR oi.description_cn LIKE ? OR oi.description_en LIKE ?))
            )
            ORDER BY o.expected_ready_date ASC, o.id ASC
            LIMIT 30";
        $oid = ctype_digit($q) ? (int) $q : 0;
        $params = array_merge($statuses, [$oid, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like]);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $chkProductAlert = @$pdo->query("SHOW COLUMNS FROM products LIKE 'high_alert_note'");
        $itemAlertCol = ($chkProductAlert && $chkProductAlert->rowCount() > 0) ? ", p.high_alert_note as product_high_alert_note" : "";
        foreach ($rows as &$r) {
            $items = $pdo->prepare("SELECT oi.id, oi.shipping_code, oi.cartons, oi.declared_cbm, oi.declared_weight$itemAlertCol FROM order_items oi LEFT JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?");
            $items->execute([$r['id']]);
            $r['items'] = $items->fetchAll(PDO::FETCH_ASSOC);
            $r['declared_cbm'] = array_sum(array_column($r['items'], 'declared_cbm'));
            $r['declared_weight'] = array_sum(array_column($r['items'], 'declared_weight'));
        }
        jsonResponse(['data' => $rows]);
    }

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
        $shippingCode = trim($_GET['shipping_code'] ?? '');

        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $custCols = 'c.name as customer_name';
        $chkPrio = @$pdo->query("SHOW COLUMNS FROM customers LIKE 'priority_level'");
        if ($chkPrio && $chkPrio->rowCount() > 0) $custCols .= ', c.priority_level as customer_priority_level, c.priority_note as customer_priority_note';
        $sql = "SELECT o.id, o.customer_id, o.supplier_id, o.expected_ready_date, o.status, o.created_at, o.high_alert_notes,
            $custCols, s.name as supplier_name, s.phone as supplier_phone
            FROM orders o
            JOIN customers c ON o.customer_id = c.id
            LEFT JOIN suppliers s ON o.supplier_id = s.id
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
        if ($shippingCode !== '') {
            $sql .= " AND EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND oi.shipping_code LIKE ?)";
            $params[] = '%' . $shippingCode . '%';
        }
        $sql .= " ORDER BY o.expected_ready_date ASC, o.id ASC LIMIT 200";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $chkProductAlert = @$pdo->query("SHOW COLUMNS FROM products LIKE 'high_alert_note'");
        $itemAlertCol = ($chkProductAlert && $chkProductAlert->rowCount() > 0) ? ", p.high_alert_note as product_high_alert_note" : "";
        foreach ($rows as &$r) {
            $items = $pdo->prepare("SELECT oi.id, oi.shipping_code, oi.cartons, oi.qty_per_carton, oi.declared_cbm, oi.declared_weight, oi.item_length, oi.item_width, oi.item_height, oi.description_cn, oi.description_en, p.hs_code$itemAlertCol FROM order_items oi LEFT JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?");
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
            $custCols = 'c.name as customer_name';
            $chkPrio = @$pdo->query("SHOW COLUMNS FROM customers LIKE 'priority_level'");
            if ($chkPrio && $chkPrio->rowCount() > 0) $custCols .= ', c.priority_level as customer_priority_level, c.priority_note as customer_priority_note';
            $stmt = $pdo->prepare("SELECT wr.*, o.id as order_id, o.customer_id, o.supplier_id, o.expected_ready_date, o.status as order_status, o.high_alert_notes,
                $custCols, s.name as supplier_name, u.full_name as received_by_name
                FROM warehouse_receipts wr
                JOIN orders o ON wr.order_id = o.id
                JOIN customers c ON o.customer_id = c.id
                LEFT JOIN suppliers s ON o.supplier_id = s.id
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

        $custCols = 'c.name as customer_name';
        $chkPrio = @$pdo->query("SHOW COLUMNS FROM customers LIKE 'priority_level'");
        if ($chkPrio && $chkPrio->rowCount() > 0) $custCols .= ', c.priority_level as customer_priority_level, c.priority_note as customer_priority_note';
        $sql = "SELECT wr.id, wr.order_id, wr.actual_cartons, wr.actual_cbm, wr.actual_weight, wr.received_at, wr.receipt_condition,
            o.expected_ready_date, o.status as order_status, $custCols, s.name as supplier_name
            FROM warehouse_receipts wr
            JOIN orders o ON wr.order_id = o.id
            JOIN customers c ON o.customer_id = c.id
            LEFT JOIN suppliers s ON o.supplier_id = s.id
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
