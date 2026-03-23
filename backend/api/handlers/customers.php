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
    if ($row) return $row;

    // Also check customer_country_shipping
    try {
        $stmt2 = $pdo->prepare("SELECT c.id, c.code, c.name FROM customer_country_shipping ccs JOIN customers c ON c.id = ccs.customer_id WHERE ccs.shipping_code = ? AND c.id != ? LIMIT 1");
        $stmt2->execute([$shippingCode, $excludeCustomerId]);
        $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);
        return $row2 ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function loadCountryShipping(PDO $pdo, int $customerId): array
{
    try {
        $stmt = $pdo->prepare("SELECT ccs.id, ccs.country_id, ccs.shipping_code, co.code as country_code, co.name as country_name FROM customer_country_shipping ccs JOIN countries co ON co.id = ccs.country_id WHERE ccs.customer_id = ? ORDER BY co.name");
        $stmt->execute([$customerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function generateCustomerCode(PDO $pdo, string $name, ?string $defaultShippingCode, array $countryShipping): string
{
    $candidates = [];
    if ($defaultShippingCode && trim($defaultShippingCode) !== '') {
        $candidates[] = trim($defaultShippingCode);
    }
    foreach ($countryShipping as $cs) {
        $sc = trim($cs['shipping_code'] ?? '');
        if ($sc !== '' && !in_array($sc, $candidates, true)) {
            $candidates[] = $sc;
        }
    }
    if (!empty($candidates)) {
        return $candidates[0];
    }
    $slug = preg_replace('/[^a-zA-Z0-9]+/', '_', substr(trim($name), 0, 30));
    $slug = $slug ?: 'cust';
    $base = $slug . '_' . bin2hex(random_bytes(2));
    $attempt = 0;
    while ($attempt < 10) {
        $stmt = $pdo->prepare("SELECT 1 FROM customers WHERE code = ? LIMIT 1");
        $stmt->execute([$base]);
        if (!$stmt->fetch()) return $base;
        $base = $slug . '_' . bin2hex(random_bytes(2));
        $attempt++;
    }
    return $slug . '_' . time();
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
                $hasEmail = false;
                try {
                    $chk = $pdo->query("SHOW COLUMNS FROM customers WHERE Field IN ('phone','email')");
                    if ($chk) {
                        while ($r = $chk->fetch(PDO::FETCH_ASSOC)) {
                            if ($r['Field'] === 'phone') $hasPhone = true;
                            if ($r['Field'] === 'email') $hasEmail = true;
                        }
                    }
                } catch (Throwable $e) {
                }
                if ($hasPhone) $cols[] = 'phone';
                if ($hasEmail) $cols[] = 'email';
                $sel = implode(', ', $cols);
                $coll = 'COLLATE utf8mb4_unicode_ci';
                $where = "(name $coll LIKE ?) OR (code $coll LIKE ?) OR (default_shipping_code $coll LIKE ?)";
                $params = [$like, $like, $like];
                if ($hasPhone) { $where .= " OR (phone $coll LIKE ?)"; $params[] = $like; }
                if ($hasEmail) { $where .= " OR (email $coll LIKE ?)"; $params[] = $like; }
                $stmt = $pdo->prepare("SELECT $sel FROM customers WHERE $where ORDER BY name LIMIT 20");
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
                    $hasEmail = false;
                    $hasDefaultShippingCode = false;
                    try {
                        $colChk = $pdo->query("SHOW COLUMNS FROM customers WHERE Field IN ('phone','email','default_shipping_code')");
                        if ($colChk) {
                            while ($r = $colChk->fetch(PDO::FETCH_ASSOC)) {
                                if ($r['Field'] === 'phone') $hasPhone = true;
                                if ($r['Field'] === 'email') $hasEmail = true;
                                if ($r['Field'] === 'default_shipping_code') $hasDefaultShippingCode = true;
                            }
                        }
                    } catch (Throwable $e) {
                    }
                    $coll = 'COLLATE utf8mb4_unicode_ci';
                    $sql .= " WHERE (name $coll LIKE ?) OR (code $coll LIKE ?)";
                    $params = [$like, $like];
                    if ($hasPhone) { $sql .= " OR (phone $coll LIKE ?)"; $params[] = $like; }
                    if ($hasEmail) { $sql .= " OR (email $coll LIKE ?)"; $params[] = $like; }
                    if ($hasDefaultShippingCode) { $sql .= " OR (default_shipping_code $coll LIKE ?)"; $params[] = $like; }
                }
                $sql .= " ORDER BY name";
                $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
                if ($params) $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as &$r) {
                    $r['contacts'] = $r['contacts'] ? json_decode($r['contacts'], true) : [];
                    $r['addresses'] = $r['addresses'] ? json_decode($r['addresses'], true) : [];
                    $r['payment_links'] = isset($r['payment_links']) && $r['payment_links'] ? json_decode($r['payment_links'], true) : [];
                    $r['country_shipping'] = loadCountryShipping($pdo, (int) $r['id']);
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
            $row['country_shipping'] = loadCountryShipping($pdo, (int) $id);
            if ($action === 'next-item-no') {
                $shippingCode = trim($_GET['shipping_code'] ?? '');
                if ($shippingCode === '') {
                    jsonError('shipping_code required', 400);
                }
                $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE o.customer_id = ? AND COALESCE(TRIM(oi.shipping_code), '') = ?");
                $stmt->execute([$id, $shippingCode]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $count = (int) ($row['cnt'] ?? 0);
                jsonResponse(['data' => ['next' => $count + 1]]);
            }
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
                $hLower = array_map('strtolower', $header);
                $codeIdx = array_search('code', $hLower) !== false ? array_search('code', $hLower) : null;
                $shipIdx = array_search('default_shipping_code', $hLower) !== false ? array_search('default_shipping_code', $hLower) : null;
                $nameIdx = array_search('name', $hLower) !== false ? array_search('name', $hLower) : 1;
                $phoneIdx = array_search('phone', $hLower) !== false ? array_search('phone', $hLower) : null;
                $emailIdx = array_search('email', $hLower) !== false ? array_search('email', $hLower) : null;
                $addressIdx = array_search('address', $hLower) !== false ? array_search('address', $hLower) : null;
                $termsIdx = array_search('payment_terms', $hLower) !== false ? array_search('payment_terms', $hLower) : null;
                $created = 0;
                $skipped = 0;
                $errors = [];
                $hasPhone = (bool) @$pdo->query("SHOW COLUMNS FROM customers LIKE 'phone'")->rowCount();
                $hasAddress = (bool) @$pdo->query("SHOW COLUMNS FROM customers LIKE 'address'")->rowCount();
                $hasPaymentLinks = (bool) @$pdo->query("SHOW COLUMNS FROM customers LIKE 'payment_links'")->rowCount();
                $hasEmail = (bool) @$pdo->query("SHOW COLUMNS FROM customers LIKE 'email'")->rowCount();
                $hasDefaultShippingCode = (bool) @$pdo->query("SHOW COLUMNS FROM customers LIKE 'default_shipping_code'")->rowCount();
                foreach ($lines as $i => $line) {
                    $row = str_getcsv($line);
                    if (count($row) < 2) continue;
                    $code = $codeIdx !== null ? trim($row[$codeIdx] ?? '') : '';
                    $defaultShippingCode = $shipIdx !== null ? trim($row[$shipIdx] ?? '') : '';
                    $name = trim($row[$nameIdx] ?? $row[1] ?? '');
                    if (!$name) {
                        $skipped++;
                        continue;
                    }
                    $code = $code ?: ($defaultShippingCode ?: null);
                    if (!$code) {
                        $code = preg_replace('/[^a-zA-Z0-9]+/', '_', substr($name, 0, 30)) ?: 'cust';
                        $code = $code . '_' . bin2hex(random_bytes(2));
                    }
                    $phone = $phoneIdx !== null && isset($row[$phoneIdx]) ? trim($row[$phoneIdx]) : null;
                    $email = $hasEmail && $emailIdx !== null && isset($row[$emailIdx]) ? trim($row[$emailIdx]) : null;
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
                        if ($hasDefaultShippingCode) {
                            $cols[] = 'default_shipping_code';
                            $vals[] = $defaultShippingCode ?: null;
                        }
                        if ($hasEmail) {
                            $cols[] = 'email';
                            $vals[] = $email;
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
                $orderId = !empty($input['order_id']) ? (int) $input['order_id'] : null;
                $userId = getAuthUserId() ?? 1;
                $chkOrder = @$pdo->query("SHOW COLUMNS FROM customer_deposits LIKE 'order_id'");
                $hasOrderId = $chkOrder && $chkOrder->rowCount() > 0;
                if ($hasOrderId) {
                    $pdo->prepare("INSERT INTO customer_deposits (customer_id, order_id, amount, currency, payment_method, reference_no, notes, created_by) VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([$id, $orderId, $amount, $currency, $paymentMethod, $referenceNo, $notes, $userId]);
                } else {
                    $pdo->prepare("INSERT INTO customer_deposits (customer_id, amount, currency, payment_method, reference_no, notes, created_by) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$id, $amount, $currency, $paymentMethod, $referenceNo, $notes, $userId]);
                }
                $newId = (int) $pdo->lastInsertId();
                logClms('customer_deposit', ['customer_id' => (int)$id, 'deposit_id' => $newId, 'amount' => $amount, 'currency' => $currency]);
                $stmt = $pdo->prepare("SELECT * FROM customer_deposits WHERE id = ?");
                $stmt->execute([$newId]);
                jsonResponse(['data' => $stmt->fetch(PDO::FETCH_ASSOC)], 201);
            }
            $name = trim($input['name'] ?? '');
            if (!$name) {
                jsonError('Missing required fields', 400, ['name' => 'Required']);
            }
            $countryShipping = is_array($input['country_shipping'] ?? null) ? $input['country_shipping'] : [];
            $defaultShippingCode = isset($input['default_shipping_code']) ? (trim((string) $input['default_shipping_code']) ?: null) : null;
            $code = trim($input['code'] ?? '');
            if ($code === '') {
                $code = generateCustomerCode($pdo, $name, $defaultShippingCode, $countryShipping);
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
            $email = !empty($input['email']) ? trim($input['email']) : null;
            [$priorityLevel, $priorityNote] = normalizeCustomerPriority($input);
            $hasPhone = false;
            $hasAddress = false;
            $hasPriority = false;
            $hasPriorityNote = false;
            $hasDefaultShippingCode = false;
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM customers WHERE Field IN ('phone','address','priority_level','priority_note','default_shipping_code','email')");
                $colsExist = $chk ? array_column($chk->fetchAll(PDO::FETCH_ASSOC), 'Field') : [];
                $hasPhone = in_array('phone', $colsExist, true);
                $hasAddress = in_array('address', $colsExist, true);
                $hasPriority = in_array('priority_level', $colsExist, true);
                $hasPriorityNote = in_array('priority_note', $colsExist, true);
                $hasDefaultShippingCode = in_array('default_shipping_code', $colsExist, true);
                $hasEmail = in_array('email', $colsExist, true);
            } catch (Throwable $e) {
            }
            $duplicateWarning = null;
            $allShippingCodes = array_filter(array_merge(
                $defaultShippingCode ? [$defaultShippingCode] : [],
                array_map(function ($cs) { return trim($cs['shipping_code'] ?? ''); }, $countryShipping)
            ));
            foreach ($allShippingCodes as $sc) {
                if ($sc === '') continue;
                $duplicate = findDuplicateCustomerShippingCode($pdo, $sc);
                if ($duplicate) {
                    $duplicateWarning = 'Shipping code "' . $sc . '" already belongs to customer ' . ($duplicate['code'] ?: ('#' . $duplicate['id'])) . ' (' . $duplicate['name'] . ')';
                    $duplicateAction = getBusinessSetting($pdo, 'SHIPPING_CODE_DUPLICATE_ACTION', 'warn');
                    if ($duplicateAction === 'block') {
                        jsonError($duplicateWarning, 409);
                    }
                    break;
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
            $stmt = $pdo->prepare("SELECT id, code FROM customers WHERE id = ?");
            $stmt->execute([$id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                jsonError('Customer not found', 404);
            }
            $code = trim($input['code'] ?? '');
            if ($code === '') {
                $code = $existing['code'];
            }
            $name = trim($input['name'] ?? '');
            if (!$name) {
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
            $email = array_key_exists('email', $input) ? (trim((string) ($input['email'] ?? '')) ?: null) : null;
            [$priorityLevel, $priorityNote] = normalizeCustomerPriority($input);
            $defaultShippingCode = array_key_exists('default_shipping_code', $input) ? (trim((string) $input['default_shipping_code']) ?: null) : null;
            $countryShipping = is_array($input['country_shipping'] ?? null) ? $input['country_shipping'] : null;
            $hasPhone = false;
            $hasAddress = false;
            $hasPriority = false;
            $hasPriorityNote = false;
            $hasDefaultShippingCode = false;
            $hasEmail = false;
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM customers WHERE Field IN ('phone','address','priority_level','priority_note','default_shipping_code','email')");
                while ($r = $chk->fetch(PDO::FETCH_ASSOC)) {
                    if ($r['Field'] === 'phone') $hasPhone = true;
                    if ($r['Field'] === 'address') $hasAddress = true;
                    if ($r['Field'] === 'priority_level') $hasPriority = true;
                    if ($r['Field'] === 'priority_note') $hasPriorityNote = true;
                    if ($r['Field'] === 'default_shipping_code') $hasDefaultShippingCode = true;
                    if ($r['Field'] === 'email') $hasEmail = true;
                }
            } catch (Throwable $e) {
            }
            $duplicateWarning = null;
            $allShippingCodes = array_filter(array_merge(
                $defaultShippingCode ? [$defaultShippingCode] : [],
                is_array($countryShipping) ? array_map(function ($cs) { return trim($cs['shipping_code'] ?? ''); }, $countryShipping) : []
            ));
            foreach ($allShippingCodes as $sc) {
                if ($sc === '') continue;
                $duplicate = findDuplicateCustomerShippingCode($pdo, $sc, (int) $id);
                if ($duplicate) {
                    $duplicateWarning = 'Shipping code "' . $sc . '" already belongs to customer ' . ($duplicate['code'] ?: ('#' . $duplicate['id'])) . ' (' . $duplicate['name'] . ')';
                    $duplicateAction = getBusinessSetting($pdo, 'SHIPPING_CODE_DUPLICATE_ACTION', 'warn');
                    if ($duplicateAction === 'block') {
                        jsonError($duplicateWarning, 409);
                    }
                    break;
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
            if ($hasEmail) {
                $sets[] = 'email=?';
                $vals[] = $email;
            }
            $vals[] = $id;
            $pdo->prepare("UPDATE customers SET " . implode(', ', $sets) . " WHERE id=?")->execute($vals);
            if (is_array($countryShipping)) {
                $pdo->prepare("DELETE FROM customer_country_shipping WHERE customer_id = ?")->execute([$id]);
                if (!empty($countryShipping)) {
                    $ins = $pdo->prepare("INSERT INTO customer_country_shipping (customer_id, country_id, shipping_code) VALUES (?, ?, ?)");
                    foreach ($countryShipping as $cs) {
                        $cid = (int) ($cs['country_id'] ?? 0);
                        $sc = trim($cs['shipping_code'] ?? '') ?: null;
                        if ($cid > 0) {
                            $ins->execute([$id, $cid, $sc]);
                        }
                    }
                }
            }
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $row['contacts'] = $row['contacts'] ? json_decode($row['contacts'], true) : [];
            $row['addresses'] = $row['addresses'] ? json_decode($row['addresses'], true) : [];
            $row['payment_links'] = isset($row['payment_links']) && $row['payment_links'] ? json_decode($row['payment_links'], true) : [];
            $row['country_shipping'] = loadCountryShipping($pdo, (int) $id);
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
