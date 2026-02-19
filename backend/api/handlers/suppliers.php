<?php

/**
 * Suppliers API - GET list, GET one, POST create, PUT update, DELETE
 */

require_once __DIR__ . '/../helpers.php';

function validatePhone(?string $phone): void
{
    if ($phone === null || $phone === '') return;
    if (!preg_match('/^[\d\s\+\-\(\)\.]{6,50}$/', $phone)) {
        jsonError('Invalid phone format (use digits, +, -, parentheses, spaces)', 400);
    }
}

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();

    switch ($method) {
        case 'GET':
            if ($id === 'search') {
                $q = trim($_GET['q'] ?? '');
                if (strlen($q) < 1) {
                    jsonResponse(['data' => []]);
                }
                $like = '%' . preg_replace('/\s+/', '%', $q) . '%';
                $stmt = $pdo->prepare("SELECT id, code, name, phone, store_id FROM suppliers WHERE name LIKE ? OR code LIKE ? OR (phone IS NOT NULL AND phone LIKE ?) OR (store_id IS NOT NULL AND store_id LIKE ?) ORDER BY name LIMIT 10");
                $stmt->execute([$like, $like, $like, $like]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                jsonResponse(['data' => $rows]);
            }
            if ($id === null) {
                $stmt = $pdo->query("SELECT * FROM suppliers ORDER BY name");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as &$r) {
                    $r['contacts'] = $r['contacts'] ? json_decode($r['contacts'], true) : [];
                    $r['additional_ids'] = $r['additional_ids'] ? json_decode($r['additional_ids'], true) : [];
                }
                jsonResponse(['data' => $rows]);
            }
            $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                jsonError('Supplier not found', 404);
            }
            $row['contacts'] = $row['contacts'] ? json_decode($row['contacts'], true) : [];
            $row['additional_ids'] = $row['additional_ids'] ? json_decode($row['additional_ids'], true) : [];

            $payments = $pdo->prepare("SELECT * FROM supplier_payments WHERE supplier_id = ? ORDER BY created_at DESC");
            $payments->execute([$id]);
            $row['payments'] = $payments->fetchAll(PDO::FETCH_ASSOC);
            $interactions = $pdo->prepare("SELECT si.*, u.full_name as created_by_name FROM supplier_interactions si LEFT JOIN users u ON si.created_by = u.id WHERE si.supplier_id = ? ORDER BY si.created_at DESC");
            $interactions->execute([$id]);
            $intRows = $interactions->fetchAll(PDO::FETCH_ASSOC);
            foreach ($intRows as &$r) {
                $r['content'] = $r['content'] ? json_decode($r['content'], true) : null;
            }
            $row['interactions'] = $intRows;
            jsonResponse(['data' => $row]);

        case 'POST':
            if ($id && $action === 'payments') {
                $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE id = ?");
                $stmt->execute([$id]);
                if (!$stmt->fetch()) jsonError('Supplier not found', 404);
                $amount = (float) ($input['amount'] ?? 0);
                $currency = trim($input['currency'] ?? 'USD');
                $paymentType = in_array($input['payment_type'] ?? '', ['partial', 'full']) ? $input['payment_type'] : 'partial';
                $notes = $input['notes'] ?? null;
                $orderId = !empty($input['order_id']) ? (int) $input['order_id'] : null;
                if ($amount <= 0) jsonError('Amount must be positive', 400);
                $pdo->prepare("INSERT INTO supplier_payments (supplier_id, order_id, amount, currency, payment_type, notes) VALUES (?,?,?,?,?,?)")
                    ->execute([$id, $orderId, $amount, $currency, $paymentType, $notes]);
                $newId = (int) $pdo->lastInsertId();
                $row = $pdo->prepare("SELECT * FROM supplier_payments WHERE id = ?");
                $row->execute([$newId]);
                jsonResponse(['data' => $row->fetch(PDO::FETCH_ASSOC)], 201);
            }
            if ($id && $action === 'interactions') {
                $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE id = ?");
                $stmt->execute([$id]);
                if (!$stmt->fetch()) jsonError('Supplier not found', 404);
                $type = in_array($input['interaction_type'] ?? '', ['visit', 'quote', 'note']) ? $input['interaction_type'] : 'visit';
                $content = isset($input['content']) ? json_encode($input['content']) : null;
                $userId = getAuthUserId();
                $pdo->prepare("INSERT INTO supplier_interactions (supplier_id, interaction_type, content, created_by) VALUES (?,?,?,?)")
                    ->execute([$id, $type, $content, $userId]);
                $newId = (int) $pdo->lastInsertId();
                $row = $pdo->prepare("SELECT si.*, u.full_name as created_by_name FROM supplier_interactions si LEFT JOIN users u ON si.created_by = u.id WHERE si.id = ?");
                $row->execute([$newId]);
                $r = $row->fetch(PDO::FETCH_ASSOC);
                $r['content'] = $r['content'] ? json_decode($r['content'], true) : null;
                jsonResponse(['data' => $r], 201);
            }
            $code = trim($input['code'] ?? '');
            $name = trim($input['name'] ?? '');
            if (!$code || !$name) {
                jsonError('Missing required fields', 400, ['code' => 'Required', 'name' => 'Required']);
            }
            $phone = isset($input['phone']) ? trim($input['phone']) : null;
            validatePhone($phone);
            $storeId = isset($input['store_id']) ? trim($input['store_id']) : null;
            $additionalIds = isset($input['additional_ids']) && is_array($input['additional_ids']) ? json_encode($input['additional_ids']) : null;
            $contacts = isset($input['contacts']) ? json_encode($input['contacts']) : null;
            $factoryLocation = $input['factory_location'] ?? null;
            $notes = $input['notes'] ?? null;
            try {
                $stmt = $pdo->prepare("INSERT INTO suppliers (code, name, store_id, contacts, factory_location, notes, phone, additional_ids) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$code, $name, $storeId ?: null, $contacts, $factoryLocation, $notes, $phone ?: null, $additionalIds]);
                $newId = (int) $pdo->lastInsertId();
                $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
                $stmt->execute([$newId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $row['contacts'] = $row['contacts'] ? json_decode($row['contacts'], true) : [];
                $row['additional_ids'] = $row['additional_ids'] ? json_decode($row['additional_ids'], true) : [];
                jsonResponse(['data' => $row], 201);
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    jsonError('Supplier code already exists', 409);
                }
                throw $e;
            }

        case 'PUT':
            if (!$id) {
                jsonError('ID required', 400);
            }
            $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                jsonError('Supplier not found', 404);
            }
            $code = trim($input['code'] ?? '');
            $name = trim($input['name'] ?? '');
            if (!$code || !$name) {
                jsonError('Missing required fields', 400);
            }
            $phone = isset($input['phone']) ? trim($input['phone']) : null;
            validatePhone($phone);
            $storeId = isset($input['store_id']) ? trim($input['store_id']) : null;
            $additionalIds = isset($input['additional_ids']) && is_array($input['additional_ids']) ? json_encode($input['additional_ids']) : null;
            $contacts = isset($input['contacts']) ? json_encode($input['contacts']) : null;
            $factoryLocation = $input['factory_location'] ?? null;
            $notes = $input['notes'] ?? null;
            $pdo->prepare("UPDATE suppliers SET code=?, name=?, store_id=?, contacts=?, factory_location=?, notes=?, phone=?, additional_ids=? WHERE id=?")
                ->execute([$code, $name, $storeId ?: null, $contacts, $factoryLocation, $notes, $phone ?: null, $additionalIds, $id]);
            $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $row['contacts'] = $row['contacts'] ? json_decode($row['contacts'], true) : [];
            $row['additional_ids'] = $row['additional_ids'] ? json_decode($row['additional_ids'], true) : [];
            jsonResponse(['data' => $row]);

        case 'DELETE':
            if (!$id) {
                jsonError('ID required', 400);
            }
            $stmt = $pdo->prepare("DELETE FROM suppliers WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                jsonError('Supplier not found', 404);
            }
            jsonResponse(['message' => 'Deleted']);

        default:
            jsonError('Method not allowed', 405);
    }
};
