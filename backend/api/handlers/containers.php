<?php

/**
 * Containers API - CRUD, export
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();

    switch ($method) {
        case 'GET':
            if ($id && $action === 'orders') {
                $stmt = $pdo->prepare("SELECT id, code, max_cbm, max_weight FROM containers WHERE id = ?");
                $stmt->execute([$id]);
                $container = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$container) jsonError('Container not found', 404);
                $stmt = $pdo->prepare(
                    "SELECT o.id, o.status, o.expected_ready_date, o.currency,
                            c.name as customer_name,
                            s.name as supplier_name,
                            sd.id as draft_id
                     FROM shipment_draft_orders sdo
                     JOIN shipment_drafts sd ON sdo.shipment_draft_id = sd.id
                     JOIN orders o ON sdo.order_id = o.id
                     JOIN customers c ON o.customer_id = c.id
                     JOIN suppliers s ON o.supplier_id = s.id
                     WHERE sd.container_id = ?
                     ORDER BY sd.id, o.id"
                );
                $stmt->execute([$id]);
                $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($orders as &$ord) {
                    $tot = $pdo->prepare(
                        "SELECT COUNT(*) as items,
                                COALESCE(SUM(cartons),0) as total_ctns,
                                COALESCE(SUM(quantity),0) as total_qty,
                                COALESCE(SUM(declared_cbm),0) as total_cbm,
                                COALESCE(SUM(declared_weight),0) as total_weight,
                                COALESCE(SUM(total_amount),0) as total_amount
                         FROM order_items WHERE order_id = ?"
                    );
                    $tot->execute([$ord['id']]);
                    $ord += $tot->fetch(PDO::FETCH_ASSOC);
                }
                jsonResponse(['data' => ['container' => $container, 'orders' => $orders]]);
            }
            if ($id && $action === 'export') {
                $stmt = $pdo->prepare("SELECT id, code FROM containers WHERE id = ?");
                $stmt->execute([$id]);
                $container = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$container) jsonError('Container not found', 404);
                $stmt = $pdo->prepare("SELECT order_id FROM shipment_draft_orders sdo JOIN shipment_drafts sd ON sdo.shipment_draft_id = sd.id WHERE sd.container_id = ? ORDER BY sdo.shipment_draft_id, sdo.order_id");
                $stmt->execute([$id]);
                $orderIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'order_id');
                if (empty($orderIds)) jsonError('No orders in this container', 404);
                $suppCols = 's.name as supplier_name, s.phone as supplier_phone, s.factory_location as supplier_factory';
                $chk = @$pdo->query("SHOW COLUMNS FROM suppliers LIKE 'address'");
                if ($chk && $chk->rowCount() > 0) $suppCols .= ', s.address as supplier_address';
                $chk = @$pdo->query("SHOW COLUMNS FROM suppliers LIKE 'fax'");
                if ($chk && $chk->rowCount() > 0) $suppCols .= ', s.fax as supplier_fax';
                $custCols = 'c.name as customer_name';
                $chk = @$pdo->query("SHOW COLUMNS FROM customers LIKE 'phone'");
                if ($chk && $chk->rowCount() > 0) $custCols .= ', c.phone as customer_phone';
                require_once dirname(__DIR__, 2) . '/services/OrderExcelService.php';
                require_once __DIR__ . '/orders.php';
                $ordersWithItems = [];
                foreach ($orderIds as $oid) {
                    $stmt = $pdo->prepare("SELECT o.*, $custCols, $suppCols FROM orders o JOIN customers c ON o.customer_id = c.id JOIN suppliers s ON o.supplier_id = s.id WHERE o.id = ?");
                    $stmt->execute([$oid]);
                    $order = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$order) continue;
                    $items = normalizeOrderItems(fetchOrderItems($pdo, (int) $oid));
                    $ordersWithItems[] = ['order' => $order, 'items' => $items];
                }
                $code = preg_replace('/[^a-zA-Z0-9_-]/', '_', $container['code'] ?? 'container');
                (new OrderExcelService())->exportOrders($ordersWithItems, 'container_' . $code . '_orders.xlsx');
            }
            if ($id === null) {
                $stmt = $pdo->query("SELECT * FROM containers ORDER BY id DESC");
                jsonResponse(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            }
            $stmt = $pdo->prepare("SELECT * FROM containers WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) jsonError('Container not found', 404);
            jsonResponse(['data' => $row]);
            break;

        case 'POST':
            $code = trim($input['code'] ?? '');
            $maxCbm = (float) ($input['max_cbm'] ?? 0);
            $maxWeight = (float) ($input['max_weight'] ?? 0);
            if (!$code || $maxCbm <= 0 || $maxWeight <= 0) {
                jsonError('code, max_cbm, max_weight required and positive', 400);
            }
            $pdo->prepare("INSERT INTO containers (code, max_cbm, max_weight) VALUES (?,?,?)")
                ->execute([$code, $maxCbm, $maxWeight]);
            $newId = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare("SELECT * FROM containers WHERE id = ?");
            $stmt->execute([$newId]);
            jsonResponse(['data' => $stmt->fetch(PDO::FETCH_ASSOC)], 201);
            break;
    }

    jsonError('Method not allowed', 405);
};
