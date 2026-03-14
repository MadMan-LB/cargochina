<?php

/**
 * Procurement Drafts API - draft order lists for suppliers
 * Roles: ChinaAdmin, ChinaEmployee, SuperAdmin
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();
    if (!getAuthUserId()) jsonError('Unauthorized', 401);
    if (!hasAnyRole(['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin'])) jsonError('Forbidden', 403);

    if ($action === 'convert' && $method === 'POST' && $id) {
        $stmt = $pdo->prepare("SELECT * FROM procurement_drafts WHERE id = ?");
        $stmt->execute([$id]);
        $draft = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$draft) jsonError('Draft not found', 404);
        if ($draft['status'] === 'converted') jsonError('Draft already converted', 400);
        $customerId = (int) ($input['customer_id'] ?? 0);
        $expectedReady = trim($input['expected_ready_date'] ?? '');
        $currency = trim($input['currency'] ?? 'USD') ?: 'USD';
        if (!$customerId || !$expectedReady) jsonError('customer_id and expected_ready_date required', 400);
        if (!in_array($currency, ['USD', 'RMB'], true)) $currency = 'USD';
        $expectedDate = date('Y-m-d', strtotime($expectedReady));
        if ($expectedDate === '1970-01-01' || !$expectedDate) jsonError('Invalid expected_ready_date', 400);

        $items = $pdo->prepare("SELECT pdi.*, p.description_cn, p.description_en, p.cbm, p.weight, p.unit_price FROM procurement_draft_items pdi LEFT JOIN products p ON pdi.product_id = p.id WHERE pdi.draft_id = ? ORDER BY pdi.sort_order, pdi.id");
        $items->execute([$id]);
        $draftItems = $items->fetchAll(PDO::FETCH_ASSOC);
        if (empty($draftItems)) jsonError('Draft has no items', 400);

        $supplierId = $draft['supplier_id'] ? (int) $draft['supplier_id'] : null;
        $userId = getAuthUserId();

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO orders (customer_id, supplier_id, expected_ready_date, currency, status, order_type, created_by) VALUES (?,?,?,?,'Draft','draft_procurement',?)")
                ->execute([$customerId, $supplierId, $expectedDate, $currency, $userId]);
            $orderId = (int) $pdo->lastInsertId();

            $hasItemSupplier = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'supplier_id'")->rowCount() > 0;
            $insCols = "order_id, product_id, shipping_code, cartons, qty_per_carton, quantity, unit, declared_cbm, declared_weight, unit_price, total_amount, description_cn, description_en";
            $insVals = "?,?,?,?,?,?,?,?,?,?,?,?,?";
            if ($hasItemSupplier) {
                $insCols .= ", supplier_id";
                $insVals .= ",?";
            }
            $insItem = $pdo->prepare("INSERT INTO order_items ($insCols) VALUES ($insVals)");

            foreach ($draftItems as $it) {
                $qty = (float) ($it['quantity'] ?? 0);
                if ($qty <= 0) continue;
                $descCn = $it['description_cn'] ?? $it['notes'];
                $descEn = $it['description_en'] ?? $it['notes'];
                $cbm = (float) ($it['cbm'] ?? 0) * $qty;
                $weight = (float) ($it['weight'] ?? 0) * $qty;
                $unitPrice = (float) ($it['unit_price'] ?? 0);
                $params = [$orderId, $it['product_id'] ? (int) $it['product_id'] : null, null, null, null, $qty, 'pieces', $cbm, $weight, $unitPrice ?: null, $unitPrice && $qty > 0 ? $unitPrice * $qty : null, $descCn, $descEn];
                if ($hasItemSupplier) $params[] = $supplierId;
                $insItem->execute($params);
            }

            $pdo->prepare("UPDATE procurement_drafts SET status='converted', converted_order_id=? WHERE id=?")->execute([$orderId, $id]);
            $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('procurement_draft',?,?,?,?)")
                ->execute([$id, 'convert', json_encode(['order_id' => $orderId]), $userId]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }

        $stmt = $pdo->prepare("SELECT o.*, c.name as customer_name, s.name as supplier_name FROM orders o JOIN customers c ON o.customer_id = c.id LEFT JOIN suppliers s ON o.supplier_id = s.id WHERE o.id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        $chk = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'supplier_id'");
        $hasSupp = $chk && $chk->rowCount() > 0;
        $itemSql = $hasSupp ? "SELECT oi.*, s.name as supplier_name FROM order_items oi LEFT JOIN suppliers s ON oi.supplier_id = s.id WHERE oi.order_id = ?" : "SELECT oi.* FROM order_items oi WHERE oi.order_id = ?";
        $itemStmt = $pdo->prepare($itemSql);
        $itemStmt->execute([$orderId]);
        $order['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($order['items'] as &$it) {
            $it['image_paths'] = $it['image_paths'] ? json_decode($it['image_paths'], true) : [];
        }
        jsonResponse(['data' => ['order' => $order, 'converted_order_id' => $orderId]], 201);
    }

    switch ($method) {
        case 'GET':
            if ($id === null) {
                $stmt = $pdo->query("SELECT pd.*, s.name as supplier_name FROM procurement_drafts pd LEFT JOIN suppliers s ON pd.supplier_id = s.id ORDER BY pd.created_at DESC");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as &$r) {
                    $items = $pdo->prepare("SELECT pdi.*, p.description_cn, p.description_en FROM procurement_draft_items pdi LEFT JOIN products p ON pdi.product_id = p.id WHERE pdi.draft_id = ? ORDER BY pdi.sort_order, pdi.id");
                    $items->execute([$r['id']]);
                    $r['items'] = $items->fetchAll(PDO::FETCH_ASSOC);
                }
                jsonResponse(['data' => $rows]);
            }
            $stmt = $pdo->prepare("SELECT pd.*, s.name as supplier_name FROM procurement_drafts pd LEFT JOIN suppliers s ON pd.supplier_id = s.id WHERE pd.id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) jsonError('Draft not found', 404);
            $items = $pdo->prepare("SELECT pdi.*, p.description_cn, p.description_en, p.cbm, p.weight FROM procurement_draft_items pdi LEFT JOIN products p ON pdi.product_id = p.id WHERE pdi.draft_id = ? ORDER BY pdi.sort_order, pdi.id");
            $items->execute([$id]);
            $row['items'] = $items->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(['data' => $row]);

        case 'POST':
            $name = trim($input['name'] ?? '');
            if (!$name) jsonError('Name required', 400);
            $supplierId = !empty($input['supplier_id']) ? (int) $input['supplier_id'] : null;
            $userId = getAuthUserId();
            $pdo->prepare("INSERT INTO procurement_drafts (name, supplier_id, status, created_by) VALUES (?,?, 'draft', ?)")
                ->execute([$name, $supplierId, $userId]);
            $newId = (int) $pdo->lastInsertId();
            $items = $input['items'] ?? [];
            $ins = $pdo->prepare("INSERT INTO procurement_draft_items (draft_id, product_id, quantity, notes, sort_order) VALUES (?,?,?,?,?)");
            foreach ($items as $i => $it) {
                $ins->execute([$newId, !empty($it['product_id']) ? (int) $it['product_id'] : null, (float) ($it['quantity'] ?? 0), trim($it['notes'] ?? '') ?: null, $i]);
            }
            $stmt = $pdo->prepare("SELECT pd.*, s.name as supplier_name FROM procurement_drafts pd LEFT JOIN suppliers s ON pd.supplier_id = s.id WHERE pd.id = ?");
            $stmt->execute([$newId]);
            jsonResponse(['data' => $stmt->fetch(PDO::FETCH_ASSOC)], 201);

        case 'PUT':
            if (!$id) jsonError('ID required', 400);
            $stmt = $pdo->prepare("SELECT * FROM procurement_drafts WHERE id = ?");
            $stmt->execute([$id]);
            $draft = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$draft) jsonError('Draft not found', 404);
            if ($draft['status'] !== 'draft' && $draft['status'] !== 'pending_review') jsonError('Draft cannot be edited in current status', 400);

            $updates = [];
            $params = [];
            foreach (['name', 'supplier_id', 'status'] as $col) {
                if (array_key_exists($col, $input)) {
                    $v = $input[$col];
                    if ($col === 'supplier_id') $v = $v ? (int) $v : null;
                    $updates[] = "$col = ?";
                    $params[] = $v;
                }
            }
            if (!empty($updates)) {
                $params[] = $id;
                $pdo->prepare("UPDATE procurement_drafts SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
            }
            if (isset($input['items'])) {
                $pdo->prepare("DELETE FROM procurement_draft_items WHERE draft_id = ?")->execute([$id]);
                $ins = $pdo->prepare("INSERT INTO procurement_draft_items (draft_id, product_id, quantity, notes, sort_order) VALUES (?,?,?,?,?)");
                foreach ($input['items'] as $i => $it) {
                    $ins->execute([$id, !empty($it['product_id']) ? (int) $it['product_id'] : null, (float) ($it['quantity'] ?? 0), trim($it['notes'] ?? '') ?: null, $i]);
                }
            }
            $stmt = $pdo->prepare("SELECT pd.*, s.name as supplier_name FROM procurement_drafts pd LEFT JOIN suppliers s ON pd.supplier_id = s.id WHERE pd.id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $items = $pdo->prepare("SELECT pdi.*, p.description_cn, p.description_en FROM procurement_draft_items pdi LEFT JOIN products p ON pdi.product_id = p.id WHERE pdi.draft_id = ? ORDER BY pdi.sort_order");
            $items->execute([$id]);
            $row['items'] = $items->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(['data' => $row]);

        case 'DELETE':
            if (!$id) jsonError('ID required', 400);
            $stmt = $pdo->prepare("SELECT status FROM procurement_drafts WHERE id = ?");
            $stmt->execute([$id]);
            $d = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$d) jsonError('Draft not found', 404);
            if ($d['status'] !== 'draft' && $d['status'] !== 'cancelled') jsonError('Only draft or cancelled can be deleted', 400);
            $pdo->prepare("DELETE FROM procurement_drafts WHERE id = ?")->execute([$id]);
            jsonResponse(['data' => ['deleted' => true]]);

        default:
            jsonError('Method not allowed', 405);
    }
};
