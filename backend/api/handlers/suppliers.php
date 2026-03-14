<?php

/**
 * Suppliers API - GET list, GET one, POST create, PUT update, DELETE
 */

require_once __DIR__ . '/../helpers.php';

function calcSupplierScore(PDO $pdo, int $supplierId): ?float
{
    $orders = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status IN ('ReceivedAtWarehouse','AwaitingCustomerConfirmation','Confirmed','ReadyForConsolidation','ConsolidatedIntoShipmentDraft','AssignedToContainer','FinalizedAndPushedToTracking') THEN 1 ELSE 0 END) as completed FROM orders WHERE supplier_id = ?");
    $orders->execute([$supplierId]);
    $ord = $orders->fetch(PDO::FETCH_ASSOC);
    $totalOrders = (int) $ord['total'];
    if ($totalOrders < 1) return null;
    $completed = (int) $ord['completed'];

    $variance = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE supplier_id = ? AND status = 'AwaitingCustomerConfirmation'");
    $variance->execute([$supplierId]);
    $varianceCount = (int) $variance->fetchColumn();

    $visits = $pdo->prepare("SELECT COUNT(*) FROM supplier_interactions WHERE supplier_id = ?");
    $visits->execute([$supplierId]);
    $visitCount = (int) $visits->fetchColumn();

    $payFull = $pdo->prepare("SELECT COUNT(*) FROM supplier_payments WHERE supplier_id = ? AND marked_full_payment = 1");
    $payFull->execute([$supplierId]);
    $fullPaid = (int) $payFull->fetchColumn();

    // Score 0–5: weighted combination
    $completionRate = $totalOrders > 0 ? $completed / $totalOrders : 0;
    $varianceRate = $totalOrders > 0 ? $varianceCount / $totalOrders : 0;
    $score = ($completionRate * 2.5) + ((1 - $varianceRate) * 1.5) + (min($visitCount, 5) / 5 * 0.5) + (min($fullPaid, 3) / 3 * 0.5);
    return round(min(5, max(0, $score)), 1);
}

function validatePhone(?string $phone): void
{
    if ($phone === null || $phone === '') return;
    if (!preg_match('/^[\d\s\+\-\(\)\.]{6,50}$/', $phone)) {
        jsonError('Invalid phone format (use digits, +, -, parentheses, spaces)', 400);
    }
}

function normalizeSupplierCommission(array $input): array
{
    $type = in_array($input['commission_type'] ?? '', ['percentage', 'fixed'], true)
        ? $input['commission_type']
        : 'percentage';
    $appliedOn = in_array($input['commission_applied_on'] ?? '', ['buy_value', 'sell_value'], true)
        ? $input['commission_applied_on']
        : 'buy_value';
    $rateRaw = $input['commission_rate'] ?? null;

    if ($rateRaw === null || $rateRaw === '') {
        return [null, $type, $appliedOn];
    }

    $rate = (float) $rateRaw;
    if ($rate < 0) {
        jsonError('Commission must be zero or positive', 400);
    }
    if ($type === 'percentage' && $rate > 100) {
        jsonError('Percentage commission cannot exceed 100', 400);
    }

    return [round($rate, 4), $type, $appliedOn];
}

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();
    $readRoles = ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'FieldStaff', 'SuperAdmin'];
    $buyerRoles = ['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin'];
    $financeRoles = ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'SuperAdmin'];
    $interactionRoles = ['ChinaAdmin', 'ChinaEmployee', 'FieldStaff', 'SuperAdmin'];
    $canViewFinancials = hasAnyRole($financeRoles);

    if ($method === 'GET') {
        requireRole($readRoles);
    } elseif ($method === 'PUT' || $method === 'DELETE') {
        requireRole($buyerRoles);
    } elseif ($method === 'POST') {
        if ($id === 'import') {
            requireRole($buyerRoles);
        } elseif ($id && $action === 'interactions') {
            requireRole($interactionRoles);
        } elseif ($id && in_array($action, ['payments', 'balance'], true)) {
            requireRole($financeRoles);
        } else {
            requireRole($buyerRoles);
        }
    }

    switch ($method) {
        case 'GET':
            if ($id === 'search') {
                $q = trim($_GET['q'] ?? '');
                if (strlen($q) < 1) {
                    jsonResponse(['data' => []]);
                }
                $like = '%' . preg_replace('/\s+/', '%', $q) . '%';
                $chkAddr = @$pdo->query("SHOW COLUMNS FROM suppliers LIKE 'address'");
                $chkFactory = @$pdo->query("SHOW COLUMNS FROM suppliers LIKE 'factory_location'");
                $extra = '';
                $extraParams = [];
                if ($chkAddr && $chkAddr->rowCount() > 0) {
                    $extra .= ' OR (address IS NOT NULL AND address LIKE ?)';
                    $extraParams[] = $like;
                }
                if ($chkFactory && $chkFactory->rowCount() > 0) {
                    $extra .= ' OR (factory_location IS NOT NULL AND factory_location LIKE ?)';
                    $extraParams[] = $like;
                }
                $stmt = $pdo->prepare("SELECT id, code, name, phone, store_id FROM suppliers WHERE name LIKE ? OR code LIKE ? OR (phone IS NOT NULL AND phone LIKE ?) OR (store_id IS NOT NULL AND store_id LIKE ?)$extra ORDER BY name LIMIT 15");
                $stmt->execute(array_merge([$like, $like, $like, $like], $extraParams));
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                jsonResponse(['data' => $rows]);
            }
            if ($id === null) {
                $q = trim($_GET['q'] ?? '');
                $paymentStatus = trim($_GET['payment_status'] ?? '');
                $sort = in_array($_GET['sort'] ?? '', ['name', 'code', 'store_id', 'phone', 'factory_location']) ? $_GET['sort'] : 'name';
                $order = strtolower($_GET['order'] ?? '') === 'desc' ? 'DESC' : 'ASC';

                $where = [];
                $params = [];
                if (strlen($q) >= 1) {
                    $like = '%' . preg_replace('/\s+/', '%', $q) . '%';
                    $where[] = "(s.name LIKE ? OR s.code LIKE ? OR (s.phone IS NOT NULL AND s.phone LIKE ?) OR (s.store_id IS NOT NULL AND s.store_id LIKE ?) OR (s.factory_location IS NOT NULL AND s.factory_location LIKE ?))";
                    $params = array_merge($params, [$like, $like, $like, $like, $like]);
                }
                if ($paymentStatus === 'outstanding') {
                    $where[] = "EXISTS (SELECT 1 FROM (SELECT currency, SUM(COALESCE(invoice_amount, amount)) as inv, SUM(amount) as paid FROM supplier_payments WHERE supplier_id = s.id GROUP BY currency) x WHERE (inv - paid) > 0)";
                } elseif ($paymentStatus === 'fully_paid') {
                    $where[] = "NOT EXISTS (SELECT 1 FROM (SELECT currency, SUM(COALESCE(invoice_amount, amount)) as inv, SUM(amount) as paid FROM supplier_payments WHERE supplier_id = s.id GROUP BY currency) x WHERE (inv - paid) > 0)";
                }
                $sql = "SELECT s.* FROM suppliers s";
                if (!empty($where)) {
                    $sql .= " WHERE " . implode(" AND ", $where);
                }
                $sql .= " ORDER BY s." . $sort . " " . $order;

                $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
                if ($params) $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($rows as &$r) {
                    $r['contacts'] = $r['contacts'] ? json_decode($r['contacts'], true) : [];
                    $r['additional_ids'] = $r['additional_ids'] ? json_decode($r['additional_ids'], true) : [];
                    $r['reliability_score'] = calcSupplierScore($pdo, (int) $r['id']);
                    if (!$canViewFinancials) {
                        unset($r['commission_rate'], $r['commission_type'], $r['commission_applied_on']);
                    }
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
            $row['reliability_score'] = calcSupplierScore($pdo, (int) $id);

            if ($action === 'balance') {
                requireRole($financeRoles);
                $stmt = $pdo->prepare("SELECT currency, SUM(amount) as total_paid, SUM(COALESCE(invoice_amount,amount)) as total_invoiced, SUM(COALESCE(discount_amount,0)) as total_discount FROM supplier_payments WHERE supplier_id = ? GROUP BY currency");
                $stmt->execute([$id]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $balance = [];
                foreach ($rows as $r) {
                    $balance[$r['currency']] = [
                        'total_paid' => (float) $r['total_paid'],
                        'total_invoiced' => (float) $r['total_invoiced'],
                        'total_discount' => (float) $r['total_discount'],
                        'outstanding' => round((float) $r['total_invoiced'] - (float) $r['total_paid'], 4),
                    ];
                }
                jsonResponse(['data' => $balance]);
            }

            if ($canViewFinancials) {
                $payments = $pdo->prepare("SELECT * FROM supplier_payments WHERE supplier_id = ? ORDER BY created_at DESC");
                $payments->execute([$id]);
                $row['payments'] = $payments->fetchAll(PDO::FETCH_ASSOC);
            }
            $interactions = $pdo->prepare("SELECT si.*, u.full_name as created_by_name FROM supplier_interactions si LEFT JOIN users u ON si.created_by = u.id WHERE si.supplier_id = ? ORDER BY si.created_at DESC");
            $interactions->execute([$id]);
            $intRows = $interactions->fetchAll(PDO::FETCH_ASSOC);
            foreach ($intRows as &$r) {
                $r['content'] = $r['content'] ? json_decode($r['content'], true) : null;
            }
            $row['interactions'] = $intRows;
            if (!$canViewFinancials) {
                unset($row['commission_rate'], $row['commission_type'], $row['commission_applied_on']);
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
                $storeIdx = array_search('store_id', array_map('strtolower', $header)) !== false ? array_search('store_id', array_map('strtolower', $header)) : null;
                $phoneIdx = array_search('phone', array_map('strtolower', $header)) !== false ? array_search('phone', array_map('strtolower', $header)) : null;
                $factoryIdx = array_search('factory_location', array_map('strtolower', $header)) !== false ? array_search('factory_location', array_map('strtolower', $header)) : null;
                $notesIdx = array_search('notes', array_map('strtolower', $header)) !== false ? array_search('notes', array_map('strtolower', $header)) : null;
                $created = 0;
                $skipped = 0;
                $errors = [];
                foreach ($lines as $i => $line) {
                    $row = str_getcsv($line);
                    if (count($row) < 2) continue;
                    $code = trim($row[$codeIdx] ?? $row[0] ?? '');
                    $name = trim($row[$nameIdx] ?? $row[1] ?? '');
                    if (!$code || !$name) {
                        $skipped++;
                        continue;
                    }
                    $storeId = $storeIdx !== null && isset($row[$storeIdx]) ? trim($row[$storeIdx]) : null;
                    $phone = $phoneIdx !== null && isset($row[$phoneIdx]) ? trim($row[$phoneIdx]) : null;
                    $factory = $factoryIdx !== null && isset($row[$factoryIdx]) ? trim($row[$factoryIdx]) : null;
                    $notes = $notesIdx !== null && isset($row[$notesIdx]) ? trim($row[$notesIdx]) : null;
                    try {
                        $pdo->prepare("INSERT INTO suppliers (code, store_id, name, phone, factory_location, notes) VALUES (?,?,?,?,?,?)")
                            ->execute([$code, $storeId ?: null, $name, $phone ?: null, $factory ?: null, $notes ?: null]);
                        $created++;
                    } catch (PDOException $e) {
                        if ($e->getCode() == 23000) $skipped++;
                        else $errors[] = "Row " . ($i + 2) . ": " . $e->getMessage();
                    }
                }
                jsonResponse(['data' => ['created' => $created, 'skipped' => $skipped, 'errors' => $errors]]);
            }
            if ($id && $action === 'payments') {
                $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE id = ?");
                $stmt->execute([$id]);
                if (!$stmt->fetch()) jsonError('Supplier not found', 404);
                $amount = (float) ($input['amount'] ?? 0);
                $currency = trim($input['currency'] ?? 'USD');
                if (!in_array($currency, ['USD', 'RMB'], true)) jsonError('Currency must be USD or RMB', 400);
                $paymentType = in_array($input['payment_type'] ?? '', ['partial', 'full']) ? $input['payment_type'] : 'partial';
                $notes = $input['notes'] ?? null;
                $orderId = !empty($input['order_id']) ? (int) $input['order_id'] : null;
                if ($amount <= 0) jsonError('Amount must be positive', 400);
                $invoiceAmount = isset($input['invoice_amount']) ? (float) $input['invoice_amount'] : null;
                $markedFull = !empty($input['marked_full_payment']) ? 1 : 0;
                $discountAmount = ($invoiceAmount !== null && $invoiceAmount > $amount) ? round($invoiceAmount - $amount, 4) : 0;
                $userId = getAuthUserId() ?? 1;
                $markedBy = $markedFull ? $userId : null;
                $pdo->prepare("INSERT INTO supplier_payments (supplier_id, order_id, amount, invoice_amount, discount_amount, marked_full_payment, marked_by, currency, payment_type, notes) VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$id, $orderId, $amount, $invoiceAmount, $discountAmount, $markedFull, $markedBy, $currency, $paymentType, $notes]);
                $newId = (int) $pdo->lastInsertId();
                logClms('supplier_payment', ['supplier_id' => (int)$id, 'payment_id' => $newId, 'amount' => $amount, 'invoice_amount' => $invoiceAmount, 'discount' => $discountAmount, 'marked_full' => $markedFull]);
                $row = $pdo->prepare("SELECT * FROM supplier_payments WHERE id = ?");
                $row->execute([$newId]);
                jsonResponse(['data' => $row->fetch(PDO::FETCH_ASSOC)], 201);
            }
            if ($id && $action === 'balance') {
                $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE id = ?");
                $stmt->execute([$id]);
                if (!$stmt->fetch()) jsonError('Supplier not found', 404);
                $stmt = $pdo->prepare("SELECT currency, SUM(amount) as total_paid, SUM(COALESCE(invoice_amount,amount)) as total_invoiced, SUM(COALESCE(discount_amount,0)) as total_discount FROM supplier_payments WHERE supplier_id = ? GROUP BY currency");
                $stmt->execute([$id]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $balance = [];
                foreach ($rows as $r) {
                    $balance[$r['currency']] = [
                        'total_paid' => (float)$r['total_paid'],
                        'total_invoiced' => (float)$r['total_invoiced'],
                        'total_discount' => (float)$r['total_discount'],
                        'outstanding' => round((float)$r['total_invoiced'] - (float)$r['total_paid'], 4),
                    ];
                }
                jsonResponse(['data' => $balance]);
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
            $address = isset($input['address']) ? trim($input['address']) : null;
            $fax = isset($input['fax']) ? trim($input['fax']) : null;
            [$commissionRate, $commissionType, $commissionAppliedOn] = normalizeSupplierCommission($input);
            try {
                $chk = @$pdo->query("SHOW COLUMNS FROM suppliers LIKE 'address'");
                $hasAddr = $chk && $chk->rowCount() > 0;
                $chk = @$pdo->query("SHOW COLUMNS FROM suppliers LIKE 'fax'");
                $hasFax = $chk && $chk->rowCount() > 0;
                $chk = @$pdo->query("SHOW COLUMNS FROM suppliers LIKE 'commission_rate'");
                $hasCommission = $chk && $chk->rowCount() > 0;
                $cols = "code, name, store_id, contacts, factory_location, notes, phone, additional_ids";
                $vals = "?, ?, ?, ?, ?, ?, ?, ?";
                $params = [$code, $name, $storeId ?: null, $contacts, $factoryLocation, $notes, $phone ?: null, $additionalIds];
                if ($hasCommission) {
                    $cols .= ", commission_rate, commission_type, commission_applied_on";
                    $vals .= ", ?, ?, ?";
                    $params[] = $commissionRate;
                    $params[] = $commissionType;
                    $params[] = $commissionAppliedOn;
                }
                if ($hasAddr) {
                    $cols .= ", address";
                    $vals .= ", ?";
                    $params[] = $address ?: null;
                }
                if ($hasFax) {
                    $cols .= ", fax";
                    $vals .= ", ?";
                    $params[] = $fax ?: null;
                }
                $stmt = $pdo->prepare("INSERT INTO suppliers ($cols) VALUES ($vals)");
                $stmt->execute($params);
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
            $address = isset($input['address']) ? trim($input['address']) : null;
            $fax = isset($input['fax']) ? trim($input['fax']) : null;
            [$commissionRate, $commissionType, $commissionAppliedOn] = normalizeSupplierCommission($input);
            $chk = @$pdo->query("SHOW COLUMNS FROM suppliers LIKE 'address'");
            $hasAddr = $chk && $chk->rowCount() > 0;
            $chk = @$pdo->query("SHOW COLUMNS FROM suppliers LIKE 'fax'");
            $hasFax = $chk && $chk->rowCount() > 0;
            $chk = @$pdo->query("SHOW COLUMNS FROM suppliers LIKE 'commission_rate'");
            $hasCommission = $chk && $chk->rowCount() > 0;
            $sets = "code=?, name=?, store_id=?, contacts=?, factory_location=?, notes=?, phone=?, additional_ids=?";
            $params = [$code, $name, $storeId ?: null, $contacts, $factoryLocation, $notes, $phone ?: null, $additionalIds];
            if ($hasCommission) {
                $sets .= ", commission_rate=?, commission_type=?, commission_applied_on=?";
                $params[] = $commissionRate;
                $params[] = $commissionType;
                $params[] = $commissionAppliedOn;
            }
            if ($hasAddr) {
                $sets .= ", address=?";
                $params[] = $address ?: null;
            }
            if ($hasFax) {
                $sets .= ", fax=?";
                $params[] = $fax ?: null;
            }
            $params[] = $id;
            $pdo->prepare("UPDATE suppliers SET $sets WHERE id=?")->execute($params);
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
