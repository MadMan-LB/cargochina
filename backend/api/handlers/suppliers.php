<?php

/**
 * Suppliers API - GET list, GET one, POST create, PUT update, DELETE
 */

require_once __DIR__ . '/../helpers.php';

function supplierTableHasColumn(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];

    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        $cache[$key] = (bool) $stmt->rowCount();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function normalizeSupplierPaymentMethodName(?string $value): string
{
    $normalized = mb_strtolower(trim((string) $value), 'UTF-8');
    if ($normalized === '') {
        return '';
    }
    if (str_contains($normalized, 'wechat') || str_contains($normalized, 'weixin')) {
        return 'WeChat';
    }
    if (str_contains($normalized, 'alipay') || str_contains($normalized, 'ali pay') || $normalized === 'ali') {
        return 'Alipay';
    }
    if (
        str_contains($normalized, 'bank') ||
        str_contains($normalized, 'transfer') ||
        str_contains($normalized, 'wire') ||
        preg_match('/\btt\b/u', $normalized)
    ) {
        return 'Bank Transfer';
    }
    return '';
}

function normalizeSupplierPaymentLinks($value): ?string
{
    if (!is_array($value)) {
        return null;
    }

    $rows = [];
    foreach ($value as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rawMethod = trim((string) ($row['method'] ?? $row['type'] ?? $row['label'] ?? ''));
        $method = normalizeSupplierPaymentMethodName($rawMethod) ?: 'Bank Transfer';
        $accountLabel = trim((string) ($row['account_label'] ?? $row['label'] ?? ''));
        $content = trim((string) ($row['value'] ?? $row['link'] ?? $row['account_value'] ?? ''));
        $currency = strtoupper(trim((string) ($row['currency'] ?? 'RMB')));
        $qrImagePath = trim((string) ($row['qr_image_path'] ?? $row['qr'] ?? ''));
        if ($rawMethod === '' && $accountLabel === '' && $content === '' && $qrImagePath === '') {
            continue;
        }
        if (!in_array($currency, ['RMB', 'USD'], true)) {
            $currency = 'RMB';
        }
        if ($qrImagePath !== '' && str_contains($qrImagePath, '..')) {
            jsonError('Invalid supplier payment QR path', 400);
        }
        $rows[] = [
            'method' => $method,
            'account_label' => $accountLabel ?: $method,
            'label' => $accountLabel ?: $method,
            'value' => $content ?: null,
            'currency' => $currency,
            'qr_image_path' => $qrImagePath !== '' ? $qrImagePath : null,
        ];
    }

    return $rows ? json_encode($rows, JSON_UNESCAPED_UNICODE) : null;
}

function decodeSupplierPaymentLinks($value): array
{
    if (!$value) {
        return [];
    }

    $decoded = json_decode((string) $value, true);
    if (!is_array($decoded)) {
        return [];
    }
    $normalized = normalizeSupplierPaymentLinks($decoded);
    if (!$normalized) {
        return [];
    }
    $rows = json_decode($normalized, true);
    return is_array($rows) ? $rows : [];
}

function supplierSettlementDeltaExpr(PDO $pdo): string
{
    if (supplierTableHasColumn($pdo, 'supplier_payments', 'settlement_delta')) {
        return 'COALESCE(settlement_delta, COALESCE(discount_amount,0), 0)';
    }
    return 'COALESCE(discount_amount,0)';
}

function calcSupplierScore(PDO $pdo, int $supplierId): ?float
{
    $orders = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status IN ('ReceivedAtWarehouse','ReadyForConsolidation','ConsolidatedIntoShipmentDraft','AssignedToContainer','FinalizedAndPushedToTracking') OR (status = 'Confirmed' AND COALESCE(confirmation_token, '') = '') THEN 1 ELSE 0 END) as completed FROM orders WHERE supplier_id = ?");
    $orders->execute([$supplierId]);
    $ord = $orders->fetch(PDO::FETCH_ASSOC);
    $totalOrders = (int) $ord['total'];
    if ($totalOrders < 1) return null;
    $completed = (int) $ord['completed'];

    $variance = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE supplier_id = ? AND COALESCE(confirmation_token, '') <> ''");
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

function normalizeSupplierDuplicateName(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';
    return trim($value);
}

function normalizeSupplierDuplicatePhone(?string $value): string
{
    return preg_replace('/\D+/', '', (string) $value) ?? '';
}

function supplierNameSimilarity(string $left, string $right): float
{
    if ($left === '' || $right === '') {
        return 0.0;
    }
    similar_text($left, $right, $percent);
    return round(((float) $percent) / 100, 4);
}

function findLikelySupplierDuplicates(PDO $pdo, string $name, ?string $storeId, ?string $phone, ?int $excludeId = null): array
{
    $storeId = trim((string) $storeId);
    $nameNorm = normalizeSupplierDuplicateName($name);
    $phoneNorm = normalizeSupplierDuplicatePhone($phone);
    if ($storeId === '' && ($nameNorm === '' || $phoneNorm === '')) {
        return [];
    }

    $where = [];
    $params = [];
    if ($excludeId) {
        $where[] = 'id != ?';
        $params[] = $excludeId;
    }

    $candidateClauses = [];
    if ($storeId !== '') {
        $candidateClauses[] = 'store_id = ?';
        $params[] = $storeId;
    }
    if ($nameNorm !== '') {
        $nameLike = '%' . str_replace(' ', '%', $nameNorm) . '%';
        $candidateClauses[] = 'name LIKE ?';
        $params[] = $nameLike;
    }
    if ($phoneNorm !== '') {
        $phoneTail = substr($phoneNorm, -6);
        $candidateClauses[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone,''), ' ', ''), '-', ''), '+', ''), '(', ''), ')', '') LIKE ?";
        $params[] = '%' . $phoneTail;
    }
    if (!$candidateClauses) {
        return [];
    }

    $sql = "SELECT id, code, name, store_id, phone FROM suppliers WHERE "
        . ($where ? implode(' AND ', $where) . ' AND ' : '')
        . '(' . implode(' OR ', $candidateClauses) . ') ORDER BY id DESC LIMIT 25';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $duplicates = [];
    foreach ($rows as $row) {
        $reasons = [];
        if ($storeId !== '' && trim((string) ($row['store_id'] ?? '')) !== '' && strcasecmp(trim((string) $row['store_id']), $storeId) === 0) {
            $reasons[] = 'same store_id';
        }

        $rowPhoneNorm = normalizeSupplierDuplicatePhone((string) ($row['phone'] ?? ''));
        $phoneMatches = $phoneNorm !== '' && $rowPhoneNorm !== '' && (
            $rowPhoneNorm === $phoneNorm
            || str_ends_with($rowPhoneNorm, $phoneNorm)
            || str_ends_with($phoneNorm, $rowPhoneNorm)
        );
        $rowNameNorm = normalizeSupplierDuplicateName((string) ($row['name'] ?? ''));
        $nameSimilarity = supplierNameSimilarity($nameNorm, $rowNameNorm);
        $nameStrongMatch = $nameSimilarity >= 0.88
            || ($nameNorm !== '' && $rowNameNorm !== '' && (str_contains($rowNameNorm, $nameNorm) || str_contains($nameNorm, $rowNameNorm)));

        if ($phoneMatches && $nameStrongMatch) {
            $reasons[] = sprintf('same phone + %.0f%% similar name', $nameSimilarity * 100);
        }

        if ($reasons) {
            $duplicates[] = [
                'id' => (int) $row['id'],
                'code' => (string) ($row['code'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'store_id' => (string) ($row['store_id'] ?? ''),
                'phone' => (string) ($row['phone'] ?? ''),
                'reasons' => $reasons,
            ];
        }
    }

    return $duplicates;
}

function ensureSupplierDuplicateSafety(PDO $pdo, string $name, ?string $storeId, ?string $phone, ?int $excludeId = null): void
{
    $duplicates = findLikelySupplierDuplicates($pdo, $name, $storeId, $phone, $excludeId);
    if (!$duplicates) {
        return;
    }

    $top = array_slice($duplicates, 0, 3);
    $summary = implode('; ', array_map(
        static fn(array $row): string => trim(sprintf(
            '%s (%s)%s%s',
            $row['name'] ?: ('#' . $row['id']),
            $row['code'] ?: ('ID ' . $row['id']),
            !empty($row['store_id']) ? ' store_id ' . $row['store_id'] : '',
            !empty($row['phone']) ? ' phone ' . $row['phone'] : ''
        )) . ' [' . implode(', ', $row['reasons']) . ']',
        $top
    ));

    jsonError(
        'Likely duplicate supplier found. Review existing records before saving: ' . $summary,
        409,
        ['duplicates' => $duplicates]
    );
}

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();
    $readRoles = ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'ContainersStaff', 'FieldStaff', 'SuperAdmin'];
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
                $settlementExpr = supplierSettlementDeltaExpr($pdo);
                if ($paymentStatus === 'outstanding') {
                    $where[] = "EXISTS (SELECT 1 FROM (SELECT currency, SUM(COALESCE(invoice_amount, amount)) as inv, SUM(amount) as paid, SUM($settlementExpr) as settled FROM supplier_payments WHERE supplier_id = s.id GROUP BY currency) x WHERE (inv - paid - settled) > 0)";
                } elseif ($paymentStatus === 'fully_paid') {
                    $where[] = "NOT EXISTS (SELECT 1 FROM (SELECT currency, SUM(COALESCE(invoice_amount, amount)) as inv, SUM(amount) as paid, SUM($settlementExpr) as settled FROM supplier_payments WHERE supplier_id = s.id GROUP BY currency) x WHERE (inv - paid - settled) > 0)";
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
                    $r['payment_links'] = decodeSupplierPaymentLinks($r['payment_links'] ?? null);
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
            $row['payment_links'] = decodeSupplierPaymentLinks($row['payment_links'] ?? null);
            $row['reliability_score'] = calcSupplierScore($pdo, (int) $id);

            if ($action === 'balance') {
                requireRole($financeRoles);
                $settlementExpr = supplierSettlementDeltaExpr($pdo);
                $stmt = $pdo->prepare("SELECT currency, SUM(amount) as total_paid, SUM(COALESCE(invoice_amount,amount)) as total_invoiced, SUM($settlementExpr) as total_discount FROM supplier_payments WHERE supplier_id = ? GROUP BY currency");
                $stmt->execute([$id]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $balance = [];
                foreach ($rows as $r) {
                    $balance[$r['currency']] = [
                        'total_paid' => (float) $r['total_paid'],
                        'total_invoiced' => (float) $r['total_invoiced'],
                        'total_discount' => (float) $r['total_discount'],
                        'outstanding' => round((float) $r['total_invoiced'] - (float) $r['total_paid'] - (float) $r['total_discount'], 4),
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
                $currency = trim($input['currency'] ?? 'RMB');
                if (!in_array($currency, ['USD', 'RMB'], true)) jsonError('Currency must be USD or RMB', 400);
                $paymentType = in_array($input['payment_type'] ?? '', ['partial', 'full']) ? $input['payment_type'] : 'partial';
                $paymentChannel = normalizeSupplierPaymentMethodName((string) ($input['payment_channel'] ?? ''));
                if ($paymentChannel !== '' && !in_array($paymentChannel, ['WeChat', 'Alipay', 'Bank Transfer'], true)) {
                    jsonError('Unsupported payment channel', 400);
                }
                $paymentAccountLabel = trim((string) ($input['payment_account_label'] ?? '')) ?: null;
                $paymentAccountValue = trim((string) ($input['payment_account_value'] ?? '')) ?: null;
                $paymentAccountQrPath = trim((string) ($input['payment_account_qr_path'] ?? '')) ?: null;
                if ($paymentAccountQrPath !== null && str_contains($paymentAccountQrPath, '..')) {
                    jsonError('Invalid payment account QR path', 400);
                }
                $notes = $input['notes'] ?? null;
                $orderId = !empty($input['order_id']) ? (int) $input['order_id'] : null;
                if ($amount <= 0) jsonError('Amount must be positive', 400);
                $invoiceAmount = isset($input['invoice_amount']) ? (float) $input['invoice_amount'] : null;
                $markedFull = !empty($input['marked_full_payment']) ? 1 : 0;
                $settlementMode = trim((string) ($input['settlement_mode'] ?? ''));
                $settlementNote = trim((string) ($input['settlement_note'] ?? '')) ?: null;
                $settlementDelta = ($invoiceAmount !== null && $invoiceAmount > $amount && $markedFull)
                    ? round($invoiceAmount - $amount, 4)
                    : 0;
                $discountAmount = ($invoiceAmount !== null && $invoiceAmount > $amount) ? round($invoiceAmount - $amount, 4) : 0;
                $userId = getAuthUserId() ?? 1;
                $markedBy = $markedFull ? $userId : null;
                if ($orderId) {
                    $orderStmt = $pdo->prepare("SELECT id FROM orders WHERE id = ? AND (supplier_id = ? OR EXISTS (SELECT 1 FROM order_items oi LEFT JOIN products p ON oi.product_id = p.id WHERE oi.order_id = orders.id AND COALESCE(oi.supplier_id, p.supplier_id) = ?))");
                    $orderStmt->execute([$orderId, $id, $id]);
                    if (!$orderStmt->fetchColumn()) {
                        jsonError('Selected order does not belong to this supplier', 400);
                    }
                }
                if ($markedFull && $settlementDelta > 0 && $settlementMode === '') {
                    $settlementMode = 'fully_settled_by_agreement';
                }

                $columns = ['supplier_id', 'order_id', 'amount', 'invoice_amount', 'discount_amount', 'marked_full_payment', 'marked_by', 'currency', 'payment_type', 'notes'];
                $values = ['?', '?', '?', '?', '?', '?', '?', '?', '?', '?'];
                $params = [$id, $orderId, $amount, $invoiceAmount, $discountAmount, $markedFull, $markedBy, $currency, $paymentType, $notes];
                if (supplierTableHasColumn($pdo, 'supplier_payments', 'payment_channel')) {
                    $columns[] = 'payment_channel';
                    $values[] = '?';
                    $params[] = $paymentChannel ?: null;
                }
                if (supplierTableHasColumn($pdo, 'supplier_payments', 'payment_account_label')) {
                    $columns[] = 'payment_account_label';
                    $values[] = '?';
                    $params[] = $paymentAccountLabel;
                }
                if (supplierTableHasColumn($pdo, 'supplier_payments', 'payment_account_value')) {
                    $columns[] = 'payment_account_value';
                    $values[] = '?';
                    $params[] = $paymentAccountValue;
                }
                if (supplierTableHasColumn($pdo, 'supplier_payments', 'payment_account_qr_path')) {
                    $columns[] = 'payment_account_qr_path';
                    $values[] = '?';
                    $params[] = $paymentAccountQrPath;
                }
                if (supplierTableHasColumn($pdo, 'supplier_payments', 'settlement_delta')) {
                    $columns[] = 'settlement_delta';
                    $values[] = '?';
                    $params[] = $settlementDelta;
                }
                if (supplierTableHasColumn($pdo, 'supplier_payments', 'settlement_mode')) {
                    $columns[] = 'settlement_mode';
                    $values[] = '?';
                    $params[] = $settlementMode ?: null;
                }
                if (supplierTableHasColumn($pdo, 'supplier_payments', 'settlement_note')) {
                    $columns[] = 'settlement_note';
                    $values[] = '?';
                    $params[] = $settlementNote;
                }
                $pdo->prepare("INSERT INTO supplier_payments (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ")")
                    ->execute($params);
                $newId = (int) $pdo->lastInsertId();
                logClms('supplier_payment', ['supplier_id' => (int)$id, 'payment_id' => $newId, 'amount' => $amount, 'invoice_amount' => $invoiceAmount, 'discount' => $discountAmount, 'settlement_delta' => $settlementDelta, 'settlement_mode' => $settlementMode, 'marked_full' => $markedFull]);
                $row = $pdo->prepare("SELECT * FROM supplier_payments WHERE id = ?");
                $row->execute([$newId]);
                jsonResponse(['data' => $row->fetch(PDO::FETCH_ASSOC)], 201);
            }
            if ($id && $action === 'balance') {
                $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE id = ?");
                $stmt->execute([$id]);
                if (!$stmt->fetch()) jsonError('Supplier not found', 404);
                $settlementExpr = supplierSettlementDeltaExpr($pdo);
                $stmt = $pdo->prepare("SELECT currency, SUM(amount) as total_paid, SUM(COALESCE(invoice_amount,amount)) as total_invoiced, SUM($settlementExpr) as total_discount FROM supplier_payments WHERE supplier_id = ? GROUP BY currency");
                $stmt->execute([$id]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $balance = [];
                foreach ($rows as $r) {
                    $balance[$r['currency']] = [
                        'total_paid' => (float)$r['total_paid'],
                        'total_invoiced' => (float)$r['total_invoiced'],
                        'total_discount' => (float)$r['total_discount'],
                        'outstanding' => round((float)$r['total_invoiced'] - (float)$r['total_paid'] - (float)$r['total_discount'], 4),
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
            $paymentFacilityDays = array_key_exists('payment_facility_days', $input) && $input['payment_facility_days'] !== '' ? max(0, (int) $input['payment_facility_days']) : null;
            $paymentLinks = normalizeSupplierPaymentLinks($input['payment_links'] ?? null);
            [$commissionRate, $commissionType, $commissionAppliedOn] = normalizeSupplierCommission($input);
            ensureSupplierDuplicateSafety($pdo, $name, $storeId, $phone);
            try {
                $hasAddr = supplierTableHasColumn($pdo, 'suppliers', 'address');
                $hasFax = supplierTableHasColumn($pdo, 'suppliers', 'fax');
                $hasCommission = supplierTableHasColumn($pdo, 'suppliers', 'commission_rate');
                $hasPaymentFacility = supplierTableHasColumn($pdo, 'suppliers', 'payment_facility_days');
                $hasPaymentLinks = supplierTableHasColumn($pdo, 'suppliers', 'payment_links');
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
                if ($hasPaymentFacility) {
                    $cols .= ", payment_facility_days";
                    $vals .= ", ?";
                    $params[] = $paymentFacilityDays;
                }
                if ($hasPaymentLinks) {
                    $cols .= ", payment_links";
                    $vals .= ", ?";
                    $params[] = $paymentLinks;
                }
                $stmt = $pdo->prepare("INSERT INTO suppliers ($cols) VALUES ($vals)");
                $stmt->execute($params);
                $newId = (int) $pdo->lastInsertId();
                $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
                $stmt->execute([$newId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $row['contacts'] = $row['contacts'] ? json_decode($row['contacts'], true) : [];
                $row['additional_ids'] = $row['additional_ids'] ? json_decode($row['additional_ids'], true) : [];
                $row['payment_links'] = decodeSupplierPaymentLinks($row['payment_links'] ?? null);
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
            $paymentFacilityDays = array_key_exists('payment_facility_days', $input) && $input['payment_facility_days'] !== '' ? max(0, (int) $input['payment_facility_days']) : null;
            $paymentLinks = normalizeSupplierPaymentLinks($input['payment_links'] ?? null);
            [$commissionRate, $commissionType, $commissionAppliedOn] = normalizeSupplierCommission($input);
            ensureSupplierDuplicateSafety($pdo, $name, $storeId, $phone, (int) $id);
            $hasAddr = supplierTableHasColumn($pdo, 'suppliers', 'address');
            $hasFax = supplierTableHasColumn($pdo, 'suppliers', 'fax');
            $hasCommission = supplierTableHasColumn($pdo, 'suppliers', 'commission_rate');
            $hasPaymentFacility = supplierTableHasColumn($pdo, 'suppliers', 'payment_facility_days');
            $hasPaymentLinks = supplierTableHasColumn($pdo, 'suppliers', 'payment_links');
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
            if ($hasPaymentFacility) {
                $sets .= ", payment_facility_days=?";
                $params[] = $paymentFacilityDays;
            }
            if ($hasPaymentLinks) {
                $sets .= ", payment_links=?";
                $params[] = $paymentLinks;
            }
            $params[] = $id;
            $pdo->prepare("UPDATE suppliers SET $sets WHERE id=?")->execute($params);
            $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $row['contacts'] = $row['contacts'] ? json_decode($row['contacts'], true) : [];
            $row['additional_ids'] = $row['additional_ids'] ? json_decode($row['additional_ids'], true) : [];
            $row['payment_links'] = decodeSupplierPaymentLinks($row['payment_links'] ?? null);
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
