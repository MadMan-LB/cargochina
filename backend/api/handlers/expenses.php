<?php

/**
 * Expenses API - CRUD for expenses and expense categories
 * Roles: ChinaAdmin, LebanonAdmin, SuperAdmin
 */

require_once __DIR__ . '/../helpers.php';

/**
 * Find expense category by name, or create it if not found.
 * Returns category id. Throws on failure.
 * Handles race: if concurrent request creates same category, retries SELECT.
 */
function findOrCreateExpenseCategory(PDO $pdo, string $name, ?int $userId = null): int
{
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('Category name cannot be empty');
    }
    $stmt = $pdo->prepare("SELECT id FROM expense_categories WHERE TRIM(name) = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return (int) $row['id'];
    }
    $code = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
    $code = trim($code, '-') ?: 'custom-' . substr(md5($name), 0, 8);
    $baseCode = $code;
    $n = 0;
    while (true) {
        $tryCode = $n === 0 ? $code : $baseCode . '-' . $n;
        $chk = $pdo->prepare("SELECT 1 FROM expense_categories WHERE code = ?");
        $chk->execute([$tryCode]);
        if ($chk->rowCount() === 0) {
            $code = $tryCode;
            break;
        }
        $n++;
    }
    try {
        $pdo->prepare("INSERT INTO expense_categories (code, name, category_type) VALUES (?, ?, 'operational')")
            ->execute([$code, $name]);
        $id = (int) $pdo->lastInsertId();
        if ($userId) {
            logClms('expense_category_create', ['category_id' => $id, 'name' => $name, 'code' => $code, 'user_id' => $userId]);
        }
        return $id;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            $stmt->execute([$name]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return (int) $row['id'];
            }
        }
        throw $e;
    }
}

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();
    $userId = getAuthUserId();
    if (!$userId) {
        jsonError('Unauthorized', 401);
    }
    if (!hasAnyRole(['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'])) {
        jsonError('Forbidden', 403);
    }

    switch ($method) {
        case 'GET':
            if ($id === 'payee-suggestions') {
                setCacheHeaders(30);
                $q = trim($_GET['q'] ?? '');
                if (strlen($q) < 1) {
                    jsonResponse(['data' => []]);
                }
                $like = '%' . preg_replace('/\s+/', '%', $q) . '%';
                $data = [];
                $payeeSql = "SELECT DISTINCT payee as name FROM expenses WHERE payee IS NOT NULL AND TRIM(payee) != ''";
                $payeeParams = [];
                if (strlen($q) >= 1) {
                    $payeeSql .= " AND payee LIKE ?";
                    $payeeParams[] = $like;
                }
                $payeeSql .= " ORDER BY payee LIMIT 15";
                $stmt = $payeeParams ? $pdo->prepare($payeeSql) : $pdo->query($payeeSql);
                if ($payeeParams) $stmt->execute($payeeParams);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $data[] = ['id' => 'payee:' . $r['name'], 'payee' => $r['name'], 'name' => $r['name'], 'source' => 'payee'];
                }
                $suppSql = "SELECT id, code, name, phone, store_id FROM suppliers WHERE name LIKE ? OR code LIKE ? OR (phone IS NOT NULL AND phone LIKE ?) OR (store_id IS NOT NULL AND store_id LIKE ?)";
                $chkAddr = @$pdo->query("SHOW COLUMNS FROM suppliers LIKE 'address'");
                $chkFactory = @$pdo->query("SHOW COLUMNS FROM suppliers LIKE 'factory_location'");
                if ($chkAddr && $chkAddr->rowCount() > 0) $suppSql .= " OR (address IS NOT NULL AND address LIKE ?)";
                if ($chkFactory && $chkFactory->rowCount() > 0) $suppSql .= " OR (factory_location IS NOT NULL AND factory_location LIKE ?)";
                $suppSql .= " ORDER BY name LIMIT 15";
                $suppParams = [$like, $like, $like, $like];
                if ($chkAddr && $chkAddr->rowCount() > 0) $suppParams[] = $like;
                if ($chkFactory && $chkFactory->rowCount() > 0) $suppParams[] = $like;
                $stmt = $pdo->prepare($suppSql);
                $stmt->execute($suppParams);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $data[] = ['id' => 'supplier:' . $r['id'], 'payee' => $r['name'], 'name' => $r['name'], 'source' => 'supplier', 'supplier_id' => (int) $r['id'], 'code' => $r['code'] ?? null, 'phone' => $r['phone'] ?? null];
                }
                jsonResponse(['data' => $data]);
            }
            if ($id === 'payees') {
                setCacheHeaders(30);
                $q = trim($_GET['q'] ?? '');
                $sql = "SELECT DISTINCT payee as name FROM expenses WHERE payee IS NOT NULL AND TRIM(payee) != ''";
                $params = [];
                if (strlen($q) >= 1) {
                    $like = '%' . preg_replace('/\s+/', '%', $q) . '%';
                    $sql .= " AND payee LIKE ?";
                    $params[] = $like;
                }
                $sql .= " ORDER BY payee LIMIT 20";
                $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
                if ($params) $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $data = array_map(function ($r) {
                    return ['id' => $r['name'], 'payee' => $r['name'], 'name' => $r['name']];
                }, $rows);
                jsonResponse(['data' => $data]);
            }
            if ($id === 'categories') {
                setCacheHeaders(60);
                $q = trim($_GET['q'] ?? '');
                $sql = "SELECT * FROM expense_categories WHERE is_active = 1";
                $params = [];
                if (strlen($q) >= 1) {
                    $like = '%' . preg_replace('/\s+/', '%', $q) . '%';
                    $sql .= " AND (name LIKE ? OR category_type LIKE ?)";
                    $params = [$like, $like];
                }
                $sql .= " ORDER BY category_type, name";
                $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
                if ($params) $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                jsonResponse(['data' => $rows]);
            }
            if ($id === null || $id === '') {
                $dateFrom = $_GET['date_from'] ?? null;
                $dateTo = $_GET['date_to'] ?? null;
                $categoryId = $_GET['category_id'] ?? null;
                $orderId = $_GET['order_id'] ?? null;
                $containerId = $_GET['container_id'] ?? null;
                $customerId = $_GET['customer_id'] ?? null;
                $supplierId = $_GET['supplier_id'] ?? null;
                $q = trim($_GET['q'] ?? '');
                $limit = min(500, (int) ($_GET['limit'] ?? 100));
                $offset = (int) ($_GET['offset'] ?? 0);

                $sql = "SELECT e.*, ec.name as category_name, ec.category_type,
                    o.id as order_id_ref, c.name as customer_name, co.code as container_code, s.name as supplier_name
                    FROM expenses e
                    JOIN expense_categories ec ON e.category_id = ec.id
                    LEFT JOIN orders o ON e.order_id = o.id
                    LEFT JOIN customers c ON e.customer_id = c.id
                    LEFT JOIN containers co ON e.container_id = co.id
                    LEFT JOIN suppliers s ON e.supplier_id = s.id
                    WHERE 1=1";
                $params = [];
                if ($dateFrom) {
                    $sql .= " AND e.expense_date >= ?";
                    $params[] = $dateFrom;
                }
                if ($dateTo) {
                    $sql .= " AND e.expense_date <= ?";
                    $params[] = $dateTo;
                }
                if ($categoryId) {
                    $sql .= " AND e.category_id = ?";
                    $params[] = $categoryId;
                }
                if ($orderId) {
                    $sql .= " AND e.order_id = ?";
                    $params[] = $orderId;
                }
                if ($containerId) {
                    $sql .= " AND e.container_id = ?";
                    $params[] = $containerId;
                }
                if ($customerId) {
                    $sql .= " AND e.customer_id = ?";
                    $params[] = $customerId;
                }
                if ($supplierId) {
                    $sql .= " AND e.supplier_id = ?";
                    $params[] = $supplierId;
                }
                if (strlen($q) >= 1) {
                    $like = '%' . preg_replace('/\s+/', '%', $q) . '%';
                    $chkSupp = @$pdo->query("SHOW COLUMNS FROM expenses LIKE 'supplier_id'");
                    $suppCond = ($chkSupp && $chkSupp->rowCount() > 0)
                        ? " OR (s.name IS NOT NULL AND s.name LIKE ?)"
                        : "";
                    $sql .= " AND (e.payee LIKE ? OR e.notes LIKE ? OR ec.name LIKE ? OR co.code LIKE ?$suppCond)";
                    $params[] = $like;
                    $params[] = $like;
                    $params[] = $like;
                    $params[] = $like;
                    if ($suppCond) $params[] = $like;
                }
                $sql .= " ORDER BY e.expense_date DESC, e.id DESC LIMIT " . (int) $limit . " OFFSET " . (int) $offset;

                $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
                if ($params) $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Summary (same filters as list)
                $sumSql = "SELECT currency, SUM(amount) as total FROM expenses e
                    JOIN expense_categories ec ON e.category_id = ec.id
                    LEFT JOIN containers co ON e.container_id = co.id
                    LEFT JOIN suppliers s ON e.supplier_id = s.id
                    WHERE 1=1";
                $sumParams = [];
                if ($dateFrom) {
                    $sumSql .= " AND e.expense_date >= ?";
                    $sumParams[] = $dateFrom;
                }
                if ($dateTo) {
                    $sumSql .= " AND e.expense_date <= ?";
                    $sumParams[] = $dateTo;
                }
                if ($categoryId) {
                    $sumSql .= " AND e.category_id = ?";
                    $sumParams[] = $categoryId;
                }
                if ($orderId) {
                    $sumSql .= " AND e.order_id = ?";
                    $sumParams[] = $orderId;
                }
                if ($containerId) {
                    $sumSql .= " AND e.container_id = ?";
                    $sumParams[] = $containerId;
                }
                if ($customerId) {
                    $sumSql .= " AND e.customer_id = ?";
                    $sumParams[] = $customerId;
                }
                if ($supplierId) {
                    $sumSql .= " AND e.supplier_id = ?";
                    $sumParams[] = $supplierId;
                }
                if (strlen($q) >= 1) {
                    $like = '%' . preg_replace('/\s+/', '%', $q) . '%';
                    $sumSql .= " AND (e.payee LIKE ? OR e.notes LIKE ? OR ec.name LIKE ? OR co.code LIKE ? OR (s.name IS NOT NULL AND s.name LIKE ?))";
                    $sumParams[] = $like;
                    $sumParams[] = $like;
                    $sumParams[] = $like;
                    $sumParams[] = $like;
                    $sumParams[] = $like;
                }
                $sumSql .= " GROUP BY currency";
                $sumStmt = $sumParams ? $pdo->prepare($sumSql) : $pdo->query($sumSql);
                if ($sumParams) $sumStmt->execute($sumParams);
                $summary = $sumStmt->fetchAll(PDO::FETCH_ASSOC);

                jsonResponse(['data' => $rows, 'summary' => $summary]);
            }
            $stmt = $pdo->prepare("SELECT e.*, ec.name as category_name, o.expected_ready_date as order_expected_ready_date, c.name as customer_name, co.code as container_code, s.name as supplier_name
                FROM expenses e
                JOIN expense_categories ec ON e.category_id = ec.id
                LEFT JOIN orders o ON e.order_id = o.id
                LEFT JOIN customers c ON e.customer_id = c.id
                LEFT JOIN containers co ON e.container_id = co.id
                LEFT JOIN suppliers s ON e.supplier_id = s.id
                WHERE e.id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) jsonError('Expense not found', 404);
            jsonResponse(['data' => $row]);

        case 'POST':
            $categoryId = (int) ($input['category_id'] ?? 0);
            $categoryName = trim($input['category_name'] ?? '');
            $amount = (float) ($input['amount'] ?? 0);
            $currency = trim($input['currency'] ?? 'USD');
            $expenseDate = trim($input['expense_date'] ?? date('Y-m-d'));
            $payee = trim($input['payee'] ?? '') ?: null;
            $notes = trim($input['notes'] ?? '') ?: null;
            $orderId = !empty($input['order_id']) ? (int) $input['order_id'] : null;
            $containerId = !empty($input['container_id']) ? (int) $input['container_id'] : null;
            $customerId = !empty($input['customer_id']) ? (int) $input['customer_id'] : null;
            $supplierId = !empty($input['supplier_id']) ? (int) $input['supplier_id'] : null;

            if (!$categoryId && $categoryName !== '') {
                $categoryId = findOrCreateExpenseCategory($pdo, $categoryName, $userId);
            }
            if (!$categoryId || $amount <= 0) {
                jsonError('Category and amount are required. Type a category name or select one from the search.', 400);
            }
            if (!in_array($currency, ['USD', 'RMB', 'EUR'], true)) {
                $currency = 'USD';
            }

            $chkSupp = @$pdo->query("SHOW COLUMNS FROM expenses LIKE 'supplier_id'");
            $hasSupp = $chkSupp && $chkSupp->rowCount() > 0;
            if ($hasSupp) {
                $stmt = $pdo->prepare("INSERT INTO expenses (category_id, amount, currency, expense_date, payee, notes, order_id, container_id, customer_id, supplier_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$categoryId, $amount, $currency, $expenseDate, $payee, $notes, $orderId, $containerId, $customerId, $supplierId, $userId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO expenses (category_id, amount, currency, expense_date, payee, notes, order_id, container_id, customer_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$categoryId, $amount, $currency, $expenseDate, $payee, $notes, $orderId, $containerId, $customerId, $userId]);
            }

            $newId = (int) $pdo->lastInsertId();
            logClms('expense_create', ['expense_id' => $newId, 'amount' => $amount, 'currency' => $currency, 'user_id' => $userId]);

            $stmt = $pdo->prepare("SELECT e.*, ec.name as category_name FROM expenses e JOIN expense_categories ec ON e.category_id = ec.id WHERE e.id = ?");
            $stmt->execute([$newId]);
            jsonResponse(['data' => $stmt->fetch(PDO::FETCH_ASSOC)], 201);

        case 'PUT':
            if (!$id) jsonError('Expense ID required', 400);
            $stmt = $pdo->prepare("SELECT id FROM expenses WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) jsonError('Expense not found', 404);

            $categoryId = array_key_exists('category_id', $input) ? (int) $input['category_id'] : null;
            $categoryName = trim($input['category_name'] ?? '');
            if ($categoryId !== null && !$categoryId && $categoryName !== '') {
                $categoryId = findOrCreateExpenseCategory($pdo, $categoryName, $userId);
                $input['category_id'] = $categoryId;
            }

            $updates = [];
            $params = [];
            foreach (['category_id', 'amount', 'currency', 'expense_date', 'payee', 'notes', 'order_id', 'container_id', 'customer_id', 'supplier_id'] as $col) {
                if (array_key_exists($col, $input)) {
                    if ($col === 'amount') {
                        $v = (float) $input[$col];
                        if ($v <= 0) continue;
                    } elseif (in_array($col, ['order_id', 'container_id', 'customer_id', 'supplier_id'])) {
                        $v = !empty($input[$col]) ? (int) $input[$col] : null;
                    } elseif ($col === 'category_id') {
                        $v = (int) $input[$col];
                        if ($v <= 0) continue;
                    } else {
                        $v = trim($input[$col] ?? '') ?: null;
                    }
                    $updates[] = "$col = ?";
                    $params[] = $v;
                }
            }
            if (empty($updates)) {
                jsonError('No fields to update', 400);
            }
            $params[] = $id;
            $pdo->prepare("UPDATE expenses SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
            logClms('expense_update', ['expense_id' => $id, 'user_id' => $userId]);

            $stmt = $pdo->prepare("SELECT e.*, ec.name as category_name FROM expenses e JOIN expense_categories ec ON e.category_id = ec.id WHERE e.id = ?");
            $stmt->execute([$id]);
            jsonResponse(['data' => $stmt->fetch(PDO::FETCH_ASSOC)]);

        case 'DELETE':
            if (!$id) jsonError('Expense ID required', 400);
            $stmt = $pdo->prepare("DELETE FROM expenses WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) jsonError('Expense not found', 404);
            logClms('expense_delete', ['expense_id' => $id, 'user_id' => $userId]);
            jsonResponse(['data' => ['deleted' => true]]);

        default:
            jsonError('Method not allowed', 405);
    }
};