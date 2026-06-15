<?php

/**
 * Employee-safe Balances API.
 * Exposes customer/supplier balances and payment history without admin-only data.
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 3) . '/includes/sidebar_permissions.php';

function balancesCurrentUserCanAccess(): bool
{
    $roles = getUserRoles();
    return in_array('SuperAdmin', $roles, true) || clmsCanRolesAccessPage($roles, 'balances', null, getAuthUserId());
}

function balancesCurrentUserCanUseOrderLinks(): bool
{
    $roles = getUserRoles();
    return in_array('SuperAdmin', $roles, true) || clmsCanRolesAccessPage($roles, 'orders', null, getAuthUserId());
}

function balancesTableExists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        $cache[$table] = (bool) $stmt->rowCount();
    } catch (Throwable $e) {
        $cache[$table] = false;
    }
    return $cache[$table];
}

function balancesTableHasColumn(PDO $pdo, string $table, string $column): bool
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

function balancesSupplierSettlementExpr(PDO $pdo): string
{
    if (balancesTableHasColumn($pdo, 'supplier_payments', 'settlement_delta')) {
        return 'COALESCE(settlement_delta, COALESCE(discount_amount,0), 0)';
    }
    if (balancesTableHasColumn($pdo, 'supplier_payments', 'discount_amount')) {
        return 'COALESCE(discount_amount,0)';
    }
    return '0';
}

function balancesNormalizeCurrency(?string $currency): string
{
    $currency = strtoupper(trim((string) $currency));
    return in_array($currency, ['USD', 'RMB'], true) ? $currency : 'RMB';
}

function balancesCurrencyFilter(?string $currency): ?string
{
    $currency = strtoupper(trim((string) $currency));
    return in_array($currency, ['USD', 'RMB'], true) ? $currency : null;
}

function balancesNormalizePartyType(?string $partyType): ?string
{
    $partyType = strtolower(trim((string) $partyType));
    return in_array($partyType, ['customer', 'supplier'], true) ? $partyType : null;
}

function balancesNormalizeTransactionType(?string $type): string
{
    $type = strtolower(trim((string) $type));
    $allowed = ['payment_received', 'payment_sent', 'deposit', 'invoice', 'adjustment', 'refund', 'other'];
    return in_array($type, $allowed, true) ? $type : 'other';
}

function balancesNormalizePaymentMethodName(?string $value): string
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
    if (str_contains($normalized, 'cash')) {
        return 'Cash';
    }
    return '';
}

function balancesNormalizeDirection(string $partyType, string $transactionType, ?string $direction): string
{
    $direction = strtolower(trim((string) $direction));
    if (in_array($direction, ['increase_balance', 'reduce_balance'], true)) {
        return $direction;
    }
    if (($partyType === 'customer' && in_array($transactionType, ['payment_received', 'deposit'], true))
        || ($partyType === 'supplier' && in_array($transactionType, ['payment_sent', 'deposit'], true))) {
        return 'reduce_balance';
    }
    if ($transactionType === 'refund') {
        return $partyType === 'supplier' ? 'reduce_balance' : 'increase_balance';
    }
    return 'increase_balance';
}

function balancesNormalizeDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return date('Y-m-d');
    }
    $date = DateTime::createFromFormat('Y-m-d', $value);
    $errors = DateTime::getLastErrors();
    if (!$date || (($errors['warning_count'] ?? 0) + ($errors['error_count'] ?? 0)) > 0) {
        jsonError('Date must use YYYY-MM-DD format', 400);
    }
    return $date->format('Y-m-d');
}

function balancesDateInRange(?string $date, ?string $from, ?string $to): bool
{
    if (!$from && !$to) {
        return true;
    }
    if (!$date) {
        return false;
    }
    if ($from && $date < $from) {
        return false;
    }
    if ($to && $date > $to) {
        return false;
    }
    return true;
}

function balancesStatusFor(float $currentBalance): string
{
    if (abs($currentBalance) < 0.005) {
        return 'settled';
    }
    return $currentBalance > 0 ? 'due' : 'credit';
}

function balancesStatusLabel(string $status): string
{
    return match ($status) {
        'due' => 'Due',
        'credit' => 'Credit',
        default => 'Settled',
    };
}

function balancesDocumentTypeForRow(array $row): string
{
    $partyType = (string) ($row['party_type'] ?? '');
    $direction = (string) ($row['direction'] ?? '');
    $transactionType = (string) ($row['transaction_type'] ?? '');
    if ($direction === 'increase_balance') {
        return $transactionType === 'refund' ? 'Credit Note' : 'Invoice';
    }
    if ($partyType === 'supplier') {
        return 'Payment Voucher';
    }
    return $transactionType === 'refund' ? 'Refund Receipt' : 'Receipt';
}

function balancesDocumentNumberForRow(array $row): string
{
    $type = balancesDocumentTypeForRow($row);
    $prefix = match ($type) {
        'Invoice' => 'INV',
        'Credit Note' => 'CRN',
        'Payment Voucher' => 'PV',
        'Refund Receipt' => 'RFR',
        default => 'RCP',
    };
    $date = preg_replace('/\D+/', '', (string) ($row['transaction_date'] ?? date('Y-m-d'))) ?: date('Ymd');
    $id = (int) ($row['id'] ?? 0);
    return sprintf('%s-%s-%05d', $prefix, $date, $id);
}

function balancesIdPlaceholders(array $ids): string
{
    return implode(',', array_fill(0, count($ids), '?'));
}

function balancesUnionTextExpr(string $expr): string
{
    return "CONVERT($expr USING utf8mb4) COLLATE utf8mb4_unicode_ci";
}

function balancesSearchClause(string $alias, array $columns, string $q, array &$params): string
{
    if ($q === '') {
        return '';
    }
    $like = '%' . preg_replace('/\s+/', '%', $q) . '%';
    $parts = [];
    foreach ($columns as $column) {
        $parts[] = "($alias.$column IS NOT NULL AND $alias.$column COLLATE utf8mb4_unicode_ci LIKE ?)";
        $params[] = $like;
    }
    return $parts ? (' AND (' . implode(' OR ', $parts) . ')') : '';
}

function balancesPartySearchClause(PDO $pdo, string $alias, string $table, array $columns, string $q, array &$params): string
{
    if ($q === '') {
        return '';
    }
    $like = '%' . preg_replace('/\s+/', '%', $q) . '%';
    $parts = [];
    foreach ($columns as $column) {
        $parts[] = "($alias.$column IS NOT NULL AND $alias.$column COLLATE utf8mb4_unicode_ci LIKE ?)";
        $params[] = $like;
    }
    if (balancesTableHasColumn($pdo, $table, 'payment_links')) {
        $parts[] = "($alias.payment_links IS NOT NULL AND CONVERT($alias.payment_links USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?)";
        $params[] = $like;
    }
    return $parts ? (' AND (' . implode(' OR ', $parts) . ')') : '';
}

function balancesFetchParties(PDO $pdo, string $partyType, string $q): array
{
    $table = $partyType === 'customer' ? 'customers' : 'suppliers';
    $alias = $partyType === 'customer' ? 'c' : 's';
    $cols = ["$alias.id", "$alias.name", "$alias.code"];
    $searchCols = ['name', 'code'];
    if (balancesTableHasColumn($pdo, $table, 'phone')) {
        $cols[] = "$alias.phone";
        $searchCols[] = 'phone';
    } else {
        $cols[] = "NULL as phone";
    }
    if ($partyType === 'customer' && balancesTableHasColumn($pdo, 'customers', 'default_shipping_code')) {
        $searchCols[] = 'default_shipping_code';
    }
    if ($partyType === 'supplier' && balancesTableHasColumn($pdo, 'suppliers', 'store_id')) {
        $searchCols[] = 'store_id';
    }

    $params = [];
    $sql = 'SELECT ' . implode(', ', $cols) . " FROM $table $alias WHERE 1=1";
    if ($partyType === 'customer') {
        $scope = clmsCustomerVisibilityClause($pdo, $alias);
        $sql .= " AND {$scope['sql']}";
        $params = array_merge($params, $scope['params']);
    }
    $sql .= balancesPartySearchClause($pdo, $alias, $table, $searchCols, $q, $params);
    $sql .= " ORDER BY $alias.name";
    $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
    if ($params) {
        $stmt->execute($params);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function balancesDecodePaymentLinks(?string $value): array
{
    if (!$value) {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function balancesNormalizePaymentAccounts(string $partyType, array $links): array
{
    $accounts = [];
    foreach ($links as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $rawMethod = trim((string) ($row['method'] ?? $row['type'] ?? $row['name'] ?? $row['label'] ?? ''));
        $method = balancesNormalizePaymentMethodName($rawMethod) ?: '';
        $label = trim((string) ($row['account_label'] ?? $row['label'] ?? $row['name'] ?? $rawMethod));
        $value = trim((string) ($row['value'] ?? $row['link'] ?? $row['account_value'] ?? ''));
        $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
        if (!in_array($currency, ['USD', 'RMB'], true)) {
            $currency = '';
        }
        $qrPath = trim((string) ($row['qr_image_path'] ?? $row['qr'] ?? $row['payment_account_qr_path'] ?? ''));
        if ($label === '' && $value === '' && $qrPath === '') {
            continue;
        }
        $accounts[] = [
            'id' => $index,
            'party_type' => $partyType,
            'method' => $method,
            'label' => $label ?: ($method ?: 'Account'),
            'account_label' => $label ?: ($method ?: 'Account'),
            'value' => $value,
            'currency' => $currency,
            'qr_image_path' => $qrPath,
        ];
    }
    return $accounts;
}

function balancesFetchPartyPaymentAccounts(PDO $pdo, string $partyType, int $partyId): array
{
    $table = $partyType === 'customer' ? 'customers' : 'suppliers';
    if ($partyType === 'customer') {
        clmsRequireCustomerAccess($pdo, $partyId);
    }
    if (!balancesTableHasColumn($pdo, $table, 'payment_links')) {
        return [];
    }
    $stmt = $pdo->prepare("SELECT payment_links FROM $table WHERE id = ?");
    $stmt->execute([$partyId]);
    $raw = $stmt->fetchColumn();
    if ($raw === false) {
        return [];
    }
    return balancesNormalizePaymentAccounts($partyType, balancesDecodePaymentLinks($raw ?: null));
}

function balancesAppendPaymentAccountIfMissing(PDO $pdo, string $partyType, int $partyId, ?string $label, ?string $value, ?string $method, string $currency, ?string $qrPath): bool
{
    $value = trim((string) $value);
    if ($value === '') {
        return false;
    }
    $table = $partyType === 'customer' ? 'customers' : 'suppliers';
    if ($partyType === 'customer') {
        clmsRequireCustomerAccess($pdo, $partyId);
    }
    if (!balancesTableHasColumn($pdo, $table, 'payment_links')) {
        return false;
    }

    $stmt = $pdo->prepare("SELECT payment_links FROM $table WHERE id = ? FOR UPDATE");
    $stmt->execute([$partyId]);
    $raw = $stmt->fetchColumn();
    if ($raw === false) {
        return false;
    }
    $links = balancesDecodePaymentLinks($raw ?: null);
    $needle = mb_strtolower($value, 'UTF-8');
    foreach (balancesNormalizePaymentAccounts($partyType, $links) as $existing) {
        if (mb_strtolower(trim((string) ($existing['value'] ?? '')), 'UTF-8') === $needle) {
            return false;
        }
    }

    $label = trim((string) $label) ?: (trim((string) $method) ?: 'Account');
    if ($partyType === 'customer') {
        $links[] = [
            'name' => $label,
            'value' => $value,
        ];
    } else {
        $normalizedMethod = balancesNormalizePaymentMethodName($method ?: $label) ?: 'Bank Transfer';
        $links[] = [
            'method' => $normalizedMethod,
            'account_label' => $label,
            'label' => $label,
            'value' => $value,
            'currency' => in_array($currency, ['USD', 'RMB'], true) ? $currency : 'RMB',
            'qr_image_path' => $qrPath ?: null,
        ];
    }

    $pdo->prepare("UPDATE $table SET payment_links = ? WHERE id = ?")
        ->execute([json_encode(array_values($links), JSON_UNESCAPED_UNICODE), $partyId]);
    return true;
}

function balancesFetchCustomerReceivables(PDO $pdo, array $customerIds): array
{
    if (!$customerIds) {
        return [];
    }
    $hasSell = balancesTableHasColumn($pdo, 'order_items', 'sell_price');
    $expr = $hasSell
        ? "SUM(CASE WHEN oi.sell_price IS NOT NULL THEN oi.quantity * oi.sell_price ELSE COALESCE(oi.total_amount, oi.quantity * COALESCE(oi.unit_price, 0)) END)"
        : "SUM(COALESCE(oi.total_amount, oi.quantity * COALESCE(oi.unit_price, 0)))";
    $params = $customerIds;
    $sql = "SELECT o.customer_id, o.currency, COALESCE($expr, 0) as total_due
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        WHERE o.status NOT IN ('Draft','CustomerDeclined','CustomerDeclinedAfterAutoConfirm')
          AND o.customer_id IN (" . balancesIdPlaceholders($customerIds) . ")
        GROUP BY o.customer_id, o.currency";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(int) $row['customer_id']][(string) ($row['currency'] ?: 'USD')] = (float) $row['total_due'];
    }
    return $map;
}

function balancesFetchCustomerDeposits(PDO $pdo, array $customerIds): array
{
    if (!$customerIds || !balancesTableExists($pdo, 'customer_deposits')) {
        return [];
    }
    $ledgerDateJoin = '';
    $paymentDateExpr = 'DATE(cd.created_at)';
    if (balancesTableExists($pdo, 'balance_transactions')) {
        $ledgerDateJoin = "LEFT JOIN (
                SELECT source_id, MAX(transaction_date) as transaction_date
                FROM balance_transactions
                WHERE source_table = 'customer_deposits'
                GROUP BY source_id
            ) btd ON btd.source_id = cd.id";
        $paymentDateExpr = 'COALESCE(btd.transaction_date, DATE(cd.created_at))';
    }
    $sql = "SELECT cd.customer_id, cd.currency, SUM(cd.amount) as total_paid, MAX($paymentDateExpr) as last_payment_date
        FROM customer_deposits cd
        $ledgerDateJoin
        WHERE cd.customer_id IN (" . balancesIdPlaceholders($customerIds) . ")
        GROUP BY cd.customer_id, cd.currency";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($customerIds);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(int) $row['customer_id']][(string) ($row['currency'] ?: 'USD')] = [
            'total_paid' => (float) $row['total_paid'],
            'last_payment_date' => $row['last_payment_date'] ?: null,
        ];
    }
    return $map;
}

function balancesFetchSupplierPayments(PDO $pdo, array $supplierIds): array
{
    if (!$supplierIds || !balancesTableExists($pdo, 'supplier_payments')) {
        return [];
    }
    $invoiceExpr = balancesTableHasColumn($pdo, 'supplier_payments', 'invoice_amount')
        ? 'COALESCE(invoice_amount, amount)'
        : 'amount';
    $settlementExpr = balancesSupplierSettlementExpr($pdo);
    $ledgerDateJoin = '';
    $paymentDateExpr = 'DATE(sp.created_at)';
    if (balancesTableExists($pdo, 'balance_transactions')) {
        $ledgerDateJoin = "LEFT JOIN (
                SELECT source_id, MAX(transaction_date) as transaction_date
                FROM balance_transactions
                WHERE source_table = 'supplier_payments'
                GROUP BY source_id
            ) btd ON btd.source_id = sp.id";
        $paymentDateExpr = 'COALESCE(btd.transaction_date, DATE(sp.created_at))';
    }
    $sql = "SELECT sp.supplier_id, sp.currency,
            SUM(sp.amount) as total_paid,
            SUM($invoiceExpr) as total_due,
            SUM($settlementExpr) as total_settlement,
            MAX($paymentDateExpr) as last_payment_date
        FROM supplier_payments sp
        $ledgerDateJoin
        WHERE sp.supplier_id IN (" . balancesIdPlaceholders($supplierIds) . ")
        GROUP BY sp.supplier_id, sp.currency";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($supplierIds);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(int) $row['supplier_id']][(string) ($row['currency'] ?: 'USD')] = [
            'total_paid' => (float) $row['total_paid'],
            'total_due' => (float) $row['total_due'],
            'total_settlement' => (float) $row['total_settlement'],
            'last_payment_date' => $row['last_payment_date'] ?: null,
        ];
    }
    return $map;
}

function balancesFetchLedgerEffects(PDO $pdo, string $partyType, array $partyIds): array
{
    if (!$partyIds || !balancesTableExists($pdo, 'balance_transactions')) {
        return [];
    }
    $params = array_merge([$partyType], $partyIds);
    $sql = "SELECT party_id, currency,
            SUM(CASE WHEN direction = 'increase_balance' THEN amount ELSE 0 END) as due_delta,
            SUM(CASE WHEN direction = 'reduce_balance' THEN amount ELSE 0 END) as paid_delta,
            MAX(CASE WHEN transaction_type IN ('payment_received','payment_sent','deposit') THEN transaction_date ELSE NULL END) as last_payment_date
        FROM balance_transactions
        WHERE party_type = ?
          AND party_id IN (" . balancesIdPlaceholders($partyIds) . ")
          AND (source_table IS NULL OR source_table = '')
        GROUP BY party_id, currency";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(int) $row['party_id']][(string) ($row['currency'] ?: 'USD')] = [
            'due_delta' => (float) $row['due_delta'],
            'paid_delta' => (float) $row['paid_delta'],
            'last_payment_date' => $row['last_payment_date'] ?: null,
        ];
    }
    return $map;
}

function balancesBuildRows(array $parties, array $dueMap, array $paidMap, array $ledgerMap, ?string $currencyFilter, ?string $statusFilter, ?string $dateFrom, ?string $dateTo, string $partyType): array
{
    $currencies = $currencyFilter ? [$currencyFilter] : ['USD', 'RMB'];
    $rows = [];
    foreach ($parties as $party) {
        $partyId = (int) $party['id'];
        $partyHadActivity = false;
        foreach ($currencies as $currency) {
            $baseDue = 0.0;
            $basePaid = 0.0;
            $settlement = 0.0;
            $lastPaymentDate = null;

            if ($partyType === 'customer') {
                $baseDue = (float) ($dueMap[$partyId][$currency] ?? 0);
                $basePaid = (float) ($paidMap[$partyId][$currency]['total_paid'] ?? 0);
                $lastPaymentDate = $paidMap[$partyId][$currency]['last_payment_date'] ?? null;
            } else {
                $baseDue = (float) ($paidMap[$partyId][$currency]['total_due'] ?? 0);
                $basePaid = (float) ($paidMap[$partyId][$currency]['total_paid'] ?? 0);
                $settlement = (float) ($paidMap[$partyId][$currency]['total_settlement'] ?? 0);
                $lastPaymentDate = $paidMap[$partyId][$currency]['last_payment_date'] ?? null;
            }

            $ledgerDue = (float) ($ledgerMap[$partyId][$currency]['due_delta'] ?? 0);
            $ledgerPaid = (float) ($ledgerMap[$partyId][$currency]['paid_delta'] ?? 0);
            $ledgerLast = $ledgerMap[$partyId][$currency]['last_payment_date'] ?? null;
            if ($ledgerLast && (!$lastPaymentDate || $ledgerLast > $lastPaymentDate)) {
                $lastPaymentDate = $ledgerLast;
            }

            $totalDue = round($baseDue + $ledgerDue, 4);
            $totalPaid = round($basePaid + $ledgerPaid, 4);
            $currentBalance = round($totalDue - $totalPaid - $settlement, 4);
            $status = balancesStatusFor($currentBalance);
            $hasActivity = abs($totalDue) >= 0.005 || abs($totalPaid) >= 0.005 || abs($currentBalance) >= 0.005;
            $partyHadActivity = $partyHadActivity || $hasActivity;

            if ($statusFilter && $statusFilter !== $status) {
                continue;
            }
            if (!balancesDateInRange($lastPaymentDate, $dateFrom, $dateTo)) {
                continue;
            }
            if (!$currencyFilter && !$hasActivity) {
                continue;
            }

            $rows[] = [
                'party_type' => $partyType,
                'id' => $partyId,
                'name' => (string) ($party['name'] ?? ''),
                'code' => (string) ($party['code'] ?? ''),
                'phone' => (string) ($party['phone'] ?? ''),
                'currency' => $currency,
                'current_balance' => $currentBalance,
                'total_paid' => $totalPaid,
                'total_due' => $totalDue,
                'last_payment_date' => $lastPaymentDate,
                'status' => $status,
                'status_label' => balancesStatusLabel($status),
            ];
        }

        if (!$currencyFilter && !$partyHadActivity && !$statusFilter && !$dateFrom && !$dateTo) {
            $rows[] = [
                'party_type' => $partyType,
                'id' => $partyId,
                'name' => (string) ($party['name'] ?? ''),
                'code' => (string) ($party['code'] ?? ''),
                'phone' => (string) ($party['phone'] ?? ''),
                'currency' => 'USD',
                'current_balance' => 0,
                'total_paid' => 0,
                'total_due' => 0,
                'last_payment_date' => null,
                'status' => 'settled',
                'status_label' => balancesStatusLabel('settled'),
            ];
        }
    }
    return $rows;
}

function balancesPaymentsTodaySummary(PDO $pdo): array
{
    $today = date('Y-m-d');
    $summary = [
        'payments_received_today' => ['USD' => 0.0, 'RMB' => 0.0],
        'payments_sent_today' => ['USD' => 0.0, 'RMB' => 0.0],
    ];

    if (balancesTableExists($pdo, 'balance_transactions')) {
        $customerScope = clmsCustomerIdVisibilityClause($pdo, 'party_id', 'cvbtsum');
        $stmt = $pdo->prepare("SELECT party_type, transaction_type, currency, SUM(amount) as total
            FROM balance_transactions
            WHERE transaction_date = ?
              AND transaction_type IN ('payment_received', 'payment_sent', 'deposit')
              AND (party_type <> 'customer' OR {$customerScope['sql']})
            GROUP BY party_type, transaction_type, currency");
        $stmt->execute(array_merge([$today], $customerScope['params']));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $currency = balancesNormalizeCurrency($row['currency'] ?? 'RMB');
            if (($row['transaction_type'] ?? '') === 'payment_received'
                || (($row['transaction_type'] ?? '') === 'deposit' && ($row['party_type'] ?? '') === 'customer')) {
                $summary['payments_received_today'][$currency] += (float) $row['total'];
            } elseif (($row['transaction_type'] ?? '') === 'payment_sent'
                || (($row['transaction_type'] ?? '') === 'deposit' && ($row['party_type'] ?? '') === 'supplier')) {
                $summary['payments_sent_today'][$currency] += (float) $row['total'];
            }
        }
    }

    if (balancesTableExists($pdo, 'customer_deposits')) {
        $notLinked = balancesTableExists($pdo, 'balance_transactions')
            ? "AND NOT EXISTS (SELECT 1 FROM balance_transactions bt WHERE bt.source_table = 'customer_deposits' AND bt.source_id = cd.id)"
            : '';
        $customerScope = clmsCustomerIdVisibilityClause($pdo, 'cd.customer_id', 'cvcdsum');
        $stmt = $pdo->prepare("SELECT currency, SUM(amount) as total FROM customer_deposits cd WHERE DATE(cd.created_at) = ? AND {$customerScope['sql']} $notLinked GROUP BY currency");
        $stmt->execute(array_merge([$today], $customerScope['params']));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $currency = balancesNormalizeCurrency($row['currency'] ?? 'RMB');
            $summary['payments_received_today'][$currency] += (float) $row['total'];
        }
    }

    if (balancesTableExists($pdo, 'supplier_payments')) {
        $notLinked = balancesTableExists($pdo, 'balance_transactions')
            ? "AND NOT EXISTS (SELECT 1 FROM balance_transactions bt WHERE bt.source_table = 'supplier_payments' AND bt.source_id = sp.id)"
            : '';
        $stmt = $pdo->prepare("SELECT currency, SUM(amount) as total FROM supplier_payments sp WHERE DATE(sp.created_at) = ? $notLinked GROUP BY currency");
        $stmt->execute([$today]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $currency = balancesNormalizeCurrency($row['currency'] ?? 'RMB');
            $summary['payments_sent_today'][$currency] += (float) $row['total'];
        }
    }

    return $summary;
}

function balancesBuildOverview(PDO $pdo, array $filters): array
{
    $q = trim((string) ($filters['q'] ?? ''));
    $partyType = balancesNormalizePartyType($filters['party_type'] ?? null);
    $currency = balancesCurrencyFilter($filters['currency'] ?? null);
    $status = strtolower(trim((string) ($filters['status'] ?? '')));
    $status = in_array($status, ['due', 'credit', 'settled'], true) ? $status : null;
    $dateFrom = trim((string) ($filters['date_from'] ?? '')) ?: null;
    $dateTo = trim((string) ($filters['date_to'] ?? '')) ?: null;

    $customers = [];
    $suppliers = [];

    if (!$partyType || $partyType === 'customer') {
        $customerParties = balancesFetchParties($pdo, 'customer', $q);
        $customerIds = array_map(static fn($row): int => (int) $row['id'], $customerParties);
        $customers = balancesBuildRows(
            $customerParties,
            balancesFetchCustomerReceivables($pdo, $customerIds),
            balancesFetchCustomerDeposits($pdo, $customerIds),
            balancesFetchLedgerEffects($pdo, 'customer', $customerIds),
            $currency,
            $status,
            $dateFrom,
            $dateTo,
            'customer'
        );
    }

    if (!$partyType || $partyType === 'supplier') {
        $supplierParties = balancesFetchParties($pdo, 'supplier', $q);
        $supplierIds = array_map(static fn($row): int => (int) $row['id'], $supplierParties);
        $suppliers = balancesBuildRows(
            $supplierParties,
            [],
            balancesFetchSupplierPayments($pdo, $supplierIds),
            balancesFetchLedgerEffects($pdo, 'supplier', $supplierIds),
            $currency,
            $status,
            $dateFrom,
            $dateTo,
            'supplier'
        );
    }

    $summary = [
        'customer_balances' => ['USD' => 0.0, 'RMB' => 0.0],
        'supplier_balances' => ['USD' => 0.0, 'RMB' => 0.0],
    ];
    foreach ($customers as $row) {
        if ((float) $row['current_balance'] > 0) {
            $summary['customer_balances'][$row['currency']] += (float) $row['current_balance'];
        }
    }
    foreach ($suppliers as $row) {
        if ((float) $row['current_balance'] > 0) {
            $summary['supplier_balances'][$row['currency']] += (float) $row['current_balance'];
        }
    }
    $summary = array_merge($summary, balancesPaymentsTodaySummary($pdo));

    return [
        'customers' => $customers,
        'suppliers' => $suppliers,
        'summary' => $summary,
    ];
}

function balancesTransactionUnionSql(PDO $pdo): string
{
    $selects = [];
    $customerPhoneExpr = balancesTableHasColumn($pdo, 'customers', 'phone') ? 'c.phone' : 'NULL';
    $supplierPhoneExpr = balancesTableHasColumn($pdo, 'suppliers', 'phone') ? 's.phone' : 'NULL';
    $btAccountLabel = balancesTableHasColumn($pdo, 'balance_transactions', 'payment_account_label') ? 'bt.payment_account_label' : 'NULL';
    $btAccountValue = balancesTableHasColumn($pdo, 'balance_transactions', 'payment_account_value') ? 'bt.payment_account_value' : 'NULL';
    $btAccountQrPath = balancesTableHasColumn($pdo, 'balance_transactions', 'payment_account_qr_path') ? 'bt.payment_account_qr_path' : 'NULL';
    $btOrderId = balancesTableHasColumn($pdo, 'balance_transactions', 'order_id') ? 'bt.order_id' : 'NULL';
    $btOrderReference = balancesTableHasColumn($pdo, 'balance_transactions', 'order_reference') ? "COALESCE(bt.order_reference, CONCAT('#', bt.order_id))" : 'NULL';

    if (balancesTableExists($pdo, 'balance_transactions')) {
        $selects[] = "SELECT bt.id, " . balancesUnionTextExpr('bt.party_type') . " as party_type, bt.party_id,
                " . balancesUnionTextExpr("CASE WHEN bt.party_type = 'customer' THEN c.name ELSE s.name END") . " as party_name,
                " . balancesUnionTextExpr("CASE WHEN bt.party_type = 'customer' THEN c.code ELSE s.code END") . " as party_code,
                " . balancesUnionTextExpr("CASE WHEN bt.party_type = 'customer' THEN $customerPhoneExpr ELSE $supplierPhoneExpr END") . " as party_phone,
                " . balancesUnionTextExpr('bt.transaction_type') . " as transaction_type,
                " . balancesUnionTextExpr('bt.direction') . " as direction,
                bt.amount, " . balancesUnionTextExpr('bt.currency') . " as currency,
                " . balancesUnionTextExpr('bt.payment_method') . " as payment_method,
                " . balancesUnionTextExpr($btAccountLabel) . " as payment_account_label,
                " . balancesUnionTextExpr($btAccountValue) . " as payment_account_value,
                " . balancesUnionTextExpr($btAccountQrPath) . " as payment_account_qr_path,
                $btOrderId as order_id, " . balancesUnionTextExpr($btOrderReference) . " as order_reference,
                " . balancesUnionTextExpr('bt.reference_number') . " as reference_number,
                " . balancesUnionTextExpr('bt.notes') . " as notes,
                bt.created_by, " . balancesUnionTextExpr('u.full_name') . " as created_by_name,
                bt.transaction_date, bt.created_at, " . balancesUnionTextExpr('bt.source_table') . " as source_table, bt.source_id
            FROM balance_transactions bt
            LEFT JOIN customers c ON bt.party_type = 'customer' AND bt.party_id = c.id
            LEFT JOIN suppliers s ON bt.party_type = 'supplier' AND bt.party_id = s.id
            LEFT JOIN users u ON bt.created_by = u.id";
    }

    if (balancesTableExists($pdo, 'customer_deposits')) {
        $cdOrderId = balancesTableHasColumn($pdo, 'customer_deposits', 'order_id') ? 'cd.order_id' : 'NULL';
        $notLinked = balancesTableExists($pdo, 'balance_transactions')
            ? "WHERE NOT EXISTS (SELECT 1 FROM balance_transactions bt WHERE bt.source_table = 'customer_deposits' AND bt.source_id = cd.id)"
            : '';
        $selects[] = "SELECT cd.id, " . balancesUnionTextExpr("'customer'") . " as party_type, cd.customer_id as party_id,
                " . balancesUnionTextExpr('c.name') . " as party_name,
                " . balancesUnionTextExpr('c.code') . " as party_code,
                " . balancesUnionTextExpr($customerPhoneExpr) . " as party_phone,
                " . balancesUnionTextExpr("'payment_received'") . " as transaction_type,
                " . balancesUnionTextExpr("'reduce_balance'") . " as direction,
                cd.amount, " . balancesUnionTextExpr('cd.currency') . " as currency,
                " . balancesUnionTextExpr('cd.payment_method') . " as payment_method,
                " . balancesUnionTextExpr('NULL') . " as payment_account_label,
                " . balancesUnionTextExpr('NULL') . " as payment_account_value,
                " . balancesUnionTextExpr('NULL') . " as payment_account_qr_path, $cdOrderId as order_id,
                " . balancesUnionTextExpr("CASE WHEN $cdOrderId IS NULL THEN NULL ELSE CONCAT('#', $cdOrderId) END") . " as order_reference,
                " . balancesUnionTextExpr('cd.reference_no') . " as reference_number,
                " . balancesUnionTextExpr('cd.notes') . " as notes,
                cd.created_by, " . balancesUnionTextExpr('u.full_name') . " as created_by_name,
                DATE(cd.created_at) as transaction_date, cd.created_at,
                " . balancesUnionTextExpr("'customer_deposits'") . " as source_table, cd.id as source_id
            FROM customer_deposits cd
            JOIN customers c ON cd.customer_id = c.id
            LEFT JOIN users u ON cd.created_by = u.id
            $notLinked";
    }

    if (balancesTableExists($pdo, 'supplier_payments')) {
        $paymentMethod = balancesTableHasColumn($pdo, 'supplier_payments', 'payment_channel')
            ? 'COALESCE(sp.payment_channel, sp.payment_type)'
            : 'sp.payment_type';
        $createdBy = balancesTableHasColumn($pdo, 'supplier_payments', 'marked_by')
            ? 'sp.marked_by'
            : 'NULL';
        $paymentAccountLabel = balancesTableHasColumn($pdo, 'supplier_payments', 'payment_account_label')
            ? 'sp.payment_account_label'
            : 'NULL';
        $paymentAccountValue = balancesTableHasColumn($pdo, 'supplier_payments', 'payment_account_value')
            ? 'sp.payment_account_value'
            : 'NULL';
        $paymentAccountQrPath = balancesTableHasColumn($pdo, 'supplier_payments', 'payment_account_qr_path')
            ? 'sp.payment_account_qr_path'
            : 'NULL';
        $notLinked = balancesTableExists($pdo, 'balance_transactions')
            ? "WHERE NOT EXISTS (SELECT 1 FROM balance_transactions bt WHERE bt.source_table = 'supplier_payments' AND bt.source_id = sp.id)"
            : '';
        $selects[] = "SELECT sp.id, " . balancesUnionTextExpr("'supplier'") . " as party_type, sp.supplier_id as party_id,
                " . balancesUnionTextExpr('s.name') . " as party_name,
                " . balancesUnionTextExpr('s.code') . " as party_code,
                " . balancesUnionTextExpr($supplierPhoneExpr) . " as party_phone,
                " . balancesUnionTextExpr("'payment_sent'") . " as transaction_type,
                " . balancesUnionTextExpr("'reduce_balance'") . " as direction,
                sp.amount, " . balancesUnionTextExpr('sp.currency') . " as currency,
                " . balancesUnionTextExpr($paymentMethod) . " as payment_method,
                " . balancesUnionTextExpr($paymentAccountLabel) . " as payment_account_label,
                " . balancesUnionTextExpr($paymentAccountValue) . " as payment_account_value,
                " . balancesUnionTextExpr($paymentAccountQrPath) . " as payment_account_qr_path, sp.order_id as order_id,
                " . balancesUnionTextExpr("CASE WHEN sp.order_id IS NULL THEN NULL ELSE CONCAT('#', sp.order_id) END") . " as order_reference,
                " . balancesUnionTextExpr('NULL') . " as reference_number,
                " . balancesUnionTextExpr('sp.notes') . " as notes,
                $createdBy as created_by,
                " . balancesUnionTextExpr('u.full_name') . " as created_by_name, DATE(sp.created_at) as transaction_date,
                sp.created_at, " . balancesUnionTextExpr("'supplier_payments'") . " as source_table, sp.id as source_id
            FROM supplier_payments sp
            JOIN suppliers s ON sp.supplier_id = s.id
            LEFT JOIN users u ON $createdBy = u.id
            $notLinked";
    }

    return implode(' UNION ALL ', $selects);
}

function balancesListTransactions(PDO $pdo, array $filters): array
{
    $union = balancesTransactionUnionSql($pdo);
    if ($union === '') {
        return [];
    }

    $params = [];
    $sql = "SELECT * FROM ($union) tx WHERE 1=1";
    $customerScope = clmsCustomerIdVisibilityClause($pdo, 'tx.party_id', 'cvtx');
    $sql .= " AND (tx.party_type <> 'customer' OR {$customerScope['sql']})";
    $params = array_merge($params, $customerScope['params']);
    $partyType = balancesNormalizePartyType($filters['party_type'] ?? null);
    if ($partyType) {
        $sql .= ' AND tx.party_type = ?';
        $params[] = $partyType;
    }
    $partyId = !empty($filters['party_id']) ? (int) $filters['party_id'] : 0;
    if ($partyId > 0) {
        $sql .= ' AND tx.party_id = ?';
        $params[] = $partyId;
    }
    $transactionId = !empty($filters['transaction_id']) ? (int) $filters['transaction_id'] : 0;
    if ($transactionId > 0) {
        $sql .= ' AND tx.id = ?';
        $params[] = $transactionId;
    }
    $transactionType = strtolower(trim((string) ($filters['transaction_type'] ?? '')));
    if (in_array($transactionType, ['payment_received', 'payment_sent', 'deposit', 'invoice', 'adjustment', 'refund', 'other'], true)) {
        $sql .= ' AND tx.transaction_type = ?';
        $params[] = $transactionType;
    }
    $orderId = !empty($filters['order_id']) ? (int) $filters['order_id'] : 0;
    if ($orderId > 0) {
        $sql .= ' AND tx.order_id = ?';
        $params[] = $orderId;
    }
    $currency = balancesCurrencyFilter($filters['currency'] ?? null);
    if ($currency) {
        $sql .= ' AND tx.currency = ?';
        $params[] = $currency;
    }
    $paymentMethod = trim((string) ($filters['payment_method'] ?? ''));
    if ($paymentMethod !== '') {
        $sql .= ' AND tx.payment_method = ?';
        $params[] = $paymentMethod;
    }
    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    if ($dateFrom !== '') {
        $sql .= ' AND tx.transaction_date >= ?';
        $params[] = $dateFrom;
    }
    $dateTo = trim((string) ($filters['date_to'] ?? ''));
    if ($dateTo !== '') {
        $sql .= ' AND tx.transaction_date <= ?';
        $params[] = $dateTo;
    }
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . preg_replace('/\s+/', '%', $q) . '%';
        $sql .= " AND (
            CONVERT(COALESCE(tx.party_name, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?
            OR CONVERT(COALESCE(tx.party_code, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?
            OR CONVERT(COALESCE(tx.party_phone, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?
            OR CONVERT(COALESCE(tx.payment_account_label, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?
            OR CONVERT(COALESCE(tx.payment_account_value, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?
            OR CONVERT(COALESCE(tx.order_reference, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?
            OR CONVERT(COALESCE(tx.reference_number, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?
            OR CONVERT(COALESCE(tx.notes, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?
        )";
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
    }
    $sql .= ' ORDER BY tx.transaction_date DESC, tx.created_at DESC, tx.id DESC LIMIT 500';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['document_type'] = balancesDocumentTypeForRow($row);
        $row['document_number'] = balancesDocumentNumberForRow($row);
    }
    unset($row);
    return $rows;
}

function balancesValidateParty(PDO $pdo, string $partyType, int $partyId): array
{
    $table = $partyType === 'customer' ? 'customers' : 'suppliers';
    if ($partyType === 'customer') {
        clmsRequireCustomerAccess($pdo, $partyId);
    }
    $stmt = $pdo->prepare("SELECT id, name, code FROM $table WHERE id = ?");
    $stmt->execute([$partyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        jsonError($partyType === 'customer' ? 'Customer not found' : 'Supplier not found', 404);
    }
    return $row;
}

function balancesOrderReference(array $order): string
{
    $id = (int) ($order['id'] ?? 0);
    return $id > 0 ? ('#' . $id) : '';
}

function balancesFetchOrderContext(PDO $pdo, int $orderId): array
{
    if ($orderId <= 0) {
        jsonError('Missing order', 400, ['order_id' => 'Missing order']);
    }
    if (!balancesCurrentUserCanUseOrderLinks()) {
        jsonError('You do not have permission', 403);
    }

    $scope = clmsCustomerVisibilityClause($pdo, 'c');
    $stmt = $pdo->prepare("SELECT o.id, o.customer_id, o.supplier_id, o.currency, o.status, c.name as customer_name, s.name as supplier_name
        FROM orders o
        JOIN customers c ON o.customer_id = c.id
        LEFT JOIN suppliers s ON o.supplier_id = s.id
        WHERE o.id = ? AND {$scope['sql']}");
    $stmt->execute(array_merge([$orderId], $scope['params']));
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        jsonError('Invalid order', 404, ['order_id' => 'Invalid order']);
    }
    clmsRequireCustomerAccess($pdo, (int) $order['customer_id']);

    return [
        'order_id' => (int) $order['id'],
        'order_reference' => balancesOrderReference($order),
        'party_type' => 'customer',
        'party_id' => (int) $order['customer_id'],
        'party_name' => (string) ($order['customer_name'] ?? ''),
        'supplier_id' => !empty($order['supplier_id']) ? (int) $order['supplier_id'] : null,
        'supplier_name' => (string) ($order['supplier_name'] ?? ''),
        'currency' => balancesNormalizeCurrency($order['currency'] ?? 'RMB'),
        'transaction_type' => 'deposit',
        'transaction_date' => date('Y-m-d'),
    ];
}

function balancesValidateOrderLink(PDO $pdo, ?int $orderId, string $partyType, int $partyId): ?array
{
    if (!$orderId || $orderId <= 0) {
        return null;
    }
    if (!balancesCurrentUserCanUseOrderLinks()) {
        jsonError('You do not have permission', 403);
    }
    $stmt = $pdo->prepare("SELECT id, customer_id, supplier_id, currency FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        jsonError('Invalid order', 404, ['order_id' => 'Invalid order']);
    }
    clmsRequireCustomerAccess($pdo, (int) $order['customer_id']);
    $expectedPartyId = $partyType === 'customer' ? (int) $order['customer_id'] : (int) ($order['supplier_id'] ?? 0);
    if ($expectedPartyId <= 0 || $expectedPartyId !== $partyId) {
        jsonError('Selected party does not match the linked order', 400, ['party_id' => 'Selected party does not match the linked order']);
    }
    return $order;
}

function balancesInsertCustomerDeposit(PDO $pdo, int $customerId, float $amount, string $currency, ?string $paymentMethod, ?string $referenceNumber, ?string $notes, int $userId, ?int $orderId = null): int
{
    $hasOrderId = balancesTableHasColumn($pdo, 'customer_deposits', 'order_id');
    if ($hasOrderId) {
        $pdo->prepare("INSERT INTO customer_deposits (customer_id, order_id, amount, currency, payment_method, reference_no, notes, created_by) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$customerId, $orderId, $amount, $currency, $paymentMethod, $referenceNumber, $notes, $userId]);
    } else {
        $pdo->prepare("INSERT INTO customer_deposits (customer_id, amount, currency, payment_method, reference_no, notes, created_by) VALUES (?,?,?,?,?,?,?)")
            ->execute([$customerId, $amount, $currency, $paymentMethod, $referenceNumber, $notes, $userId]);
    }
    return (int) $pdo->lastInsertId();
}

function balancesInsertSupplierPayment(PDO $pdo, int $supplierId, float $amount, string $currency, ?string $paymentMethod, ?string $paymentAccountLabel, ?string $paymentAccountValue, ?string $paymentAccountQrPath, ?string $notes, int $userId, ?int $orderId = null): int
{
    $columns = ['supplier_id', 'order_id', 'amount', 'currency', 'payment_type', 'notes'];
    $values = ['?', '?', '?', '?', '?', '?'];
    $params = [$supplierId, $orderId, $amount, $currency, 'partial', $notes];

    if (balancesTableHasColumn($pdo, 'supplier_payments', 'invoice_amount')) {
        $columns[] = 'invoice_amount';
        $values[] = '?';
        $params[] = 0;
    }
    if (balancesTableHasColumn($pdo, 'supplier_payments', 'discount_amount')) {
        $columns[] = 'discount_amount';
        $values[] = '?';
        $params[] = 0;
    }
    if (balancesTableHasColumn($pdo, 'supplier_payments', 'marked_full_payment')) {
        $columns[] = 'marked_full_payment';
        $values[] = '?';
        $params[] = 0;
    }
    if (balancesTableHasColumn($pdo, 'supplier_payments', 'marked_by')) {
        $columns[] = 'marked_by';
        $values[] = '?';
        $params[] = null;
    }
    if (balancesTableHasColumn($pdo, 'supplier_payments', 'payment_channel')) {
        $columns[] = 'payment_channel';
        $values[] = '?';
        $params[] = in_array($paymentMethod, ['WeChat', 'Alipay', 'Bank Transfer'], true) ? $paymentMethod : null;
    }
    if (balancesTableHasColumn($pdo, 'supplier_payments', 'payment_account_label')) {
        $columns[] = 'payment_account_label';
        $values[] = '?';
        $params[] = $paymentAccountLabel;
    }
    if (balancesTableHasColumn($pdo, 'supplier_payments', 'payment_account_value')) {
        $columns[] = 'payment_account_value';
        $values[] = '?';
        $params[] = $paymentAccountValue;
    }
    if (balancesTableHasColumn($pdo, 'supplier_payments', 'payment_account_qr_path')) {
        $columns[] = 'payment_account_qr_path';
        $values[] = '?';
        $params[] = $paymentAccountQrPath;
    }
    if (balancesTableHasColumn($pdo, 'supplier_payments', 'settlement_delta')) {
        $columns[] = 'settlement_delta';
        $values[] = '?';
        $params[] = 0;
    }
    if (balancesTableHasColumn($pdo, 'supplier_payments', 'settlement_mode')) {
        $columns[] = 'settlement_mode';
        $values[] = '?';
        $params[] = null;
    }
    if (balancesTableHasColumn($pdo, 'supplier_payments', 'settlement_note')) {
        $columns[] = 'settlement_note';
        $values[] = '?';
        $params[] = null;
    }

    $pdo->prepare("INSERT INTO supplier_payments (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ")")
        ->execute($params);
    return (int) $pdo->lastInsertId();
}

function balancesCreateTransaction(PDO $pdo, array $input): array
{
    if (!balancesTableExists($pdo, 'balance_transactions')) {
        jsonError('Balance transaction table is missing. Run migrations first.', 500);
    }

    $errors = [];
    $partyType = balancesNormalizePartyType($input['party_type'] ?? null);
    if (!$partyType) {
        $errors['party_type'] = 'Party type must be customer or supplier';
    }
    $partyId = (int) ($input['party_id'] ?? 0);
    if ($partyId <= 0) {
        $errors['party_id'] = 'Party is required';
    }
    if ($errors) {
        jsonError('Please complete the highlighted fields before saving.', 400, $errors);
    }
    balancesValidateParty($pdo, $partyType, $partyId);

    $transactionType = balancesNormalizeTransactionType($input['transaction_type'] ?? null);
    $direction = balancesNormalizeDirection($partyType, $transactionType, $input['direction'] ?? null);
    $amount = (float) ($input['amount'] ?? 0);
    if ($amount <= 0) {
        $errors['amount'] = 'Amount must be positive';
    }
    $currency = balancesNormalizeCurrency($input['currency'] ?? 'RMB');
    $transactionDate = balancesNormalizeDate($input['transaction_date'] ?? null);
    $paymentMethod = trim((string) ($input['payment_method'] ?? '')) ?: null;
    if ($transactionType === 'deposit' && $paymentMethod === null) {
        $errors['payment_method'] = 'Payment method is required';
    }
    if ($paymentMethod !== null && mb_strlen($paymentMethod) > 50) {
        $paymentMethod = mb_substr($paymentMethod, 0, 50);
    }
    $orderId = !empty($input['order_id']) ? (int) $input['order_id'] : null;
    $linkedOrder = balancesValidateOrderLink($pdo, $orderId, $partyType, $partyId);
    $orderReference = trim((string) ($input['order_reference'] ?? '')) ?: null;
    if ($linkedOrder && $orderReference === null) {
        $orderReference = balancesOrderReference($linkedOrder);
    }
    if ($orderReference !== null && mb_strlen($orderReference) > 100) {
        $orderReference = mb_substr($orderReference, 0, 100);
    }
    if ($errors) {
        jsonError('Please complete the highlighted fields before saving.', 400, $errors);
    }
    $paymentAccountLabel = trim((string) ($input['payment_account_label'] ?? '')) ?: null;
    if ($paymentAccountLabel !== null && mb_strlen($paymentAccountLabel) > 150) {
        $paymentAccountLabel = mb_substr($paymentAccountLabel, 0, 150);
    }
    $paymentAccountValue = trim((string) ($input['payment_account_value'] ?? '')) ?: null;
    if ($paymentAccountValue !== null && mb_strlen($paymentAccountValue) > 255) {
        $paymentAccountValue = mb_substr($paymentAccountValue, 0, 255);
    }
    $paymentAccountQrPath = trim((string) ($input['payment_account_qr_path'] ?? '')) ?: null;
    if ($paymentAccountQrPath !== null) {
        if (str_contains($paymentAccountQrPath, '..')) {
            jsonError('Invalid payment account QR path', 400);
        }
        if (mb_strlen($paymentAccountQrPath) > 255) {
            $paymentAccountQrPath = mb_substr($paymentAccountQrPath, 0, 255);
        }
    }
    $referenceNumber = trim((string) ($input['reference_number'] ?? '')) ?: null;
    if ($referenceNumber !== null && mb_strlen($referenceNumber) > 100) {
        $referenceNumber = mb_substr($referenceNumber, 0, 100);
    }
    $notes = trim((string) ($input['notes'] ?? '')) ?: null;
    $userId = getAuthUserId() ?? 0;

    $sourceTable = null;
    $sourceId = null;

    $pdo->beginTransaction();
    try {
        $accountWasSaved = balancesAppendPaymentAccountIfMissing(
            $pdo,
            $partyType,
            $partyId,
            $paymentAccountLabel,
            $paymentAccountValue,
            $paymentMethod,
            $currency,
            $paymentAccountQrPath
        );

        if ($partyType === 'customer' && in_array($transactionType, ['payment_received', 'deposit'], true)) {
            $sourceTable = 'customer_deposits';
            $sourceId = balancesInsertCustomerDeposit($pdo, $partyId, $amount, $currency, $paymentMethod, $referenceNumber, $notes, $userId, $orderId);
        } elseif ($partyType === 'supplier' && in_array($transactionType, ['payment_sent', 'deposit'], true)) {
            $sourceTable = 'supplier_payments';
            $sourceId = balancesInsertSupplierPayment($pdo, $partyId, $amount, $currency, $paymentMethod, $paymentAccountLabel, $paymentAccountValue, $paymentAccountQrPath, $notes, $userId, $orderId);
        }

        $columns = ['party_type', 'party_id', 'transaction_type', 'direction', 'amount', 'currency', 'payment_method'];
        $values = ['?', '?', '?', '?', '?', '?', '?'];
        $params = [
            $partyType,
            $partyId,
            $transactionType,
            $direction,
            $amount,
            $currency,
            $paymentMethod,
        ];
        if (balancesTableHasColumn($pdo, 'balance_transactions', 'order_id')) {
            $columns[] = 'order_id';
            $values[] = '?';
            $params[] = $orderId;
        }
        if (balancesTableHasColumn($pdo, 'balance_transactions', 'order_reference')) {
            $columns[] = 'order_reference';
            $values[] = '?';
            $params[] = $orderReference;
        }
        if (balancesTableHasColumn($pdo, 'balance_transactions', 'payment_account_label')) {
            $columns[] = 'payment_account_label';
            $values[] = '?';
            $params[] = $paymentAccountLabel;
        }
        if (balancesTableHasColumn($pdo, 'balance_transactions', 'payment_account_value')) {
            $columns[] = 'payment_account_value';
            $values[] = '?';
            $params[] = $paymentAccountValue;
        }
        if (balancesTableHasColumn($pdo, 'balance_transactions', 'payment_account_qr_path')) {
            $columns[] = 'payment_account_qr_path';
            $values[] = '?';
            $params[] = $paymentAccountQrPath;
        }
        array_push($columns, 'reference_number', 'notes', 'created_by', 'transaction_date', 'source_table', 'source_id');
        array_push($values, '?', '?', '?', '?', '?', '?');
        array_push($params, $referenceNumber, $notes, $userId ?: null, $transactionDate, $sourceTable, $sourceId);

        $stmt = $pdo->prepare("INSERT INTO balance_transactions (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ")");
        $stmt->execute($params);
        $newId = (int) $pdo->lastInsertId();
        $pdo->commit();
        logClms('balance_transaction', [
            'transaction_id' => $newId,
            'party_type' => $partyType,
            'party_id' => $partyId,
            'transaction_type' => $transactionType,
            'direction' => $direction,
            'amount' => $amount,
            'currency' => $currency,
            'order_id' => $orderId,
            'payment_account_saved' => $accountWasSaved,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $rows = balancesListTransactions($pdo, ['transaction_id' => $newId]);
    if ($rows) {
        return $rows[0];
    }
    $stmt = $pdo->prepare("SELECT * FROM balance_transactions WHERE id = ?");
    $stmt->execute([$newId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if ($row) {
        $row['document_type'] = balancesDocumentTypeForRow($row);
        $row['document_number'] = balancesDocumentNumberForRow($row);
    }
    return $row;
}

function balancesExportCsv(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array_map('clmsT', $headers));
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();
    requireAuth();
    if (!balancesCurrentUserCanAccess()) {
        jsonError('You do not have permission', 403);
    }

    if ($method === 'GET') {
        if ($id === 'order-context') {
            jsonResponse(['data' => balancesFetchOrderContext($pdo, (int) ($_GET['order_id'] ?? 0))]);
        }

        if ($id === 'accounts') {
            $partyType = balancesNormalizePartyType($_GET['party_type'] ?? null);
            $partyId = (int) ($_GET['party_id'] ?? 0);
            if (!$partyType || $partyId <= 0) {
                jsonError('Party is required', 400);
            }
            balancesValidateParty($pdo, $partyType, $partyId);
            jsonResponse(['data' => balancesFetchPartyPaymentAccounts($pdo, $partyType, $partyId)]);
        }

        if ($id === 'transactions') {
            jsonResponse(['data' => balancesListTransactions($pdo, $_GET)]);
        }

        if ($id === 'export') {
            $dataset = strtolower(trim((string) ($_GET['dataset'] ?? 'transactions')));
            if (in_array($dataset, ['customers', 'suppliers'], true)) {
                $overview = balancesBuildOverview($pdo, $_GET);
                $rows = [];
                foreach ($overview[$dataset] as $row) {
                    $rows[] = [
                        $row['name'],
                        $row['phone'],
                        $row['currency'],
                        format_display_amount($row['current_balance'], 2),
                        format_display_amount($row['total_paid'], 2),
                        format_display_amount($row['total_due'], 2),
                        $row['last_payment_date'] ?: '',
                        clmsT($row['status_label']),
                    ];
                }
                balancesExportCsv(
                    $dataset . '_balances_' . date('Y-m-d') . '.csv',
                    ['Name', 'Phone', 'Currency', 'Current Balance', 'Total Paid', 'Total Due', 'Last Payment Date', 'Status'],
                    $rows
                );
            }

            if ($dataset === 'documents') {
                $rows = [];
                foreach (balancesListTransactions($pdo, $_GET) as $row) {
                    $rows[] = [
                        $row['document_number'],
                        clmsT($row['document_type']),
                        $row['transaction_date'],
                        clmsT($row['party_type'] === 'customer' ? 'Customer' : 'Supplier'),
                        $row['party_name'],
                        format_display_amount($row['amount'], 2),
                        $row['currency'],
                        $row['payment_method'],
                        $row['order_reference'],
                        $row['reference_number'],
                        $row['created_by_name'],
                    ];
                }
                balancesExportCsv(
                    'balance_documents_' . date('Y-m-d') . '.csv',
                    ['Document No.', 'Document Type', 'Date', 'Type', 'Name', 'Amount', 'Currency', 'Payment Method', 'Linked Order', 'Reference Number', 'Recorded By'],
                    $rows
                );
            }

            $rows = [];
            foreach (balancesListTransactions($pdo, $_GET) as $row) {
                $rows[] = [
                    $row['transaction_date'],
                    clmsT($row['party_type'] === 'customer' ? 'Customer' : 'Supplier'),
                    $row['party_name'],
                    clmsT(str_replace('_', ' ', ucwords($row['transaction_type'], '_'))),
                    format_display_amount($row['amount'], 2),
                    $row['currency'],
                    $row['payment_method'],
                    $row['payment_account_value'],
                    $row['order_reference'],
                    $row['reference_number'],
                    $row['created_by_name'],
                    $row['notes'],
                ];
            }
            balancesExportCsv(
                'balance_transactions_' . date('Y-m-d') . '.csv',
                ['Date', 'Type', 'Name', 'Transaction Type', 'Amount', 'Currency', 'Payment Method', 'Account Number', 'Linked Order', 'Reference Number', 'Recorded By', 'Notes'],
                $rows
            );
        }

        jsonResponse(['data' => balancesBuildOverview($pdo, $_GET)]);
    }

    if ($method === 'POST' && ($id === 'transactions' || $id === null)) {
        $row = balancesCreateTransaction($pdo, $input);
        jsonResponse(['data' => $row], 201);
    }

    jsonError('Method not allowed', 405);
};
