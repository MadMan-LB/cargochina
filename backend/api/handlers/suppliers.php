<?php

/**
 * Suppliers API - GET list, GET one, POST create, PUT update, DELETE
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__,2).'/services/AuditService.php';
require_once dirname(__DIR__,2).'/services/CatalogRevisionService.php';
require_once dirname(__DIR__,2).'/services/SupplierWriteService.php';
require_once dirname(__DIR__,2).'/services/SupplierLifecycleService.php';
require_once dirname(__DIR__,2).'/services/SupplierItemsService.php';
require_once dirname(__DIR__,2).'/services/MasterDataImportService.php';

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

function generateSupplierCode(PDO $pdo, string $name = ''): string
{
    $base = strtoupper(preg_replace('/[^A-Z0-9]+/i', '', $name) ?? '');
    $base = $base !== '' ? substr($base, 0, 8) : 'SUP';
    $stmt = $pdo->prepare("SELECT 1 FROM suppliers WHERE code = ? LIMIT 1");
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $suffix = strtoupper(base_convert((string) random_int(100000, 999999), 10, 36));
        $code = substr($base . '-' . $suffix, 0, 50);
        $stmt->execute([$code]);
        if (!$stmt->fetchColumn()) {
            return $code;
        }
    }
    return substr('SUP-' . date('YmdHis') . '-' . random_int(100, 999), 0, 50);
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

function normalizeSupplierQrText(?string $value): ?string
{
    $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', (string) ($value ?? '')) ?? '');
    if ($value === '') {
        return null;
    }
    return mb_substr($value, 0, 2048, 'UTF-8');
}

function supplierQrComparable(?string $value): string
{
    $value = normalizeSupplierQrText($value);
    if ($value === null) {
        return '';
    }
    return mb_strtolower(trim($value), 'UTF-8');
}

function findSupplierByPaymentQrContent(PDO $pdo, ?string $content, ?int $excludeId = null): ?array
{
    $needle = supplierQrComparable($content);
    if ($needle === '' || !supplierTableHasColumn($pdo, 'suppliers', 'payment_links')) {
        return null;
    }

    $stmt = $pdo->query("SELECT id, code, name, payment_links FROM suppliers WHERE payment_links IS NOT NULL AND payment_links <> ''");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $supplier) {
        if ($excludeId !== null && (int) $supplier['id'] === $excludeId) {
            continue;
        }
        $links = json_decode((string) ($supplier['payment_links'] ?? ''), true);
        if (!is_array($links)) {
            continue;
        }
        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }
            $candidates = [
                $link['qr_raw_content'] ?? null,
                $link['decoded_qr_content'] ?? null,
                $link['value'] ?? null,
                $link['link'] ?? null,
                $link['account_value'] ?? null,
            ];
            foreach ($candidates as $candidate) {
                if ($needle !== '' && supplierQrComparable($candidate) === $needle) {
                    return [
                        'id' => (int) $supplier['id'],
                        'code' => (string) ($supplier['code'] ?? ''),
                        'name' => (string) ($supplier['name'] ?? ''),
                    ];
                }
            }
        }
    }
    return null;
}

function findSupplierByPaymentQrPath(PDO $pdo, ?string $path, ?int $excludeId = null): ?array
{
    $needle = trim((string) ($path ?? ''));
    if ($needle === '' || str_contains($needle, '..') || !supplierTableHasColumn($pdo, 'suppliers', 'payment_links')) {
        return null;
    }
    $stmt = $pdo->query("SELECT id, code, name, payment_links FROM suppliers WHERE payment_links IS NOT NULL AND payment_links <> ''");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $supplier) {
        if ($excludeId !== null && (int) $supplier['id'] === $excludeId) {
            continue;
        }
        $links = json_decode((string) ($supplier['payment_links'] ?? ''), true);
        if (!is_array($links)) {
            continue;
        }
        foreach ($links as $link) {
            if (is_array($link) && trim((string) ($link['qr_image_path'] ?? '')) === $needle) {
                return [
                    'id' => (int) $supplier['id'],
                    'code' => (string) ($supplier['code'] ?? ''),
                    'name' => (string) ($supplier['name'] ?? ''),
                ];
            }
        }
    }
    return null;
}

function ensureSupplierQrDuplicateSafety(PDO $pdo, ?string $paymentLinksJson, ?int $excludeId = null): void
{
    if (!$paymentLinksJson) {
        return;
    }
    $links = json_decode($paymentLinksJson, true);
    if (!is_array($links)) {
        return;
    }
    foreach ($links as $link) {
        if (!is_array($link)) {
            continue;
        }
        foreach ([$link['qr_raw_content'] ?? null, $link['decoded_qr_content'] ?? null] as $content) {
            $duplicate = findSupplierByPaymentQrContent($pdo, $content, $excludeId);
            if ($duplicate) {
                jsonError(
                    'This QR is Already Linked',
                    409,
                    [
                        'wechat_qr' => 'This QR is Already Linked',
                        'supplier_id' => $duplicate['id'],
                        'supplier_name' => $duplicate['name'],
                    ]
                );
            }
        }
        $duplicateByPath = findSupplierByPaymentQrPath($pdo, $link['qr_image_path'] ?? null, $excludeId);
        if ($duplicateByPath) {
            jsonError(
                'This QR is Already Linked',
                409,
                [
                    'wechat_qr' => 'This QR is Already Linked',
                    'supplier_id' => $duplicateByPath['id'],
                    'supplier_name' => $duplicateByPath['name'],
                ]
            );
        }
    }
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
        $qrRawContent = normalizeSupplierQrText($row['qr_raw_content'] ?? $row['decoded_qr_content'] ?? null);
        $decodedQrContent = normalizeSupplierQrText($row['decoded_qr_content'] ?? $qrRawContent);
        if ($rawMethod === '' && $accountLabel === '' && $content === '' && $qrImagePath === '' && $qrRawContent === null && $decodedQrContent === null) {
            continue;
        }
        if (!in_array($currency, ['RMB', 'USD'], true)) {
            $currency = 'RMB';
        }
        if ($qrImagePath !== '' && str_contains($qrImagePath, '..')) {
            jsonError('Invalid supplier payment QR path', 400);
        }
        $normalizedRow = [
            'method' => $method,
            'account_label' => $accountLabel ?: $method,
            'label' => $accountLabel ?: $method,
            'value' => $content ?: null,
            'currency' => $currency,
            'qr_image_path' => $qrImagePath !== '' ? $qrImagePath : null,
        ];
        if ($qrRawContent !== null || $decodedQrContent !== null) {
            $now = date('Y-m-d H:i:s');
            $normalizedRow['qr_raw_content'] = $qrRawContent;
            $normalizedRow['decoded_qr_content'] = $decodedQrContent ?: $qrRawContent;
            $normalizedRow['created_by'] = isset($row['created_by']) ? (int) $row['created_by'] : (getAuthUserId() ?? null);
            $normalizedRow['created_at'] = trim((string) ($row['created_at'] ?? '')) ?: $now;
            $normalizedRow['updated_at'] = $now;
        }
        $rows[] = $normalizedRow;
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
    require_once __DIR__ . '/../authorization.php';
    clmsAuthorizeApiRequest('suppliers', $method, $id, $action);
    $pdo = getDb();
    if ($method === 'GET') { require_once dirname(__DIR__, 2) . '/services/QueryFilterService.php'; QueryFilterService::validate($_GET, 'suppliers'); }
    $readRoles = ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'ContainersStaff', 'FieldStaff', 'SuperAdmin'];
    $buyerRoles = ['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin'];
    $managementReadRoles = ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'FieldStaff', 'SuperAdmin'];
    $financeRoles = ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'SuperAdmin'];
    $interactionRoles = ['ChinaAdmin', 'ChinaEmployee', 'FieldStaff', 'SuperAdmin'];
    $canViewFinancials = hasPermission('suppliers.finance', $financeRoles);

    if ($method === 'GET') {
        requirePermission('suppliers.read', $readRoles);
    } elseif ($method === 'PUT' || $method === 'DELETE') {
        requirePermission('suppliers.write', $buyerRoles);
    } elseif ($method === 'POST') {
        if ($id === 'import') {
            requirePermission('suppliers.import', $buyerRoles);
        } elseif ($id && $action === 'interactions') {
            requirePermission('suppliers.interactions', $interactionRoles);
        } elseif ($id && in_array($action, ['payments', 'balance'], true)) {
            requirePermission('suppliers.finance', $financeRoles);
        } else {
            requirePermission('suppliers.create', $buyerRoles);
        }
    }

    if(($method==='POST'&&$id===null)||$method==='PUT'){
        $input=SupplierWriteService::normalize($input);
        MasterDataImportService::lock($pdo,'supplier');
    }
    $createClaim=null;
    if($method==='POST' && $id===null) {
        $createClaim=OperationReplayService::claim($pdo,'catalog_suppliers',$input,(int)getAuthUserId());
        if($createClaim['previous_id']) jsonResponse(['data'=>['id'=>$createClaim['previous_id']],'idempotent_replay'=>true]);
    }
    switch ($method) {
        case 'GET':
            if ($id === 'wechat-qr-duplicate') {
                requirePermission('suppliers.create', $buyerRoles);
                $content = normalizeSupplierQrText($_GET['content'] ?? '');
                $excludeId = !empty($_GET['exclude_id']) ? (int) $_GET['exclude_id'] : null;
                if ($content === null) {
                    jsonError('QR content is required', 400);
                }
                jsonResponse(['data' => ['duplicate' => findSupplierByPaymentQrContent($pdo, $content, $excludeId)]]);
            }
            if ($id === 'search') {
                $q = clmsNormalizeSearchQuery($_GET['q'] ?? '');
                if (strlen($q) < 1) {
                    jsonResponse(['data' => []]);
                }
                $like = clmsSearchLike($q);
                // Operational selectors intentionally exclude payment accounts,
                // notes, commissions, addresses, and interaction history.
                $stmt = $pdo->prepare("SELECT id, code, name, phone, store_id FROM suppliers
                    WHERE ".SupplierLifecycleService::activeSql($pdo)." AND (" . clmsUtf8SearchExpr('name') . " LIKE ?
                       OR " . clmsUtf8SearchExpr('code') . " LIKE ?
                       OR (phone IS NOT NULL AND " . clmsUtf8SearchExpr('phone') . " LIKE ?)
                       OR (store_id IS NOT NULL AND " . clmsUtf8SearchExpr('store_id') . " LIKE ?)
                    ) ORDER BY name LIMIT 15");
                $stmt->execute([$like, $like, $like, $like]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                jsonResponse(['data' => $rows]);
            }
            requirePermission($id === null ? 'suppliers.manage.read' : 'suppliers.details.read', $managementReadRoles);
            if ($id === null) {
                $q = clmsNormalizeSearchQuery($_GET['q'] ?? '');
                $paymentStatus = trim($_GET['payment_status'] ?? '');
                $hasAddress = supplierTableHasColumn($pdo, 'suppliers', 'address');
                $sortOptions = ['name', 'code', 'store_id', 'phone', 'factory_location'];
                if ($hasAddress) {
                    $sortOptions[] = 'address';
                }
                $sort = clmsQuerySort($_GET['sort'] ?? null, $sortOptions, 'name');
                $order = clmsQueryDirection($_GET['order'] ?? null, 'ASC');

                $where = [SupplierLifecycleService::activeSql($pdo,'s')];
                $params = [];
                if (strlen($q) >= 1) {
                    $like = clmsSearchLike($q);
                    $addressSearch = $hasAddress ? " OR (s.address IS NOT NULL AND s.address LIKE ?)" : "";
                    $where[] = "(s.name LIKE ? OR s.code LIKE ? OR (s.phone IS NOT NULL AND s.phone LIKE ?) OR (s.store_id IS NOT NULL AND s.store_id LIKE ?) OR (s.factory_location IS NOT NULL AND s.factory_location LIKE ?)$addressSearch)";
                    $params = array_merge($params, [$like, $like, $like, $like, $like]);
                    if ($hasAddress) {
                        $params[] = $like;
                    }
                }
                $settlementExpr = supplierSettlementDeltaExpr($pdo);
                if ($paymentStatus === 'outstanding') {
                    $where[] = "EXISTS (SELECT 1 FROM supplier_payments WHERE supplier_id = s.id GROUP BY currency HAVING SUM(COALESCE(invoice_amount, amount)) - SUM(amount) - SUM($settlementExpr) > 0)";
                } elseif ($paymentStatus === 'fully_paid') {
                    $where[] = "NOT EXISTS (SELECT 1 FROM supplier_payments WHERE supplier_id = s.id GROUP BY currency HAVING SUM(COALESCE(invoice_amount, amount)) - SUM(amount) - SUM($settlementExpr) > 0)";
                }
                $sql = "SELECT s.* FROM suppliers s";
                if (!empty($where)) {
                    $sql .= " WHERE " . implode(" AND ", $where);
                }
                $limit = clmsQueryLimit($_GET['limit'] ?? null, 50, 100);
                $offset = clmsQueryOffset($_GET['offset'] ?? null);
                $countStmt = $params ? $pdo->prepare("SELECT COUNT(*) FROM ($sql) suppliers_filtered") : $pdo->query("SELECT COUNT(*) FROM ($sql) suppliers_filtered");
                if ($params) $countStmt->execute($params);
                $total = (int) $countStmt->fetchColumn();
                $sql .= " ORDER BY s." . $sort . " " . $order . ", s.id ASC LIMIT " . ($limit + 1) . " OFFSET " . $offset;

                $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
                if ($params) $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $hasMore = count($rows) > $limit;
                if ($hasMore) $rows = array_slice($rows, 0, $limit);

                foreach ($rows as &$r) {
                    $r['contacts'] = $r['contacts'] ? json_decode($r['contacts'], true) : [];
                    $r['additional_ids'] = $r['additional_ids'] ? json_decode($r['additional_ids'], true) : [];
                    $r['payment_links'] = decodeSupplierPaymentLinks($r['payment_links'] ?? null);
                    $r['reliability_score'] = calcSupplierScore($pdo, (int) $r['id']);
                    if (!$canViewFinancials) {
                        unset($r['commission_rate'], $r['commission_type'], $r['commission_applied_on']);
                    }
                }
                jsonResponse(['data' => $rows, 'meta' => ['limit' => $limit, 'offset' => $offset, 'has_more' => $hasMore, 'total' => $total]]);
            }
            if($action==='items-orders'){
                requirePermission('suppliers.manage.read');
                $s=$pdo->prepare('SELECT id,code,name FROM suppliers WHERE id=?');$s->execute([$id]);$supplier=$s->fetch(PDO::FETCH_ASSOC);if(!$supplier)jsonError('Supplier not found',404);
                $result=SupplierItemsService::listing($pdo,(int)$id,$_GET);$result['meta']['supplier']=$supplier;jsonResponse($result);
            }
            $revisionReadOwned = !$pdo->inTransaction();
            if ($revisionReadOwned) AuditService::begin($pdo);
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
                requirePermission('suppliers.finance', $financeRoles);
                $settlementExpr = supplierSettlementDeltaExpr($pdo);
                $stmt = $pdo->prepare("SELECT currency, SUM(amount) as total_paid, SUM(COALESCE(invoice_amount,amount)) as total_invoiced, SUM($settlementExpr) as total_discount FROM supplier_payments WHERE supplier_id = ? GROUP BY currency");
                $stmt->execute([$id]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $balance = [];
                foreach ($rows as $r) {
                    $totalPaid = DecimalMath::normalize($r['total_paid'] ?? '0');
                    $totalInvoiced = DecimalMath::normalize($r['total_invoiced'] ?? '0');
                    $totalDiscount = DecimalMath::normalize($r['total_discount'] ?? '0');
                    $balance[$r['currency']] = [
                        'total_paid' => $totalPaid,
                        'total_invoiced' => $totalInvoiced,
                        'total_discount' => $totalDiscount,
                        'outstanding' => DecimalMath::subtract(DecimalMath::subtract($totalInvoiced, $totalPaid), $totalDiscount),
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
            $row['revision']=CatalogRevisionService::revision($pdo,'suppliers',(int)$id);
            if ($revisionReadOwned) $pdo->commit();
            jsonResponse(['data' => $row]);

        case 'POST':
            if ($id === 'import') {
                $result=MasterDataImportService::run($pdo,'supplier',$input,['name'],['code','name','store_id','phone','factory_location','address','notes'],function(array $row)use($pdo):?int{
                    $row=SupplierWriteService::normalize($row);
                    if(!empty($row['code'])){$s=$pdo->prepare('SELECT id FROM suppliers WHERE code=?');$s->execute([$row['code']]);if($existing=$s->fetchColumn()){SupplierLifecycleService::requireActive($pdo,(int)$existing);return null;}}
                    ensureSupplierDuplicateSafety($pdo,$row['name'],$row['store_id']??null,$row['phone']??null);
                    $fields=['code','name','store_id','phone','factory_location','address','notes'];$row['code']=$row['code']??generateSupplierCode($pdo,$row['name']);$values=[];foreach($fields as $field)$values[]=$row[$field]??null;
                    $pdo->prepare('INSERT INTO suppliers('.implode(',',$fields).') VALUES (?,?,?,?,?,?,?)')->execute($values);return (int)$pdo->lastInsertId();
                });
                jsonResponse(['data'=>$result]);
            }
            if ($id && $action === 'payments') {
                require_once dirname(__DIR__,2).'/services/SupplierPaymentService.php';
                require_once dirname(__DIR__,2).'/services/OperationReplayService.php';
                $userId=requireAuth();$input['supplier_id']=(int)$id;
                $claim=OperationReplayService::claim($pdo,'supplier_payment',$input,$userId);
                if($claim['previous_id']){
                    $s=$pdo->prepare('SELECT * FROM supplier_payments WHERE id=?');$s->execute([$claim['previous_id']]);$saved=$s->fetch(PDO::FETCH_ASSOC);if(!$saved)jsonError('Original payment no longer exists',409);jsonResponse(['data'=>$saved]);
                }
                $pdo->beginTransaction();register_shutdown_function(static function()use($pdo){if($pdo->inTransaction())$pdo->rollBack();});
                try {
                    $newId=SupplierPaymentService::insert($pdo,(int)$id,$input,$userId);
                    $s=$pdo->prepare('SELECT * FROM supplier_payments WHERE id=?');$s->execute([$newId]);$saved=$s->fetch(PDO::FETCH_ASSOC);
                    OperationReplayService::record($pdo,'supplier_payment',$newId,$claim,$saved,$userId);
                    $pdo->commit();jsonResponse(['data'=>$saved],201);
                }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
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
                    $totalPaid = DecimalMath::normalize($r['total_paid'] ?? '0');
                    $totalInvoiced = DecimalMath::normalize($r['total_invoiced'] ?? '0');
                    $totalDiscount = DecimalMath::normalize($r['total_discount'] ?? '0');
                    $balance[$r['currency']] = [
                        'total_paid' => $totalPaid,
                        'total_invoiced' => $totalInvoiced,
                        'total_discount' => $totalDiscount,
                        'outstanding' => DecimalMath::subtract(DecimalMath::subtract($totalInvoiced, $totalPaid), $totalDiscount),
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
                AuditService::begin($pdo);
                $pdo->prepare("INSERT INTO supplier_interactions (supplier_id, interaction_type, content, created_by) VALUES (?,?,?,?)")
                    ->execute([$id, $type, $content, $userId]);
                $newId = (int) $pdo->lastInsertId();
                $row = $pdo->prepare("SELECT si.*, u.full_name as created_by_name FROM supplier_interactions si LEFT JOIN users u ON si.created_by = u.id WHERE si.id = ?");
                $row->execute([$newId]);
                $r = $row->fetch(PDO::FETCH_ASSOC);
                $r['content'] = $r['content'] ? json_decode($r['content'], true) : null;
                AuditService::record($pdo,'supplier_interaction',$newId,'create',null,$r,$userId);$pdo->commit();
                jsonResponse(['data' => $r], 201);
            }
            $code = trim($input['code'] ?? '');
            $name = trim($input['name'] ?? '');
            if (!$name) {
                jsonError('Missing required fields', 400, ['name' => 'Required']);
            }
            if (!$code) {
                $code = generateSupplierCode($pdo, $name);
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
            ensureSupplierQrDuplicateSafety($pdo, $paymentLinks, null);
            AuditService::begin($pdo);
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
                AuditService::record($pdo,'supplier',$newId,'create',null,AuditService::snapshot($pdo,'suppliers',$newId),getAuthUserId());
                OperationReplayService::record($pdo,'catalog_suppliers',$newId,$createClaim,[],(int)getAuthUserId());$pdo->commit();
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
            AuditService::begin($pdo);
            SupplierLifecycleService::requireActive($pdo,(int)$id);
            $auditBefore=AuditService::snapshot($pdo,'suppliers',(int)$id,true);
            CatalogRevisionService::assertCurrent($pdo,'suppliers',(int)$id,$input);
            $stmt = $pdo->prepare("SELECT id, code FROM suppliers WHERE id = ?");
            $stmt->execute([$id]);
            $existingSupplier = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existingSupplier) {
                jsonError('Supplier not found', 404);
            }
            $code = trim($input['code'] ?? '');
            $name = trim($input['name'] ?? '');
            if (!$name) {
                jsonError('Missing required fields', 400);
            }
            if (!$code) {
                $code = trim((string) ($existingSupplier['code'] ?? '')) ?: generateSupplierCode($pdo, $name);
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
            ensureSupplierQrDuplicateSafety($pdo, $paymentLinks, (int) $id);
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
            AuditService::record($pdo,'supplier',(int)$id,'update',$auditBefore,AuditService::snapshot($pdo,'suppliers',(int)$id),getAuthUserId());$pdo->commit();
            jsonResponse(['data' => $row]);

        case 'DELETE':
            if (!$id) {
                jsonError('ID required', 400);
            }
            AuditService::begin($pdo);
            $auditBefore=AuditService::snapshot($pdo,'suppliers',(int)$id,true);
            CatalogRevisionService::assertCurrent($pdo,'suppliers',(int)$id,$input);
            try {
                if(isset($input['delete_reason'])&&!is_string($input['delete_reason']))jsonError('Deletion reason must be text',422);
                SupplierLifecycleService::archive($pdo,(int)$id,(int)getAuthUserId(),$input['delete_reason']??null);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            jsonResponse(['message' => 'Supplier moved to Recycle Bin. Linked records are preserved.']);

        default:
            jsonError('Method not allowed', 405);
    }
};
