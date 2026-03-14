<?php

/**
 * Customers API - GET list, GET one, POST create, PUT update, DELETE
 */

require_once __DIR__ . '/../helpers.php';

function normalizeCustomerPriority(array $input): array
{
    $priorityLevel = trim((string) ($input['priority_level'] ?? 'normal'));
    if (!in_array($priorityLevel, ['normal', 'medium', 'high', 'critical'], true)) {
        $priorityLevel = 'normal';
    }
    $priorityNote = isset($input['priority_note']) ? trim((string) $input['priority_note']) : null;
    if ($priorityLevel === 'normal') {
        $priorityNote = $priorityNote ?: null;
    } elseif ($priorityNote === '') {
        jsonError('Priority note is required when priority is not normal', 400);
    }

    return [$priorityLevel, $priorityNote ?: null];
}

function findDuplicateCustomerShippingCode(PDO $pdo, ?string $shippingCode, int $excludeCustomerId = 0): ?array
{
    $shippingCode = trim((string) $shippingCode);
    if ($shippingCode === '') {
        return null;
    }

    $chk = @$pdo->query("SHOW COLUMNS FROM customers LIKE 'default_shipping_code'");
    if (!$chk || $chk->rowCount() === 0) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT id, code, name FROM customers WHERE default_shipping_code = ? AND id != ? LIMIT 1");
    $stmt->execute([$shippingCode, $excludeCustomerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
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
                $cols = ['id', 'code', 'name', 'default_shipping_code'];
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
            if ($id === 'import') {
                $csv = trim($input['csv'] ?? $input['data'] ?? '');
                if (!$csv) jsonError('No CSV data provided', 400);
                $lines = preg_split('/\r\n|\r|\n/', $csv);
                $header = array_map('trim', str_getcsv(array_shift($lines) ?? ''));
                $codeIdx = array_search('code', array_map('strtolower', $header)) !== false ? array_search('code', array_map('strtolower', $header)) : 0;
                $nameIdx = array_search('name', array_map('strtolower', $header)) !== false ? array_search('name', array_map('strtolower', $header)) : 1;
                $phoneIdx = array_search('phone', array_map('strtolower', $header)) !== false ? array_search('phone', array_map('strtolower', $header)) : null;
                $addressIdx = array_search('address', array_map('strtolower', $header)) !== false ? array_search('address', array_map('strtolower', $header)) : null;
                $termsIdx = array_search('payment_terms', array_map('strtolower', $header)) !== false ? array_search('payment_terms', array_map('strtolower', $header)) : null;
                $created = 0;
                $skipped = 0;
                $errors = [];
                $hasPhone = (bool) @$pdo->query("SHOW COLUMNS FROM customers LIKE 'phone'")->rowCount();
                $hasAddress = (bool) @$pdo->query("SHOW COLUMNS FROM customers LIKE 'address'")->rowCount();
                $hasPaymentLinks = (bool) @$pdo->query("SHOW COLUMNS FROM customers LIKE 'payment_links'")->rowCount();
                foreach ($lines as $i => $line) {
                    $row = str_getcsv($line);
                    if (count($row) < 2) continue;
                    $code = trim($row[$codeIdx] ?? $row[0] ?? '');
                    $name = trim($row[$nameIdx] ?? $row[1] ?? '');
                    if (!$code || !$name) {
                        $skipped++;
                        continue;
                    }
                    $phone = $phoneIdx !== null && isset($row[$phoneIdx]) ? trim($row[$phoneIdx]) : null;
                    $address = $addressIdx !== null && isset($row[$addressIdx]) ? trim($row[$addressIdx]) : null;
                    $paymentTerms = $termsIdx !== null && isset($row[$termsIdx]) ? trim($row[$termsIdx]) : null;
                    try {
                        $cols = ['code', 'name', 'contacts', 'addresses', 'payment_terms'];
                        $vals = [$code, $name, null, null, $paymentTerms];
                        if ($hasPaymentLinks) {
                            $cols[] = 'payment_links';
                            $vals[] = null;
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
                        $pdo->prepare("INSERT INTO customers (" . implode(',', $cols) . ") VALUES ($ph)")->execute($vals);
                        $created++;
                    } catch (PDOException $e) {
                        if ($e->getCode() == 23000) $skipped++;
                        else $errors[] = "Row " . ($i + 2) . ": " . $e->getMessage();
                    }
                }
                jsonResponse(['data' => ['created' => $created, 'skipped' => $skipped, 'errors' => $errors]]);
            }
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
            [$priorityLevel, $priorityNote] = normalizeCustomerPriority($input);
            $defaultShippingCode = isset($input['default_shipping_code']) ? (trim((string) $input['default_shipping_code']) ?: null) : null;
            $hasPhone = false;
            $hasAddress = false;
            $hasPriority = false;
            $hasPriorityNote = false;
            $hasDefaultShippingCode = false;
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM customers WHERE Field IN ('phone','address','priority_level','priority_note','default_shipping_code')");
                $colsExist = $chk ? array_column($chk->fetchAll(PDO::FETCH_ASSOC), 'Field') : [];
                $hasPhone = in_array('phone', $colsExist, true);
                $hasAddress = in_array('address', $colsExist, true);
                $hasPriority = in_array('priority_level', $colsExist, true);
                $hasPriorityNote = in_array('priority_note', $colsExist, true);
                $hasDefaultShippingCode = in_array('default_shipping_code', $colsExist, true);
            } catch (Throwable $e) {
            }
            $duplicateWarning = null;
            if ($hasDefaultShippingCode) {
                $duplicate = findDuplicateCustomerShippingCode($pdo, $defaultShippingCode);
                if ($duplicate) {
                    $duplicateWarning = 'Default shipping code already belongs to customer ' . ($duplicate['code'] ?: ('#' . $duplicate['id'])) . ' (' . $duplicate['name'] . ')';
                    $duplicateAction = getBusinessSetting($pdo, 'SHIPPING_CODE_DUPLICATE_ACTION', 'warn');
                    if ($duplicateAction === 'block') {
                        jsonError($duplicateWarning, 409);
                    }
                }
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
            if ($hasPriority) {
                $cols[] = 'priority_level';
                $vals[] = $priorityLevel;
            }
            if ($hasPriorityNote) {
                $cols[] = 'priority_note';
                $vals[] = $priorityNote;
            }
            if ($hasDefaultShippingCode) {
                $cols[] = 'default_shipping_code';
                $vals[] = $defaultShippingCode;
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
                jsonResponse(array_filter(['data' => $row, 'warning' => $duplicateWarning]), 201);
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
            [$priorityLevel, $priorityNote] = normalizeCustomerPriority($input);
            $defaultShippingCode = array_key_exists('default_shipping_code', $input) ? (trim((string) $input['default_shipping_code']) ?: null) : null;
            $hasPhone = false;
            $hasAddress = false;
            $hasPriority = false;
            $hasPriorityNote = false;
            $hasDefaultShippingCode = false;
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM customers WHERE Field IN ('phone','address','priority_level','priority_note','default_shipping_code')");
                while ($r = $chk->fetch(PDO::FETCH_ASSOC)) {
                    if ($r['Field'] === 'phone') $hasPhone = true;
                    if ($r['Field'] === 'address') $hasAddress = true;
                    if ($r['Field'] === 'priority_level') $hasPriority = true;
                    if ($r['Field'] === 'priority_note') $hasPriorityNote = true;
                    if ($r['Field'] === 'default_shipping_code') $hasDefaultShippingCode = true;
                }
            } catch (Throwable $e) {
            }
            $duplicateWarning = null;
            if ($hasDefaultShippingCode) {
                $duplicate = findDuplicateCustomerShippingCode($pdo, $defaultShippingCode, (int) $id);
                if ($duplicate) {
                    $duplicateWarning = 'Default shipping code already belongs to customer ' . ($duplicate['code'] ?: ('#' . $duplicate['id'])) . ' (' . $duplicate['name'] . ')';
                    $duplicateAction = getBusinessSetting($pdo, 'SHIPPING_CODE_DUPLICATE_ACTION', 'warn');
                    if ($duplicateAction === 'block') {
                        jsonError($duplicateWarning, 409);
                    }
                }
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
            if ($hasPriority) {
                $sets[] = 'priority_level=?';
                $vals[] = $priorityLevel;
            }
            if ($hasPriorityNote) {
                $sets[] = 'priority_note=?';
                $vals[] = $priorityNote;
            }
            if ($hasDefaultShippingCode) {
                $sets[] = 'default_shipping_code=?';
                $vals[] = $defaultShippingCode;
            }
            $vals[] = $id;
            $pdo->prepare("UPDATE customers SET " . implode(', ', $sets) . " WHERE id=?")->execute($vals);
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $row['contacts'] = $row['contacts'] ? json_decode($row['contacts'], true) : [];
            $row['addresses'] = $row['addresses'] ? json_decode($row['addresses'], true) : [];
            $row['payment_links'] = isset($row['payment_links']) && $row['payment_links'] ? json_decode($row['payment_links'], true) : [];
            jsonResponse(array_filter(['data' => $row, 'warning' => $duplicateWarning]));

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
