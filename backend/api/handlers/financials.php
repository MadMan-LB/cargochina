<?php

/**
 * Financials API - profit, balances, outstanding
 * Roles: ChinaAdmin, LebanonAdmin, SuperAdmin
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();
    if (!getAuthUserId()) jsonError('Unauthorized', 401);
    if (!hasAnyRole(['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'])) jsonError('Forbidden', 403);

    if ($method !== 'GET') jsonError('Method not allowed', 405);

    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo = $_GET['date_to'] ?? null;
    $customerId = $_GET['customer_id'] ?? null;
    $supplierId = $_GET['supplier_id'] ?? null;
    $statusParam = $_GET['status'] ?? null;
    $statuses = is_array($statusParam) ? array_values(array_filter($statusParam)) : ($statusParam ? [$statusParam] : []);
    $statusMode = strtolower(trim((string) ($_GET['status_mode'] ?? 'include')));
    $statusMode = $statusMode === 'exclude' ? 'exclude' : 'include';
    $defaultExcludedStatuses = ['Draft', 'CustomerDeclined'];

    if ($id === 'balances') {
        $customers = [];
        $chkDep = @$pdo->query("SHOW TABLES LIKE 'customer_deposits'");
        $hasDeposits = $chkDep && $chkDep->rowCount() > 0;
        $chkSell = @$pdo->query("SHOW COLUMNS FROM order_items LIKE 'sell_price'");
        $hasSell = $chkSell && $chkSell->rowCount() > 0;
        $depSubq = $hasDeposits ? "(SELECT COALESCE(SUM(amount), 0) FROM customer_deposits WHERE customer_id = c.id)" : "0";
        $custSql = "SELECT c.id, c.name, c.code, $depSubq as total_deposits FROM customers c";
        $custParams = [];
        if ($customerId) {
            $custSql .= " WHERE c.id = ?";
            $custParams[] = $customerId;
        }
        $stmt = $custParams ? $pdo->prepare($custSql) : $pdo->query($custSql);
        if ($custParams) {
            $stmt->execute($custParams);
        }
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $custId = $r['id'];
            $deposits = (float) ($r['total_deposits'] ?? 0);
            $receivableExpr = $hasSell
                ? "SUM(oi.quantity * COALESCE(oi.sell_price, oi.unit_price, 0))"
                : "SUM(COALESCE(oi.total_amount, oi.quantity * COALESCE(oi.unit_price, 0)))";
            $orderTotal = $pdo->prepare("SELECT COALESCE($receivableExpr, 0) FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE o.customer_id = ? AND o.status NOT IN ('Draft','CustomerDeclined')");
            $orderTotal->execute([$custId]);
            $receivable = (float) $orderTotal->fetchColumn();
            $customers[] = ['id' => $custId, 'name' => $r['name'], 'code' => $r['code'], 'deposits' => $deposits, 'receivable' => $receivable, 'balance' => $deposits - $receivable];
        }
        $suppliers = [];
        $suppSql = "SELECT s.id, s.name, s.code FROM suppliers s";
        $suppParams = [];
        if ($supplierId) {
            $suppSql .= " WHERE s.id = ?";
            $suppParams[] = $supplierId;
        }
        $chkPay = @$pdo->query("SHOW TABLES LIKE 'supplier_payments'");
        $hasPayments = $chkPay && $chkPay->rowCount() > 0;
        if ($hasPayments) {
            $stmt = $suppParams ? $pdo->prepare($suppSql) : $pdo->query($suppSql);
            if ($suppParams) {
                $stmt->execute($suppParams);
            }
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $paidStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM supplier_payments WHERE supplier_id = ?");
                $paidStmt->execute([$r['id']]);
                $paidVal = (float) $paidStmt->fetchColumn();
                $invStmt = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(invoice_amount, amount)), 0) FROM supplier_payments WHERE supplier_id = ?");
                $invStmt->execute([$r['id']]);
                $invVal = (float) $invStmt->fetchColumn();
                $suppliers[] = ['id' => $r['id'], 'name' => $r['name'], 'code' => $r['code'], 'paid' => $paidVal, 'invoiced' => $invVal, 'payable' => $invVal - $paidVal];
            }
        } else {
            $stmt = $suppParams ? $pdo->prepare($suppSql) : $pdo->query($suppSql);
            if ($suppParams) {
                $stmt->execute($suppParams);
            }
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $suppliers[] = ['id' => $r['id'], 'name' => $r['name'], 'code' => $r['code'], 'paid' => 0, 'invoiced' => 0, 'payable' => 0];
            }
        }
        jsonResponse(['data' => ['customers' => $customers, 'suppliers' => $suppliers]]);
    }

    if ($id === 'profit' || $id === null) {
        $chkSell = @$pdo->query("SHOW COLUMNS FROM order_items LIKE 'sell_price'");
        $hasSell = $chkSell && $chkSell->rowCount() > 0;
        $chkBuy = @$pdo->query("SHOW COLUMNS FROM order_items LIKE 'buy_price'");
        $hasBuy = $chkBuy && $chkBuy->rowCount() > 0;
        $chkItemSupp = @$pdo->query("SHOW COLUMNS FROM order_items LIKE 'supplier_id'");
        $hasItemSupplier = $chkItemSupp && $chkItemSupp->rowCount() > 0;
        $chkComm = @$pdo->query("SHOW COLUMNS FROM suppliers LIKE 'commission_rate'");
        $hasCommission = $chkComm && $chkComm->rowCount() > 0;

        $sellExpr = $hasSell
            ? "(SELECT SUM(CASE WHEN oi.sell_price IS NOT NULL THEN oi.quantity * oi.sell_price ELSE COALESCE(oi.total_amount, oi.quantity * COALESCE(oi.unit_price, 0)) END) FROM order_items oi WHERE oi.order_id = o.id)"
            : "(SELECT SUM(COALESCE(oi.total_amount, oi.quantity * COALESCE(oi.unit_price, 0))) FROM order_items oi WHERE oi.order_id = o.id)";
        $buyExpr = $hasBuy
            ? "(SELECT SUM(oi.quantity * COALESCE(oi.buy_price, oi.unit_price)) FROM order_items oi WHERE oi.order_id = o.id)"
            : "(SELECT SUM(oi.quantity * COALESCE(oi.unit_price, 0)) FROM order_items oi WHERE oi.order_id = o.id)";

        $suppCols = 's.name as supplier_name';
        if ($hasCommission && !$hasItemSupplier) {
            $suppCols .= ', s.commission_rate, s.commission_type, s.commission_applied_on';
        }
        $sql = "SELECT o.id, o.customer_id, o.supplier_id, o.currency, o.status, o.expected_ready_date,
            c.name as customer_name, $suppCols,
            $sellExpr as order_total,
            $buyExpr as buy_total
            FROM orders o
            JOIN customers c ON o.customer_id = c.id
            LEFT JOIN suppliers s ON o.supplier_id = s.id
            WHERE 1=1";
        $params = [];
        if ($statuses) {
            if ($statusMode === 'exclude') {
                $excludedStatuses = array_values(array_unique(array_merge($defaultExcludedStatuses, $statuses)));
                $placeholders = implode(',', array_fill(0, count($excludedStatuses), '?'));
                $sql .= " AND o.status NOT IN ($placeholders)";
                $params = array_merge($params, $excludedStatuses);
            } else {
                $placeholders = implode(',', array_fill(0, count($statuses), '?'));
                $sql .= " AND o.status IN ($placeholders)";
                $params = array_merge($params, $statuses);
            }
        } else {
            $placeholders = implode(',', array_fill(0, count($defaultExcludedStatuses), '?'));
            $sql .= " AND o.status NOT IN ($placeholders)";
            $params = array_merge($params, $defaultExcludedStatuses);
        }
        if ($dateFrom) {
            $sql .= " AND o.expected_ready_date >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo) {
            $sql .= " AND o.expected_ready_date <= ?";
            $params[] = $dateTo;
        }
        if ($customerId) {
            $sql .= " AND o.customer_id = ?";
            $params[] = $customerId;
        }
        if ($supplierId) {
            if ($hasItemSupplier) {
                $sql .= " AND (o.supplier_id = ? OR EXISTS (SELECT 1 FROM order_items oi LEFT JOIN products p ON oi.product_id = p.id WHERE oi.order_id = o.id AND COALESCE(oi.supplier_id, p.supplier_id) = ?))";
                $params[] = $supplierId;
                $params[] = $supplierId;
            } else {
                $sql .= " AND o.supplier_id = ?";
                $params[] = $supplierId;
            }
        }
        $sql .= " ORDER BY o.expected_ready_date DESC";
        $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
        if ($params) $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $orderCommissions = [];
        if ($hasCommission && $hasItemSupplier && !empty($rows)) {
            $orderIds = array_column($rows, 'id');
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $itemSql = "SELECT oi.order_id, oi.quantity, oi.buy_price, oi.sell_price, oi.unit_price,
                COALESCE(oi.supplier_id, p.supplier_id) as eff_supplier_id,
                s.commission_rate, s.commission_type, s.commission_applied_on
                FROM order_items oi
                LEFT JOIN products p ON oi.product_id = p.id
                LEFT JOIN suppliers s ON s.id = COALESCE(oi.supplier_id, p.supplier_id)
                WHERE oi.order_id IN ($placeholders)";
            $itemStmt = $pdo->prepare($itemSql);
            $itemStmt->execute($orderIds);
            $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($items as $it) {
                $oid = (int) $it['order_id'];
                $sid = $it['eff_supplier_id'] ? (int) $it['eff_supplier_id'] : null;
                $rate = (float) ($it['commission_rate'] ?? 0);
                $type = $it['commission_type'] ?? 'percentage';
                $appliedOn = $it['commission_applied_on'] ?? 'buy_value';
                if (!$rate || !$sid) continue;
                $buyVal = (float) ($it['quantity'] ?? 0) * (float) ($it['buy_price'] ?? $it['unit_price'] ?? 0);
                $sellVal = (float) ($it['quantity'] ?? 0) * (float) ($it['sell_price'] ?? $it['unit_price'] ?? 0);
                $base = $appliedOn === 'sell_value' ? $sellVal : $buyVal;
                if ($type === 'fixed') {
                    if (!isset($orderCommissions[$oid])) $orderCommissions[$oid] = ['pct' => 0, 'fixed' => []];
                    if (!isset($orderCommissions[$oid]['fixed'][$sid])) $orderCommissions[$oid]['fixed'][$sid] = $rate;
                } else {
                    if (!isset($orderCommissions[$oid])) $orderCommissions[$oid] = ['pct' => 0, 'fixed' => []];
                    $orderCommissions[$oid]['pct'] += $base * $rate / 100;
                }
            }
        } elseif ($hasCommission && !empty($rows)) {
            foreach ($rows as $r) {
                $oid = (int) $r['id'];
                $rate = (float) ($r['commission_rate'] ?? 0);
                if (!$rate) continue;
                $type = $r['commission_type'] ?? 'percentage';
                $appliedOn = $r['commission_applied_on'] ?? 'buy_value';
                $base = $appliedOn === 'sell_value' ? (float) ($r['order_total'] ?? 0) : (float) ($r['buy_total'] ?? 0);
                if ($type === 'fixed') {
                    $orderCommissions[$oid] = ['pct' => 0, 'fixed' => [0 => $rate]];
                } else {
                    $orderCommissions[$oid] = ['pct' => $base * $rate / 100, 'fixed' => []];
                }
            }
        }

        $totalSell = 0;
        $totalBuy = 0;
        $totalCommission = 0;
        foreach ($rows as &$r) {
            $r['order_total'] = (float) ($r['order_total'] ?? 0);
            $r['buy_total'] = (float) ($r['buy_total'] ?? $r['order_total']);
            $commission = 0.0;
            $oid = (int) $r['id'];
            if (isset($orderCommissions[$oid])) {
                $commission = $orderCommissions[$oid]['pct'] ?? 0;
                $commission += isset($orderCommissions[$oid]['fixed']) ? array_sum($orderCommissions[$oid]['fixed']) : 0;
            }
            $r['commission'] = round($commission, 4);
            $r['margin'] = $r['order_total'] - $r['buy_total'] - $r['commission'];
            $totalSell += $r['order_total'];
            $totalBuy += $r['buy_total'];
            $totalCommission += $r['commission'];
        }
        $expenseSql = "SELECT currency, SUM(amount) as total FROM expenses WHERE 1=1";
        $expParams = [];
        if ($dateFrom) {
            $expenseSql .= " AND expense_date >= ?";
            $expParams[] = $dateFrom;
        }
        if ($dateTo) {
            $expenseSql .= " AND expense_date <= ?";
            $expParams[] = $dateTo;
        }
        $expenseSql .= " GROUP BY currency";
        $expStmt = $expParams ? $pdo->prepare($expenseSql) : $pdo->query($expenseSql);
        if ($expParams) $expStmt->execute($expParams);
        $expenses = $expStmt->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(['data' => $rows, 'summary' => ['total_sell' => $totalSell, 'total_buy' => $totalBuy, 'total_commission' => $totalCommission, 'gross_profit' => $totalSell - $totalBuy, 'net_profit' => $totalSell - $totalBuy - $totalCommission, 'expenses' => $expenses]]);
    }

    jsonError('Not found', 404);
};
