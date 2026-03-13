<?php

/**
 * Containers API - CRUD, status, export
 * Statuses: planning | to_go | on_route | arrived | available
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();

    switch ($method) {

        // -------------------------------------------------------------------------
        case 'GET':
            if ($id && $action === 'orders') {
                $stmt = $pdo->prepare("SELECT id, code, max_cbm, max_weight, status, notes FROM containers WHERE id = ?");
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
                $totalCbm = 0.0;
                $totalWeight = 0.0;
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
                    $t = $tot->fetch(PDO::FETCH_ASSOC);
                    $ord += $t;
                    $totalCbm   += (float) ($t['total_cbm']    ?? 0);
                    $totalWeight += (float) ($t['total_weight'] ?? 0);
                }
                unset($ord);
                $container['used_cbm']    = round($totalCbm,    4);
                $container['used_weight'] = round($totalWeight,  2);
                $container['fill_pct_cbm'] = $container['max_cbm'] > 0
                    ? round($totalCbm / $container['max_cbm'] * 100, 1) : 0;
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
                // Enhanced list: include fill stats + optional search
                $search = trim($_GET['q'] ?? '');
                $statusFilter = trim($_GET['status'] ?? '');
                $sql = "SELECT c.*,
                    COALESCE((
                        SELECT SUM(oi.declared_cbm)
                        FROM shipment_draft_orders sdo
                        JOIN shipment_drafts sd ON sdo.shipment_draft_id = sd.id
                        JOIN order_items oi ON oi.order_id = sdo.order_id
                        WHERE sd.container_id = c.id
                    ), 0) AS used_cbm,
                    COALESCE((
                        SELECT SUM(oi.declared_weight)
                        FROM shipment_draft_orders sdo
                        JOIN shipment_drafts sd ON sdo.shipment_draft_id = sd.id
                        JOIN order_items oi ON oi.order_id = sdo.order_id
                        WHERE sd.container_id = c.id
                    ), 0) AS used_weight,
                    COALESCE((
                        SELECT COUNT(DISTINCT sdo.order_id)
                        FROM shipment_draft_orders sdo
                        JOIN shipment_drafts sd ON sdo.shipment_draft_id = sd.id
                        WHERE sd.container_id = c.id
                    ), 0) AS order_count
                FROM containers c WHERE 1=1";
                $params = [];
                if ($search !== '') {
                    $like = '%' . $search . '%';
                    $innerCond = "cu2.name LIKE ? OR cu2.code LIKE ?";
                    $innerParams = [$like, $like];
                    $chkCust = $pdo->query("SHOW COLUMNS FROM customers LIKE 'phone'");
                    if ($chkCust && $chkCust->rowCount() > 0) {
                        $innerCond .= " OR cu2.phone LIKE ?";
                        $innerParams[] = $like;
                    }
                    $innerCond .= " OR oi2.shipping_code LIKE ? OR oi2.item_no LIKE ? OR oi2.description_cn LIKE ? OR oi2.description_en LIKE ?";
                    $innerParams = array_merge($innerParams, [$like, $like, $like, $like]);
                    if (is_numeric($search)) {
                        $innerCond .= " OR o2.id = ?";
                        $innerParams[] = (int) $search;
                    }
                    $sql .= " AND (c.code LIKE ? OR EXISTS (
                        SELECT 1 FROM shipment_draft_orders sdo2
                        JOIN shipment_drafts sd2 ON sdo2.shipment_draft_id = sd2.id
                        JOIN orders o2 ON sdo2.order_id = o2.id
                        JOIN customers cu2 ON o2.customer_id = cu2.id
                        LEFT JOIN order_items oi2 ON oi2.order_id = o2.id
                        WHERE sd2.container_id = c.id AND (" . $innerCond . ")
                    ))";
                    $params[] = $like;
                    foreach ($innerParams as $p) $params[] = $p;
                }
                if ($statusFilter !== '') {
                    $sql .= " AND c.status = ?";
                    $params[] = $statusFilter;
                }
                $sql .= " ORDER BY c.id DESC";
                $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
                if ($params) $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as &$r) {
                    $r['used_cbm']    = (float) $r['used_cbm'];
                    $r['used_weight'] = (float) $r['used_weight'];
                    $r['order_count'] = (int)   $r['order_count'];
                    $r['fill_pct_cbm'] = $r['max_cbm'] > 0
                        ? round($r['used_cbm'] / $r['max_cbm'] * 100, 1) : 0;
                }
                unset($r);
                jsonResponse(['data' => $rows]);
            }

            $stmt = $pdo->prepare("SELECT * FROM containers WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) jsonError('Container not found', 404);
            jsonResponse(['data' => $row]);
            break;

        // -------------------------------------------------------------------------
        case 'PUT':
            if (!$id) jsonError('Container ID required', 400);
            $stmt = $pdo->prepare("SELECT id FROM containers WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) jsonError('Container not found', 404);
            $allowed_statuses = ['planning', 'to_go', 'on_route', 'arrived', 'available'];
            $sets = [];
            $params = [];
            if (isset($input['status'])) {
                if (!in_array($input['status'], $allowed_statuses)) {
                    jsonError('Invalid status. Allowed: ' . implode(', ', $allowed_statuses), 400);
                }
                $sets[] = 'status = ?';
                $params[] = $input['status'];
            }
            if (array_key_exists('notes', $input)) {
                $sets[] = 'notes = ?';
                $params[] = $input['notes'] ?: null;
            }
            if (empty($sets)) jsonError('Nothing to update', 400);
            $params[] = $id;
            $pdo->prepare("UPDATE containers SET " . implode(', ', $sets) . " WHERE id = ?")
                ->execute($params);
            $stmt = $pdo->prepare("SELECT * FROM containers WHERE id = ?");
            $stmt->execute([$id]);
            jsonResponse(['data' => $stmt->fetch(PDO::FETCH_ASSOC)]);
            break;

        // -------------------------------------------------------------------------
        case 'POST':
            // Shortcut: assign orders directly to a container, handling draft creation automatically
            if ($id && $action === 'assign-orders') {
                requireRole(['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin']);
                $orderIds = array_map('intval', $input['order_ids'] ?? []);
                $force    = !empty($input['force']); // allow even if over capacity
                if (empty($orderIds)) jsonError('order_ids required', 400);

                $stmt = $pdo->prepare("SELECT * FROM containers WHERE id = ?");
                $stmt->execute([$id]);
                $container = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$container) jsonError('Container not found', 404);

                // Validate order eligibility
                $eligible = ['ReadyForConsolidation', 'Confirmed'];
                foreach ($orderIds as $oid) {
                    $st = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
                    $st->execute([$oid]);
                    $s = $st->fetchColumn();
                    if (!in_array($s, $eligible)) {
                        jsonError("Order #$oid is not eligible (status: $s). Must be ReadyForConsolidation or Confirmed.", 400);
                    }
                }

                // Find or create a non-finalized draft for this container
                $draftRow = $pdo->prepare("SELECT id FROM shipment_drafts WHERE container_id = ? AND status != 'finalized' ORDER BY id DESC LIMIT 1");
                $draftRow->execute([$id]);
                $draftId = $draftRow->fetchColumn();
                if (!$draftId) {
                    $pdo->prepare("INSERT INTO shipment_drafts (status, container_id) VALUES ('draft', ?)")->execute([$id]);
                    $draftId = (int) $pdo->lastInsertId();
                }

                // Compute CURRENT usage of this container
                $usageSql = "SELECT COALESCE(SUM(oi.declared_cbm),0) AS used_cbm,
                                    COALESCE(SUM(oi.declared_weight),0) AS used_weight
                             FROM shipment_draft_orders sdo
                             JOIN shipment_drafts sd ON sdo.shipment_draft_id = sd.id
                             JOIN order_items oi ON oi.order_id = sdo.order_id
                             WHERE sd.container_id = ?";
                $usageStmt = $pdo->prepare($usageSql);
                $usageStmt->execute([$id]);
                $usage = $usageStmt->fetch(PDO::FETCH_ASSOC);
                $currentCbm    = (float) ($usage['used_cbm']    ?? 0);
                $currentWeight = (float) ($usage['used_weight'] ?? 0);

                // Compute what the NEW orders would add
                $ph = implode(',', array_fill(0, count($orderIds), '?'));
                $newTot = $pdo->prepare("SELECT COALESCE(SUM(declared_cbm),0), COALESCE(SUM(declared_weight),0) FROM order_items WHERE order_id IN ($ph)");
                $newTot->execute($orderIds);
                [$addCbm, $addWeight] = $newTot->fetch(PDO::FETCH_NUM);
                $addCbm    = (float) $addCbm;
                $addWeight = (float) $addWeight;

                $totalCbm    = $currentCbm    + $addCbm;
                $totalWeight = $currentWeight + $addWeight;
                $maxCbm    = (float) $container['max_cbm'];
                $maxWeight = (float) $container['max_weight'];

                $overCbm    = $totalCbm    > $maxCbm;
                $overWeight = $totalWeight > $maxWeight;

                if (($overCbm || $overWeight) && !$force) {
                    $msgs = [];
                    if ($overCbm)    $msgs[] = "CBM: {$totalCbm} / {$maxCbm}";
                    if ($overWeight) $msgs[] = "Weight: {$totalWeight} / {$maxWeight} kg";
                    jsonResponse([
                        'over_capacity' => true,
                        'message' => 'Adding these orders would exceed container capacity (' . implode(', ', $msgs) . '). Send with force=true to proceed anyway.',
                        'details' => compact('totalCbm', 'totalWeight', 'maxCbm', 'maxWeight'),
                    ], 409);
                }

                // Insert into draft
                $ins = $pdo->prepare("INSERT IGNORE INTO shipment_draft_orders (shipment_draft_id, order_id) VALUES (?,?)");
                foreach ($orderIds as $oid) {
                    $ins->execute([$draftId, $oid]);
                }
                if (!empty($orderIds)) {
                    $pdo->prepare("UPDATE orders SET status='ConsolidatedIntoShipmentDraft' WHERE id IN ($ph)")->execute($orderIds);
                }
                // Ensure draft is linked to this container
                $pdo->prepare("UPDATE shipment_drafts SET container_id = ? WHERE id = ?")->execute([$id, $draftId]);
                // Update order status to AssignedToContainer
                if (!empty($orderIds)) {
                    $pdo->prepare("UPDATE orders SET status='AssignedToContainer' WHERE id IN ($ph)")->execute($orderIds);
                }

                $usageStmt->execute([$id]);
                $newUsage = $usageStmt->fetch(PDO::FETCH_ASSOC);
                jsonResponse([
                    'data' => [
                        'draft_id'      => $draftId,
                        'orders_added'  => count($orderIds),
                        'over_capacity' => $overCbm || $overWeight,
                        'used_cbm'      => (float) ($newUsage['used_cbm']    ?? 0),
                        'used_weight'   => (float) ($newUsage['used_weight'] ?? 0),
                        'max_cbm'       => $maxCbm,
                        'max_weight'    => $maxWeight,
                    ],
                ]);
            }

            $code = trim($input['code'] ?? '');
            $maxCbm = (float) ($input['max_cbm'] ?? 0);
            $maxWeight = (float) ($input['max_weight'] ?? 0);
            if (!$code || $maxCbm <= 0 || $maxWeight <= 0) {
                jsonError('code, max_cbm, max_weight required and positive', 400);
            }
            $status = in_array($input['status'] ?? '', ['planning', 'to_go', 'on_route', 'arrived', 'available'])
                ? $input['status'] : 'planning';
            $pdo->prepare("INSERT INTO containers (code, max_cbm, max_weight, status) VALUES (?,?,?,?)")
                ->execute([$code, $maxCbm, $maxWeight, $status]);
            $newId = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare("SELECT * FROM containers WHERE id = ?");
            $stmt->execute([$newId]);
            jsonResponse(['data' => $stmt->fetch(PDO::FETCH_ASSOC)], 201);
            break;
    }

    jsonError('Method not allowed', 405);
};
