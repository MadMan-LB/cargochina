<?php

/**
 * Customers API - GET list, GET one, POST create, PUT update, DELETE
 */

require_once __DIR__ . '/../helpers.php';

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
                $cols = ['id', 'code', 'name'];
                $hasPhone = false;
                try {
                    $chk = $pdo->query("SHOW COLUMNS FROM customers LIKE 'phone'");
                    $hasPhone = $chk && $chk->rowCount() > 0;
                } catch (Throwable $e) {
                }
                if ($hasPhone) $cols[] = 'phone';
                $sel = implode(', ', $cols);
                $stmt = $pdo->prepare("SELECT $sel FROM customers WHERE name LIKE ? OR code LIKE ? " . ($hasPhone ? "OR phone LIKE ? " : "") . "ORDER BY name LIMIT 20");
                $params = [$like, $like];
                if ($hasPhone) $params[] = $like;
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                jsonResponse(['data' => $rows]);
            }
            if ($id === null) {
                $q = trim($_GET['q'] ?? '');
                $sql = "SELECT * FROM customers";
                $params = [];
                if (strlen($q) >= 1) {
                    $like = '%' . preg_replace('/\s+/', '%', $q) . '%';
                    $hasPhone = false;
                    try {
                        $chk = $pdo->query("SHOW COLUMNS FROM customers LIKE 'phone'");
                        $hasPhone = $chk && $chk->rowCount() > 0;
                    } catch (Throwable $e) {
                    }
                    $sql .= " WHERE name LIKE ? OR code LIKE ?";
                    $params = [$like, $like];
                    if ($hasPhone) {
                        $sql .= " OR phone LIKE ?";
                        $params[] = $like;
                    }
                }
                $sql .= " ORDER BY name";
                $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
                if ($params) $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as &$r) {
                    $r['contacts'] = $r['contacts'] ? json_decode($r['contacts'], true) : [];
                    $r['addresses'] = $r['addresses'] ? json_decode($r['addresses'], true) : [];
                    $r['payment_links'] = isset($r['payment_links']) && $r['payment_links'] ? json_decode($r['payment_links'], true) : [];
                }
                jsonResponse(['data' => $rows]);
            }
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                jsonError('Customer not found', 404);
            }
            $row['contacts'] = $row['contacts'] ? json_decode($row['contacts'], true) : [];
            $row['addresses'] = $row['addresses'] ? json_decode($row['addresses'], true) : [];
            $row['payment_links'] = isset($row['payment_links']) && $row['payment_links'] ? json_decode($row['payment_links'], true) : [];
            if ($action === 'deposits') {
                $stmt2 = $pdo->prepare("SELECT * FROM customer_deposits WHERE customer_id = ? ORDER BY created_at DESC");
                $stmt2->execute([$id]);
                $row['deposits'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                jsonResponse(['data' => $row]);
            }
            if ($action === 'balance') {
                $stmt2 = $pdo->prepare("SELECT currency, SUM(amount) as total FROM customer_deposits WHERE customer_id = ? GROUP BY currency");
                $stmt2->execute([$id]);
                $bals = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                $balance = [];
                foreach ($bals as $b) $balance[$b['currency']] = (float) $b['total'];
                jsonResponse(['data' => $balance]);
            }
            jsonResponse(['data' => $row]);

        case 'POST':
            if ($id && $action === 'deposits') {
                $stmt = $pdo->prepare("SELECT id FROM customers WHERE id = ?");
                $stmt->execute([$id]);
                if (!$stmt->fetch()) jsonError('Customer not found', 404);
                $amount = (float) ($input['amount'] ?? 0);
                if ($amount <= 0) jsonError('Amount must be positive', 400);
                $currency = trim($input['currency'] ?? 'USD');
                if (!in_array($currency, ['USD', 'RMB'], true)) jsonError('Currency must be USD or RMB', 400);
                $paymentMethod = $input['payment_method'] ?? null;
                $referenceNo = $input['reference_no'] ?? null;
                $notes = $input['notes'] ?? null;
                $userId = getAuthUserId() ?? 1;
                $pdo->prepare("INSERT INTO customer_deposits (customer_id, amount, currency, payment_method, reference_no, notes, created_by) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$id, $amount, $currency, $paymentMethod, $referenceNo, $notes, $userId]);
                $newId = (int) $pdo->lastInsertId();
                logClms('customer_deposit', ['customer_id' => (int)$id, 'deposit_id' => $newId, 'amount' => $amount, 'currency' => $currency]);
                $stmt = $pdo->prepare("SELECT * FROM customer_deposits WHERE id = ?");
                $stmt->execute([$newId]);
                jsonResponse(['data' => $stmt->fetch(PDO::FETCH_ASSOC)], 201);
            }
            $code = trim($input['code'] ?? '');
            $name = trim($input['name'] ?? '');
            if (!$code || !$name) {
                jsonError('Missing required fields', 400, ['code' => 'Required', 'name' => 'Required']);
            }
            $contacts = isset($input['contacts']) ? json_encode($input['contacts']) : null;
            $addresses = isset($input['addresses']) ? json_encode($input['addresses']) : null;
            $paymentTerms = $input['payment_terms'] ?? null;
            $paymentLinks = isset($input['payment_links']) && is_array($input['payment_links'])
                ? json_encode(array_values(array_map(function ($p) {
                    return ['name' => trim($p['name'] ?? ''), 'value' => trim($p['value'] ?? '')];
                }, array_filter($input['payment_links'], function ($p) {
                    return !empty(trim($p['name'] ?? ''));
                }))))
                : null;
            $phone = !empty($input['phone']) ? trim($input['phone']) : null;
            $address = !empty($input['address']) ? trim($input['address']) : null;
            $hasPhone = false;
            $hasAddress = false;
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM customers WHERE Field IN ('phone','address')");
                $colsExist = $chk ? array_column($chk->fetchAll(PDO::FETCH_ASSOC), 'Field') : [];
                $hasPhone = in_array('phone', $colsExist, true);
                $hasAddress = in_array('address', $colsExist, true);
            } catch (Throwable $e) {
            }
            $cols = ['code', 'name', 'contacts', 'addresses', 'payment_terms'];
            $vals = [$code, $name, $contacts, $addresses, $paymentTerms];
            $hasPaymentLinks = false;
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM customers LIKE 'payment_links'");
                $hasPaymentLinks = $chk && $chk->rowCount() > 0;
            } catch (Throwable $e) {
            }
            if ($hasPaymentLinks) {
                $cols[] = 'payment_links';
                $vals[] = $paymentLinks;
            }
            if ($hasPhone) {
                $cols[] = 'phone';
                $vals[] = $phone;
            }
            if ($hasAddress) {
                $cols[] = 'address';
                $vals[] = $address;
            }
            $ph = implode(',', array_fill(0, count($vals), '?'));
            $colStr = implode(', ', $cols);
            try {
                $stmt = $pdo->prepare("INSERT INTO customers ($colStr) VALUES ($ph)");
                $stmt->execute($vals);
                $newId = (int) $pdo->lastInsertId();
                $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
                $stmt->execute([$newId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $row['contacts'] = $row['contacts'] ? json_decode($row['contacts'], true) : [];
                $row['addresses'] = $row['addresses'] ? json_decode($row['addresses'], true) : [];
                $row['payment_links'] = isset($row['payment_links']) && $row['payment_links'] ? json_decode($row['payment_links'], true) : [];
                jsonResponse(['data' => $row], 201);
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    jsonError('Customer code already exists', 409);
                }
                throw $e;
            }

        case 'PUT':
            if (!$id) {
                jsonError('ID required', 400);
            }
            $stmt = $pdo->prepare("SELECT id FROM customers WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                jsonError('Customer not found', 404);
            }
            $code = trim($input['code'] ?? '');
            $name = trim($input['name'] ?? '');
            if (!$code || !$name) {
                jsonError('Missing required fields', 400);
            }
            $contacts = isset($input['contacts']) ? json_encode($input['contacts']) : null;
            $addresses = isset($input['addresses']) ? json_encode($input['addresses']) : null;
            $paymentTerms = $input['payment_terms'] ?? null;
            $paymentLinks = isset($input['payment_links']) && is_array($input['payment_links'])
                ? json_encode(array_values(array_map(function ($p) {
                    return ['name' => trim($p['name'] ?? ''), 'value' => trim($p['value'] ?? '')];
                }, array_filter($input['payment_links'], function ($p) {
                    return !empty(trim($p['name'] ?? ''));
                }))))
                : null;
            $phone = isset($input['phone']) ? trim($input['phone']) : null;
            $address = isset($input['address']) ? trim($input['address']) : null;
            $hasPhone = false;
            $hasAddress = false;
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM customers WHERE Field IN ('phone','address')");
                while ($r = $chk->fetch(PDO::FETCH_ASSOC)) {
                    if ($r['Field'] === 'phone') $hasPhone = true;
                    if ($r['Field'] === 'address') $hasAddress = true;
                }
            } catch (Throwable $e) {
            }
            $sets = ['code=?', 'name=?', 'contacts=?', 'addresses=?', 'payment_terms=?'];
            $vals = [$code, $name, $contacts, $addresses, $paymentTerms];
            $hasPaymentLinks = false;
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM customers LIKE 'payment_links'");
                $hasPaymentLinks = $chk && $chk->rowCount() > 0;
            } catch (Throwable $e) {
            }
            if ($hasPaymentLinks) {
                $sets[] = 'payment_links=?';
                $vals[] = $paymentLinks;
            }
            if ($hasPhone) {
                $sets[] = 'phone=?';
                $vals[] = $phone;
            }
            if ($hasAddress) {
                $sets[] = 'address=?';
                $vals[] = $address;
            }
            $vals[] = $id;
            $pdo->prepare("UPDATE customers SET " . implode(', ', $sets) . " WHERE id=?")->execute($vals);
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $row['contacts'] = $row['contacts'] ? json_decode($row['contacts'], true) : [];
            $row['addresses'] = $row['addresses'] ? json_decode($row['addresses'], true) : [];
            $row['payment_links'] = isset($row['payment_links']) && $row['payment_links'] ? json_decode($row['payment_links'], true) : [];
            jsonResponse(['data' => $row]);

        case 'DELETE':
            if (!$id) {
                jsonError('ID required', 400);
            }
            $stmt = $pdo->prepare("DELETE FROM customers WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                jsonError('Customer not found', 404);
            }
            jsonResponse(['message' => 'Deleted']);

        default:
            jsonError('Method not allowed', 405);
    }
};
