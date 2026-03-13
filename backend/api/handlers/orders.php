<?php

/**
 * Orders API - CRUD, submit, approve, attachments
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 2) . '/services/OrderStateService.php';
require_once dirname(__DIR__, 2) . '/services/NotificationService.php';

function normalizeOrderItems(array $items): array
{
    foreach ($items as &$it) {
        $it['image_paths'] = $it['image_paths'] ? json_decode($it['image_paths'], true) : [];
    }
    return $items;
}

function fetchOrderItems(PDO $pdo, int $orderId): array
{
    $chk = @$pdo->query("SHOW COLUMNS FROM order_items LIKE 'supplier_id'");
    $hasSupplier = $chk && $chk->rowCount() > 0;
    $sql = $hasSupplier
        ? "SELECT oi.*, s.name as supplier_name FROM order_items oi LEFT JOIN suppliers s ON oi.supplier_id = s.id WHERE oi.order_id = ?"
        : "SELECT oi.* FROM order_items oi WHERE oi.order_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Sync order item data back to product when user corrects info in the order form */
function syncProductFromOrderItem(PDO $pdo, array $it): void
{
    $productId = !empty($it['product_id']) ? (int) $it['product_id'] : null;
    if (!$productId) return;
    $qty = (float) ($it['quantity'] ?? 0);
    if ($qty <= 0) $qty = 1;
    $cbmTotal = (float) ($it['declared_cbm'] ?? 0);
    $weightTotal = (float) ($it['declared_weight'] ?? 0);
    $sets = [];
    $vals = [];
    if (isset($it['description_cn'])) {
        $sets[] = 'description_cn=?';
        $vals[] = $it['description_cn'] ?: null;
    }
    if (isset($it['description_en'])) {
        $sets[] = 'description_en=?';
        $vals[] = $it['description_en'] ?: null;
    }
    if (isset($it['unit_price'])) {
        $sets[] = 'unit_price=?';
        $vals[] = $it['unit_price'] !== null && $it['unit_price'] !== '' ? (float) $it['unit_price'] : null;
    }
    if ($weightTotal > 0) {
        $sets[] = 'weight=?';
        $vals[] = $weightTotal / $qty;
    }
    if ($cbmTotal > 0) {
        $sets[] = 'cbm=?';
        $vals[] = $cbmTotal / $qty;
    }
    if (isset($it['item_length']) && $it['item_length'] !== null && $it['item_length'] !== '') {
        $sets[] = 'length_cm=?';
        $vals[] = (float) $it['item_length'];
    }
    if (isset($it['item_width']) && $it['item_width'] !== null && $it['item_width'] !== '') {
        $sets[] = 'width_cm=?';
        $vals[] = (float) $it['item_width'];
    }
    if (isset($it['item_height']) && $it['item_height'] !== null && $it['item_height'] !== '') {
        $sets[] = 'height_cm=?';
        $vals[] = (float) $it['item_height'];
    }
    if (isset($it['qty_per_carton']) && $it['qty_per_carton'] !== null && $it['qty_per_carton'] !== '') {
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM products LIKE 'pieces_per_carton'");
            if ($chk && $chk->rowCount() > 0) {
                $sets[] = 'pieces_per_carton=?';
                $vals[] = (int) $it['qty_per_carton'];
            }
        } catch (Throwable $e) {
        }
    }
    if (empty($sets)) return;
    $vals[] = $productId;
    $pdo->prepare("UPDATE products SET " . implode(', ', $sets) . " WHERE id=?")->execute($vals);
}

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();
    $userId = getAuthUserId() ?? 1; // Dev fallback

    switch ($method) {
        case 'GET':
            if ($id && $action === 'export') {
                $suppCols = 's.name as supplier_name, s.phone as supplier_phone, s.factory_location as supplier_factory';
                $chk = @$pdo->query("SHOW COLUMNS FROM suppliers LIKE 'address'");
                if ($chk && $chk->rowCount() > 0) $suppCols .= ', s.address as supplier_address';
                $chk = @$pdo->query("SHOW COLUMNS FROM suppliers LIKE 'fax'");
                if ($chk && $chk->rowCount() > 0) $suppCols .= ', s.fax as supplier_fax';
                $stmt = $pdo->prepare("SELECT o.*, c.name as customer_name, $suppCols FROM orders o JOIN customers c ON o.customer_id = c.id JOIN suppliers s ON o.supplier_id = s.id WHERE o.id = ?");
                $stmt->execute([$id]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$order) jsonError('Order not found', 404);
                $items = normalizeOrderItems(fetchOrderItems($pdo, (int) $id));
                require_once dirname(__DIR__, 2) . '/services/OrderExcelService.php';
                (new OrderExcelService())->exportOrder($order, $items);
            }
            if ($id === null) {
                $status = $_GET['status'] ?? null;
                $customerId = $_GET['customer_id'] ?? null;
                $supplierId = $_GET['supplier_id'] ?? null;
                $dateFrom = $_GET['date_from'] ?? null;
                $dateTo = $_GET['date_to'] ?? null;
                $orderId = $_GET['order_id'] ?? null;
                $shippingCode = trim($_GET['shipping_code'] ?? '');
                $q = trim($_GET['q'] ?? '');
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
                if ($supplierId) {
                    $sql .= " AND o.supplier_id = ?";
                    $params[] = $supplierId;
                }
                if ($dateFrom) {
                    $sql .= " AND o.expected_ready_date >= ?";
                    $params[] = $dateFrom;
                }
                if ($dateTo) {
                    $sql .= " AND o.expected_ready_date <= ?";
                    $params[] = $dateTo;
                }
                if ($orderId) {
                    $sql .= " AND o.id = ?";
                    $params[] = (int) $orderId;
                }
                if ($shippingCode !== '') {
                    $sql .= " AND EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND oi.shipping_code LIKE ?)";
                    $params[] = '%' . $shippingCode . '%';
                }
                if ($q !== '') {
                    $like = '%' . $q . '%';
                    $chkSupp = $pdo->query("SHOW COLUMNS FROM suppliers LIKE 'phone'");
                    $hasSuppPhone = $chkSupp && $chkSupp->rowCount() > 0;
                    $chkCust = $pdo->query("SHOW COLUMNS FROM customers LIKE 'phone'");
                    $hasCustPhone = $chkCust && $chkCust->rowCount() > 0;
                    $sql .= " AND (c.name LIKE ? OR c.code LIKE ?";
                    $params[] = $like;
                    $params[] = $like;
                    if ($hasCustPhone) {
                        $sql .= " OR c.phone LIKE ?";
                        $params[] = $like;
                    }
                    $sql .= " OR s.name LIKE ?";
                    $params[] = $like;
                    if ($hasSuppPhone) {
                        $sql .= " OR s.phone LIKE ?";
                        $params[] = $like;
                    }
                    if (is_numeric($q)) {
                        $sql .= " OR o.id = ?";
                        $params[] = (int) $q;
                    }
                    $sql .= " OR EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND (oi.shipping_code LIKE ? OR oi.item_no LIKE ? OR oi.description_cn LIKE ? OR oi.description_en LIKE ?))";
                    $params[] = $like;
                    $params[] = $like;
                    $params[] = $like;
                    $params[] = $like;
                    $sql .= ")";
                }
                $sql .= " ORDER BY o.expected_ready_date ASC, o.created_at DESC";
                $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
                if ($params) $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as &$r) {
                    $r['items'] = normalizeOrderItems(fetchOrderItems($pdo, (int) $r['id']));
                }
                jsonResponse(['data' => $rows]);
            }
            $stmt = $pdo->prepare("SELECT o.*, c.name as customer_name, s.name as supplier_name FROM orders o JOIN customers c ON o.customer_id = c.id JOIN suppliers s ON o.supplier_id = s.id WHERE o.id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) jsonError('Order not found', 404);
            $row['items'] = normalizeOrderItems(fetchOrderItems($pdo, (int) $id));
            $att = $pdo->prepare("SELECT * FROM order_attachments WHERE order_id = ?");
            $att->execute([$id]);
            $row['attachments'] = $att->fetchAll(PDO::FETCH_ASSOC);
            $wr = $pdo->prepare("SELECT * FROM warehouse_receipts WHERE order_id = ? ORDER BY received_at DESC LIMIT 1");
            $wr->execute([$id]);
            $receipt = $wr->fetch(PDO::FETCH_ASSOC);
            if ($receipt) {
                $row['receipt'] = $receipt;
                $rip = $pdo->prepare("SELECT * FROM warehouse_receipt_photos WHERE receipt_id = ?");
                $rip->execute([$receipt['id']]);
                $row['receipt']['photos'] = $rip->fetchAll(PDO::FETCH_ASSOC);
                $rii = $pdo->prepare("SELECT wri.*, oi.description_cn, oi.description_en FROM warehouse_receipt_items wri JOIN order_items oi ON wri.order_item_id = oi.id WHERE wri.receipt_id = ?");
                $rii->execute([$receipt['id']]);
                $row['receipt']['items'] = $rii->fetchAll(PDO::FETCH_ASSOC);
                $config = require dirname(__DIR__, 2) . '/config/config.php';
                $row['customer_photo_visibility'] = $config['customer_photo_visibility'] ?? 'internal-only';
            }
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
                $hasItemSupplier = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'supplier_id'")->rowCount() > 0;
                $insCols = "order_id, product_id, item_no, shipping_code, cartons, qty_per_carton, quantity, unit, declared_cbm, declared_weight, item_length, item_width, item_height, unit_price, total_amount, notes, image_paths, description_cn, description_en";
                $insVals = "?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?";
                if ($hasItemSupplier) {
                    $insCols .= ", supplier_id";
                    $insVals .= ",?";
                }
                $insItem = $pdo->prepare("INSERT INTO order_items ($insCols) VALUES ($insVals)");
                foreach ($items as $it) {
                    $qty = (float) ($it['quantity'] ?? 0);
                    $cartons = isset($it['cartons']) ? (int) $it['cartons'] : null;
                    $qtyPerCtn = isset($it['qty_per_carton']) ? (float) $it['qty_per_carton'] : null;
                    if ($cartons !== null && $qtyPerCtn !== null && $qtyPerCtn > 0) {
                        $qty = $cartons * $qtyPerCtn;
                    }
                    $l = isset($it['item_length']) ? (float) $it['item_length'] : null;
                    $w = isset($it['item_width']) ? (float) $it['item_width'] : null;
                    $h = isset($it['item_height']) ? (float) $it['item_height'] : null;
                    $unitPrice = isset($it['unit_price']) ? (float) $it['unit_price'] : null;
                    $totalAmount = isset($it['total_amount']) ? (float) $it['total_amount'] : ($unitPrice !== null && $qty > 0 ? $unitPrice * $qty : null);
                    $imagePaths = isset($it['image_paths']) && is_array($it['image_paths']) ? json_encode($it['image_paths']) : null;
                    $itemSupplierId = !empty($it['supplier_id']) ? (int) $it['supplier_id'] : $supplierId;
                    $params = [
                        $id,
                        !empty($it['product_id']) ? (int) $it['product_id'] : null,
                        $it['item_no'] ?? null,
                        $it['shipping_code'] ?? null,
                        $cartons,
                        $qtyPerCtn,
                        $qty,
                        $it['unit'] ?? 'pieces',
                        (float) ($it['declared_cbm'] ?? 0),
                        (float) ($it['declared_weight'] ?? 0),
                        $l,
                        $w,
                        $h,
                        $unitPrice,
                        $totalAmount,
                        $it['notes'] ?? null,
                        $imagePaths,
                        $it['description_cn'] ?? null,
                        $it['description_en'] ?? null
                    ];
                    if ($hasItemSupplier) {
                        $params[] = $itemSupplierId ?: null;
                    }
                    $insItem->execute($params);
                    syncProductFromOrderItem($pdo, $it);
                }
                $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('order',?,?,?,?)")
                    ->execute([$id, 'update', json_encode($input), $userId]);
                $pdo->commit();
                $stmt = $pdo->prepare("SELECT o.*, c.name as customer_name, s.name as supplier_name FROM orders o JOIN customers c ON o.customer_id = c.id JOIN suppliers s ON o.supplier_id = s.id WHERE o.id = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $row['items'] = normalizeOrderItems(fetchOrderItems($pdo, (int) $id));
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
                $currency = trim($input['currency'] ?? 'USD');
                $items = $input['items'] ?? [];
                if (!$customerId || !$supplierId || !$expectedReady) {
                    jsonError('Missing required: customer_id, supplier_id, expected_ready_date', 400);
                }
                if (!in_array($currency, ['USD', 'RMB'], true)) {
                    jsonError('Currency must be USD or RMB', 400);
                }
                $expectedDate = date('Y-m-d', strtotime($expectedReady));
                if ($expectedDate === '1970-01-01' || !$expectedDate) {
                    jsonError('Invalid expected_ready_date', 400);
                }
                foreach ($items as $it) {
                    $qty = (float) ($it['quantity'] ?? 0);
                    $cartons = isset($it['cartons']) ? (int) $it['cartons'] : null;
                    $qtyPerCtn = isset($it['qty_per_carton']) ? (float) $it['qty_per_carton'] : null;
                    if ($cartons !== null && $qtyPerCtn !== null && $qtyPerCtn > 0) {
                        $qty = $cartons * $qtyPerCtn;
                    }
                    $unit = $it['unit'] ?? 'pieces';
                    $cbm = (float) ($it['declared_cbm'] ?? 0);
                    $weight = (float) ($it['declared_weight'] ?? 0);
                    if ($qty <= 0 || !in_array($unit, ['cartons', 'pieces']) || $cbm < 0 || $weight < 0) {
                        jsonError('Invalid item: quantity>0 (or cartons*qty_per_carton), unit in [cartons,pieces], cbm/weight>=0', 400);
                    }
                }
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("INSERT INTO orders (customer_id, supplier_id, expected_ready_date, currency, status, created_by) VALUES (?,?,?,?,'Draft',?)")
                        ->execute([$customerId, $supplierId, $expectedDate, $currency, $userId]);
                    $orderId = (int) $pdo->lastInsertId();
                    $hasItemSupplier = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'supplier_id'")->rowCount() > 0;
                    $insCols = "order_id, product_id, item_no, shipping_code, cartons, qty_per_carton, quantity, unit, declared_cbm, declared_weight, item_length, item_width, item_height, unit_price, total_amount, notes, image_paths, description_cn, description_en";
                    $insVals = "?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?";
                    if ($hasItemSupplier) {
                        $insCols .= ", supplier_id";
                        $insVals .= ",?";
                    }
                    $insItem = $pdo->prepare("INSERT INTO order_items ($insCols) VALUES ($insVals)");
                    foreach ($items as $it) {
                        $qty = (float) ($it['quantity'] ?? 0);
                        $cartons = isset($it['cartons']) ? (int) $it['cartons'] : null;
                        $qtyPerCtn = isset($it['qty_per_carton']) ? (float) $it['qty_per_carton'] : null;
                        if ($cartons !== null && $qtyPerCtn !== null && $qtyPerCtn > 0) {
                            $qty = $cartons * $qtyPerCtn;
                        }
                        $unitPrice = isset($it['unit_price']) ? (float) $it['unit_price'] : null;
                        $totalAmount = isset($it['total_amount']) ? (float) $it['total_amount'] : ($unitPrice !== null && $qty > 0 ? $unitPrice * $qty : null);
                        $imagePaths = isset($it['image_paths']) && is_array($it['image_paths']) ? json_encode($it['image_paths']) : null;
                        $itemSupplierId = !empty($it['supplier_id']) ? (int) $it['supplier_id'] : $supplierId;
                        $params = [
                            $orderId,
                            !empty($it['product_id']) ? (int) $it['product_id'] : null,
                            $it['item_no'] ?? null,
                            $it['shipping_code'] ?? null,
                            $cartons,
                            $qtyPerCtn,
                            $qty,
                            $it['unit'] ?? 'pieces',
                            (float) ($it['declared_cbm'] ?? 0),
                            (float) ($it['declared_weight'] ?? 0),
                            isset($it['item_length']) ? (float) $it['item_length'] : null,
                            isset($it['item_width']) ? (float) $it['item_width'] : null,
                            isset($it['item_height']) ? (float) $it['item_height'] : null,
                            $unitPrice,
                            $totalAmount,
                            $it['notes'] ?? null,
                            $imagePaths,
                            $it['description_cn'] ?? null,
                            $it['description_en'] ?? null
                        ];
                        if ($hasItemSupplier) {
                            $params[] = $itemSupplierId ?: null;
                        }
                        $insItem->execute($params);
                        syncProductFromOrderItem($pdo, $it);
                    }
                    $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('order',?,?,?,?)")
                        ->execute([$orderId, 'create', json_encode(['status' => 'Draft']), $userId]);
                    (new NotificationService($pdo))->notifyOrderCreated($orderId, $userId);
                    $pdo->commit();
                    $stmt = $pdo->prepare("SELECT o.*, c.name as customer_name, s.name as supplier_name FROM orders o JOIN customers c ON o.customer_id = c.id JOIN suppliers s ON o.supplier_id = s.id WHERE o.id = ?");
                    $stmt->execute([$orderId]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $row['items'] = normalizeOrderItems(fetchOrderItems($pdo, $orderId));
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
                $itemsInput = $input['items'] ?? [];
                $config = require dirname(__DIR__, 2) . '/config/config.php';
                $thresholdPct = $config['variance_threshold_percent'] ?? 10;
                $thresholdAbs = $config['variance_threshold_abs_cbm'] ?? 0.1;
                $photoEvidencePerItem = (int) ($config['photo_evidence_per_item'] ?? 0);
                $itemLevelEnabled = (int) ($config['item_level_receiving_enabled'] ?? 0);

                $orderItems = $pdo->prepare("SELECT id, declared_cbm, declared_weight FROM order_items WHERE order_id = ?");
                $orderItems->execute([$id]);
                $orderItemsRows = $orderItems->fetchAll(PDO::FETCH_ASSOC);
                $declaredCbm = array_sum(array_column($orderItemsRows, 'declared_cbm'));
                $declaredWeight = array_sum(array_column($orderItemsRows, 'declared_weight'));

                $hasVariance = false;
                $itemVariances = [];
                if (!empty($itemsInput)) {
                    $sumCbm = 0;
                    $sumWeight = 0;
                    $sumCartons = 0;
                    $errors = [];
                    foreach ($itemsInput as $idx => $it) {
                        $oiId = (int) ($it['order_item_id'] ?? 0);
                        $oi = null;
                        foreach ($orderItemsRows as $o) {
                            if ((int) $o['id'] === $oiId) {
                                $oi = $o;
                                break;
                            }
                        }
                        if (!$oi) {
                            $errors["items.$idx.order_item_id"] = 'Invalid order_item_id';
                            continue;
                        }
                        $aCbm = isset($it['actual_cbm']) ? (float) $it['actual_cbm'] : null;
                        $aWeight = isset($it['actual_weight']) ? (float) $it['actual_weight'] : null;
                        $aCartons = isset($it['actual_cartons']) ? (int) $it['actual_cartons'] : null;
                        $itCond = $it['condition'] ?? 'good';
                        if (!in_array($itCond, ['good', 'damaged', 'partial'])) $itCond = 'good';
                        $itPhotos = $it['photo_paths'] ?? [];
                        $decCbm = (float) $oi['declared_cbm'];
                        $decWeight = (float) $oi['declared_weight'];
                        $varPct = $decCbm > 0 ? abs(($aCbm ?? 0) - $decCbm) / $decCbm * 100 : 0;
                        $varAbs = abs(($aCbm ?? 0) - $decCbm);
                        $itemVar = $varPct >= $thresholdPct || $varAbs >= $thresholdAbs || $itCond !== 'good';
                        $itemVariances[$oiId] = $itemVar;
                        if ($itemVar) $hasVariance = true;
                        if ($aCbm !== null) $sumCbm += $aCbm;
                        if ($aWeight !== null) $sumWeight += $aWeight;
                        if ($aCartons !== null) $sumCartons += $aCartons;
                        if ($photoEvidencePerItem && $itemVar && empty($itPhotos)) {
                            $errors["items.$idx.photo_paths"] = 'Photo evidence required for item with variance';
                        }
                    }
                    if (!empty($errors)) jsonError('Validation failed', 400, $errors);
                    $tolerance = 0.01;
                    if (abs($sumCbm - $actualCbm) > $tolerance || abs($sumWeight - $actualWeight) > $tolerance) {
                        jsonError('Item-level totals must match order-level actuals (CBM/weight)', 400);
                    }
                } else {
                    $variancePct = $declaredCbm > 0 ? abs($actualCbm - $declaredCbm) / $declaredCbm * 100 : 0;
                    $varianceAbs = abs($actualCbm - $declaredCbm);
                    $hasVariance = $variancePct >= $thresholdPct || $varianceAbs >= $thresholdAbs || $condition !== 'good';
                }
                if ($hasVariance && empty($photoPaths)) {
                    jsonError('Evidence photos required when variance or damage is present', 400);
                }
                if ($itemLevelEnabled && empty($itemsInput)) {
                    jsonError('Item-level receiving is required; provide items array', 400);
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
                    if (!empty($itemsInput)) {
                        $insItem = $pdo->prepare("INSERT INTO warehouse_receipt_items (receipt_id, order_item_id, actual_cartons, actual_cbm, actual_weight, receipt_condition, variance_detected, notes) VALUES (?,?,?,?,?,?,?,?)");
                        $insItemPhoto = $pdo->prepare("INSERT INTO warehouse_receipt_item_photos (receipt_item_id, file_path) VALUES (?,?)");
                        foreach ($itemsInput as $it) {
                            $oiId = (int) ($it['order_item_id'] ?? 0);
                            $aCbm = isset($it['actual_cbm']) ? (float) $it['actual_cbm'] : null;
                            $aWeight = isset($it['actual_weight']) ? (float) $it['actual_weight'] : null;
                            $aCartons = isset($it['actual_cartons']) ? (int) $it['actual_cartons'] : null;
                            $itCond = in_array($it['condition'] ?? 'good', ['good', 'damaged', 'partial']) ? ($it['condition'] ?? 'good') : 'good';
                            $varDet = $itemVariances[$oiId] ?? 0;
                            $insItem->execute([$receiptId, $oiId, $aCartons, $aCbm, $aWeight, $itCond, $varDet ? 1 : 0, $it['notes'] ?? null]);
                            $riId = (int) $pdo->lastInsertId();
                            foreach ($it['photo_paths'] ?? [] as $p) {
                                $insItemPhoto->execute([$riId, $p]);
                            }
                        }
                    }
                    $newStatus = $hasVariance ? 'AwaitingCustomerConfirmation' : 'ReadyForConsolidation';
                    $confirmToken = null;
                    if ($hasVariance) {
                        $confirmToken = bin2hex(random_bytes(24));
                        $pdo->prepare("UPDATE orders SET status=?, confirmation_token=? WHERE id=?")->execute([$newStatus, $confirmToken, $id]);
                    } else {
                        $pdo->prepare("UPDATE orders SET status=? WHERE id=?")->execute([$newStatus, $id]);
                    }
                    $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('order',?,?,?,?)")
                        ->execute([$id, 'receive', json_encode(['actual_cbm' => $actualCbm, 'actual_weight' => $actualWeight, 'status' => $newStatus, 'receipt_id' => $receiptId]), $userId]);
                    logClms('order_received', ['order_id' => (int) $id, 'receipt_id' => $receiptId, 'user_id' => $userId, 'item_level' => !empty($itemsInput), 'variance_detected' => $hasVariance]);
                    (new NotificationService($pdo))->notifyOrderReceived((int) $id, $userId, $hasVariance, $confirmToken);
                    $pdo->commit();
                    jsonResponse(['data' => ['status' => $newStatus, 'receipt_id' => $receiptId, 'variance_detected' => $hasVariance]]);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
            }
            if ($id && $action === 'confirm') {
                $token = $input['token'] ?? null;
                $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
                $stmt->execute([$id]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$order) jsonError('Order not found', 404);
                if ($order['status'] !== 'AwaitingCustomerConfirmation') {
                    jsonError('Order is not awaiting customer confirmation', 400);
                }
                // Allow staff session-based confirm OR token-based public confirm
                if ($token && !hash_equals((string)($order['confirmation_token'] ?? ''), $token)) {
                    jsonError('Invalid or expired confirmation token', 403);
                }
                $pdo->prepare("UPDATE orders SET status='Confirmed', confirmation_token=NULL WHERE id=?")->execute([$id]);
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
                    $config = require dirname(__DIR__, 2) . '/config/config.php';
                    $minPhotos = (int) ($config['min_photos_per_item'] ?? 0);
                    if ($minPhotos > 0) {
                        $itemsWithPhotos = $pdo->prepare("SELECT id, image_paths FROM order_items WHERE order_id = ?");
                        $itemsWithPhotos->execute([$id]);
                        while ($row = $itemsWithPhotos->fetch(PDO::FETCH_ASSOC)) {
                            $paths = $row['image_paths'] ? (json_decode($row['image_paths'], true) ?? []) : [];
                            if (!is_array($paths) || count($paths) < $minPhotos) {
                                jsonError("Each item must have at least $minPhotos photo(s). Item #{$row['id']} has insufficient photos.", 400);
                            }
                        }
                    }
                    $pdo->prepare("UPDATE orders SET status='Submitted' WHERE id=?")->execute([$id]);
                    $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, user_id) VALUES ('order',?,'submit',?)")->execute([$id, $userId]);
                    (new NotificationService($pdo))->notifyOrderSubmitted((int) $id);
                    jsonResponse(['data' => ['status' => 'Submitted']]);
                }
                if ($action === 'approve') {
                    OrderStateService::validateTransition($order['status'], 'Approved');
                    $pdo->prepare("UPDATE orders SET status='Approved' WHERE id=?")->execute([$id]);
                    $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, user_id) VALUES ('order',?,'approve',?)")->execute([$id, $userId]);
                    (new NotificationService($pdo))->notifyOrderApproved((int) $id);
                    jsonResponse(['data' => ['status' => 'Approved']]);
                }
            }
            jsonError('Invalid action', 400);
            break;
    }

    jsonError('Method not allowed', 405);
};
