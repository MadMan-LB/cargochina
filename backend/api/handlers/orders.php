<?php

/**
 * Orders API - CRUD, submit, approve, attachments
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 2) . '/backend/services/OrderStateService.php';
require_once dirname(__DIR__, 2) . '/backend/services/NotificationService.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();
    $userId = getAuthUserId() ?? 1; // Dev fallback

    switch ($method) {
        case 'GET':
            if ($id === null) {
                $status = $_GET['status'] ?? null;
                $customerId = $_GET['customer_id'] ?? null;
                $sql = "SELECT o.*, c.name as customer_name, s.name as supplier_name FROM orders o
                    JOIN customers c ON o.customer_id = c.id JOIN suppliers s ON o.supplier_id = s.id WHERE 1=1";
                $params = [];
                if ($status) {
                    $sql .= " AND o.status = ?";
                    $params[] = $status;
                }
                if ($customerId) {
                    $sql .= " AND o.customer_id = ?";
                    $params[] = $customerId;
                }
                $sql .= " ORDER BY o.created_at DESC";
                $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
                if ($params) $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as &$r) {
                    $r['items'] = [];
                    $si = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
                    $si->execute([$r['id']]);
                    $r['items'] = $si->fetchAll(PDO::FETCH_ASSOC);
                }
                jsonResponse(['data' => $rows]);
            }
            $stmt = $pdo->prepare("SELECT o.*, c.name as customer_name, s.name as supplier_name FROM orders o JOIN customers c ON o.customer_id = c.id JOIN suppliers s ON o.supplier_id = s.id WHERE o.id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) jsonError('Order not found', 404);
            $si = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
            $si->execute([$id]);
            $row['items'] = $si->fetchAll(PDO::FETCH_ASSOC);
            $att = $pdo->prepare("SELECT * FROM order_attachments WHERE order_id = ?");
            $att->execute([$id]);
            $row['attachments'] = $att->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(['data' => $row]);
            break;

        case 'PUT':
            if (!$id) jsonError('ID required', 400);
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
            $stmt->execute([$id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) jsonError('Order not found', 404);
            if ($order['status'] !== 'Draft') {
                jsonError('Only Draft orders can be edited', 400);
            }
            $customerId = (int) ($input['customer_id'] ?? $order['customer_id']);
            $supplierId = (int) ($input['supplier_id'] ?? $order['supplier_id']);
            $expectedReady = $input['expected_ready_date'] ?? $order['expected_ready_date'];
            $expectedDate = date('Y-m-d', strtotime($expectedReady));
            $items = $input['items'] ?? [];
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE orders SET customer_id=?, supplier_id=?, expected_ready_date=? WHERE id=?")
                    ->execute([$customerId, $supplierId, $expectedDate, $id]);
                $pdo->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$id]);
                $insItem = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, unit, declared_cbm, declared_weight, description_cn, description_en) VALUES (?,?,?,?,?,?,?,?)");
                foreach ($items as $it) {
                    $insItem->execute([
                        $id,
                        !empty($it['product_id']) ? (int) $it['product_id'] : null,
                        (float) $it['quantity'],
                        $it['unit'],
                        (float) ($it['declared_cbm'] ?? 0),
                        (float) ($it['declared_weight'] ?? 0),
                        $it['description_cn'] ?? null,
                        $it['description_en'] ?? null
                    ]);
                }
                $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('order',?,?,?,?)")
                    ->execute([$id, 'update', json_encode($input), $userId]);
                $pdo->commit();
                $stmt = $pdo->prepare("SELECT o.*, c.name as customer_name, s.name as supplier_name FROM orders o JOIN customers c ON o.customer_id = c.id JOIN suppliers s ON o.supplier_id = s.id WHERE o.id = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $si = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
                $si->execute([$id]);
                $row['items'] = $si->fetchAll(PDO::FETCH_ASSOC);
                jsonResponse(['data' => $row]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        case 'POST':
            if ($id === null) {
                $customerId = (int) ($input['customer_id'] ?? 0);
                $supplierId = (int) ($input['supplier_id'] ?? 0);
                $expectedReady = $input['expected_ready_date'] ?? '';
                $items = $input['items'] ?? [];
                if (!$customerId || !$supplierId || !$expectedReady) {
                    jsonError('Missing required: customer_id, supplier_id, expected_ready_date', 400);
                }
                $expectedDate = date('Y-m-d', strtotime($expectedReady));
                if ($expectedDate === '1970-01-01' || !$expectedDate) {
                    jsonError('Invalid expected_ready_date', 400);
                }
                foreach ($items as $it) {
                    $qty = (float) ($it['quantity'] ?? 0);
                    $unit = $it['unit'] ?? '';
                    $cbm = (float) ($it['declared_cbm'] ?? 0);
                    $weight = (float) ($it['declared_weight'] ?? 0);
                    if ($qty <= 0 || !in_array($unit, ['cartons', 'pieces']) || $cbm < 0 || $weight < 0) {
                        jsonError('Invalid item: quantity>0, unit in [cartons,pieces], cbm/weight>=0', 400);
                    }
                }
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("INSERT INTO orders (customer_id, supplier_id, expected_ready_date, status, created_by) VALUES (?,?,?,'Draft',?)")
                        ->execute([$customerId, $supplierId, $expectedDate, $userId]);
                    $orderId = (int) $pdo->lastInsertId();
                    $insItem = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, unit, declared_cbm, declared_weight, description_cn, description_en) VALUES (?,?,?,?,?,?,?,?)");
                    foreach ($items as $it) {
                        $insItem->execute([
                            $orderId,
                            !empty($it['product_id']) ? (int) $it['product_id'] : null,
                            (float) $it['quantity'],
                            $it['unit'],
                            (float) ($it['declared_cbm'] ?? 0),
                            (float) ($it['declared_weight'] ?? 0),
                            $it['description_cn'] ?? null,
                            $it['description_en'] ?? null
                        ]);
                    }
                    $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('order',?,?,?,?)")
                        ->execute([$orderId, 'create', json_encode(['status' => 'Draft']), $userId]);
                    (new NotificationService($pdo))->notifyOrderCreated($orderId, $userId);
                    $pdo->commit();
                    $stmt = $pdo->prepare("SELECT o.*, c.name as customer_name, s.name as supplier_name FROM orders o JOIN customers c ON o.customer_id = c.id JOIN suppliers s ON o.supplier_id = s.id WHERE o.id = ?");
                    $stmt->execute([$orderId]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $si = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
                    $si->execute([$orderId]);
                    $row['items'] = $si->fetchAll(PDO::FETCH_ASSOC);
                    jsonResponse(['data' => $row], 201);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
            }
            if ($id && $action === 'receive') {
                $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
                $stmt->execute([$id]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$order) jsonError('Order not found', 404);
                $allowed = ['Approved', 'InTransitToWarehouse'];
                if (!in_array($order['status'], $allowed, true)) {
                    jsonError('Order must be Approved or InTransitToWarehouse to receive', 400);
                }
                $actualCartons = (int) ($input['actual_cartons'] ?? 0);
                $actualCbm = (float) ($input['actual_cbm'] ?? 0);
                $actualWeight = (float) ($input['actual_weight'] ?? 0);
                $condition = $input['condition'] ?? 'good';
                if (!in_array($condition, ['good', 'damaged', 'partial'])) $condition = 'good';
                $photoPaths = $input['photo_paths'] ?? [];
                $sc = $pdo->prepare("SELECT COALESCE(SUM(declared_cbm),0), COALESCE(SUM(declared_weight),0) FROM order_items WHERE order_id = ?");
                $sc->execute([$id]);
                $row = $sc->fetch(PDO::FETCH_NUM);
                $declaredCbm = (float) ($row[0] ?? 0);
                $declaredWeight = (float) ($row[1] ?? 0);
                $config = require dirname(__DIR__, 2) . '/backend/config/config.php';
                $thresholdPct = $config['variance_threshold_percent'] ?? 10;
                $thresholdAbs = $config['variance_threshold_abs_cbm'] ?? 0.1;
                $variancePct = $declaredCbm > 0 ? abs($actualCbm - $declaredCbm) / $declaredCbm * 100 : 0;
                $varianceAbs = abs($actualCbm - $declaredCbm);
                $hasVariance = $variancePct >= $thresholdPct || $varianceAbs >= $thresholdAbs || $condition !== 'good';
                if ($hasVariance && empty($photoPaths)) {
                    jsonError('Evidence photos required when variance or damage is present', 400);
                }
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("INSERT INTO warehouse_receipts (order_id, actual_cartons, actual_cbm, actual_weight, receipt_condition, notes, received_by) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$id, $actualCartons, $actualCbm, $actualWeight, $condition, $input['notes'] ?? null, $userId]);
                    $receiptId = (int) $pdo->lastInsertId();
                    $insPhoto = $pdo->prepare("INSERT INTO warehouse_receipt_photos (receipt_id, file_path) VALUES (?,?)");
                    foreach ($photoPaths as $path) {
                        $insPhoto->execute([$receiptId, $path]);
                    }
                    $newStatus = $hasVariance ? 'AwaitingCustomerConfirmation' : 'ReadyForConsolidation';
                    $pdo->prepare("UPDATE orders SET status=? WHERE id=?")->execute([$newStatus, $id]);
                    $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('order',?,?,?,?)")
                        ->execute([$id, 'receive', json_encode(['actual_cbm' => $actualCbm, 'actual_weight' => $actualWeight, 'status' => $newStatus]), $userId]);
                    (new NotificationService($pdo))->notifyOrderReceived((int) $id, $userId, $hasVariance);
                    $pdo->commit();
                    jsonResponse(['data' => ['status' => $newStatus, 'receipt_id' => $receiptId, 'variance_detected' => $hasVariance]]);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
            }
            if ($id && $action === 'confirm') {
                $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
                $stmt->execute([$id]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$order) jsonError('Order not found', 404);
                if ($order['status'] !== 'AwaitingCustomerConfirmation') {
                    jsonError('Order is not awaiting customer confirmation', 400);
                }
                $pdo->prepare("UPDATE orders SET status='Confirmed' WHERE id=?")->execute([$id]);
                $wr = $pdo->prepare("SELECT * FROM warehouse_receipts WHERE order_id = ? ORDER BY received_at DESC LIMIT 1");
                $wr->execute([$id]);
                $receipt = $wr->fetch(PDO::FETCH_ASSOC);
                $accepted = $receipt ? ['actual_cbm' => $receipt['actual_cbm'], 'actual_weight' => $receipt['actual_weight'], 'actual_cartons' => $receipt['actual_cartons']] : [];
                $pdo->prepare("INSERT INTO customer_confirmations (order_id, confirmed_by, accepted_actuals) VALUES (?,?,?)")
                    ->execute([$id, $userId, json_encode($accepted)]);
                $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, user_id) VALUES ('order',?,'confirm',?)")->execute([$id, $userId]);
                jsonResponse(['data' => ['status' => 'Confirmed']]);
            }
            if ($id && in_array($action, ['submit', 'approve'], true)) {
                $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
                $stmt->execute([$id]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$order) jsonError('Order not found', 404);
                if ($action === 'submit') {
                    OrderStateService::validateTransition($order['status'], 'Submitted');
                    $si = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE order_id = ?");
                    $si->execute([$id]);
                    if ((int) $si->fetchColumn() === 0) {
                        jsonError('Order must have at least one item to submit', 400);
                    }
                    $pdo->prepare("UPDATE orders SET status='Submitted' WHERE id=?")->execute([$id]);
                    $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, user_id) VALUES ('order',?,'submit',?)")->execute([$id, $userId]);
                    jsonResponse(['data' => ['status' => 'Submitted']]);
                }
                if ($action === 'approve') {
                    OrderStateService::validateTransition($order['status'], 'Approved');
                    $pdo->prepare("UPDATE orders SET status='Approved' WHERE id=?")->execute([$id]);
                    $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, user_id) VALUES ('order',?,'approve',?)")->execute([$id, $userId]);
                    jsonResponse(['data' => ['status' => 'Approved']]);
                }
            }
            jsonError('Invalid action', 400);
            break;
    }

    jsonError('Method not allowed', 405);
};
