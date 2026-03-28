<?php

/**
 * Users API - list, get one, update (SuperAdmin only)
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 3) . '/includes/sidebar_permissions.php';

function normalizeUserLoginIdentifier(string $value): string
{
    return trim($value);
}

function normalizeUserPassword($value): string
{
    return (string) ($value ?? '');
}

return function (string $method, ?string $id, ?string $action, array $input) {
    requireRole(['SuperAdmin']);

    $pdo = getDb();

    if ($method === 'GET') {
        if ($id === 'sidebar-access' && $action === null) {
            $roles = $pdo->query("SELECT id, code, name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
            $defaults = [];
            $assignable = [];
            foreach ($roles as $role) {
                $defaults[$role['code']] = clmsGetDefaultPageIdsForRole($role['code']);
                $assignable[$role['code']] = clmsGetAssignablePageIdsForRole($role['code']);
            }
            jsonResponse([
                'data' => [
                    'roles' => $roles,
                    'registry' => clmsSidebarPageRegistry(),
                    'sections' => clmsSidebarSectionLabels(),
                    'assignable' => $assignable,
                    'defaults' => $defaults,
                    'settings' => clmsLoadRoleSidebarPageSettings($pdo),
                ],
            ]);
        }
        if ($id === null) {
            $stmt = $pdo->query("SELECT u.id, u.email, u.full_name, u.is_active, u.created_at FROM users u ORDER BY u.id");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $roleStmt = $pdo->prepare("SELECT r.code FROM roles r JOIN user_roles ur ON r.id = ur.role_id WHERE ur.user_id = ?");
                $roleStmt->execute([$r['id']]);
                $r['roles'] = array_column($roleStmt->fetchAll(PDO::FETCH_ASSOC), 'code');
                $deptStmt = $pdo->prepare("SELECT d.id, d.code, d.name FROM departments d JOIN user_departments ud ON d.id = ud.department_id WHERE ud.user_id = ? ORDER BY ud.is_primary DESC");
                $deptStmt->execute([$r['id']]);
                $r['departments'] = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            jsonResponse(['data' => $rows]);
        }
        if ($id && $action === 'activity') {
            $userId = (int) $id;
            $entityType = trim($_GET['entity_type'] ?? '');
            $actionFilter = trim($_GET['action'] ?? '');
            $dateFrom = trim($_GET['date_from'] ?? '');
            $dateTo = trim($_GET['date_to'] ?? '');
            $limit = min(100, max(1, (int) ($_GET['limit'] ?? 50)));
            $offset = max(0, (int) ($_GET['offset'] ?? 0));

            $chk = $pdo->prepare("SELECT 1 FROM users WHERE id = ?");
            $chk->execute([$userId]);
            if (!$chk->fetch()) jsonError('User not found', 404);

            $fetchLimit = $limit + $offset + 50;

            // 1. Audit log (exclude order+confirm — we get those from customer_confirmations)
            $sql = "SELECT a.id, a.entity_type, a.entity_id, a.action, a.old_value, a.new_value, a.user_id, a.created_at
                FROM audit_log a WHERE a.user_id = ?
                AND NOT (a.entity_type = 'order' AND a.action = 'confirm')";
            $params = [$userId];
            if ($entityType !== '') {
                $sql .= " AND a.entity_type = ?";
                $params[] = $entityType;
            }
            if ($actionFilter !== '') {
                $sql .= " AND a.action = ?";
                $params[] = $actionFilter;
            }
            if ($dateFrom !== '') {
                $sql .= " AND DATE(a.created_at) >= ?";
                $params[] = $dateFrom;
            }
            if ($dateTo !== '') {
                $sql .= " AND DATE(a.created_at) <= ?";
                $params[] = $dateTo;
            }
            $sql .= " ORDER BY a.created_at DESC LIMIT " . (int) $fetchLimit;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $auditRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 2. Customer confirmations (admin confirmations only — confirmed_by IS NOT NULL)
            $includeConfirmations = ($entityType === '' || $entityType === 'order') && ($actionFilter === '' || $actionFilter === 'confirm');
            $ccRows = [];
            if ($includeConfirmations) {
                $ccSql = "SELECT cc.id, cc.order_id, cc.confirmed_at
                    FROM customer_confirmations cc
                    WHERE cc.confirmed_by = ? AND cc.confirmed_at IS NOT NULL";
                $ccParams = [$userId];
                if ($dateFrom !== '') {
                    $ccSql .= " AND DATE(cc.confirmed_at) >= ?";
                    $ccParams[] = $dateFrom;
                }
                if ($dateTo !== '') {
                    $ccSql .= " AND DATE(cc.confirmed_at) <= ?";
                    $ccParams[] = $dateTo;
                }
                $ccSql .= " ORDER BY cc.confirmed_at DESC LIMIT " . (int) $fetchLimit;
                $ccStmt = $pdo->prepare($ccSql);
                $ccStmt->execute($ccParams);
                $ccRows = $ccStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // 3. created_by synthetic events (orders, expenses, supplier_interactions, procurement_drafts, order_templates, customer_deposits, customer_portal_tokens)
            $includeCreateAction = $actionFilter === '' || $actionFilter === 'create';
            $createdByMap = [
                'order' => ['orders', 'order', 'created_by', 'created_at'],
                'expense' => ['expenses', 'expense', 'created_by', 'created_at'],
                'supplier_interaction' => ['supplier_interactions', 'supplier_interaction', 'created_by', 'created_at'],
                'procurement_draft' => ['procurement_drafts', 'procurement_draft', 'created_by', 'created_at'],
                'order_template' => ['order_templates', 'order_template', 'created_by', 'created_at'],
                'customer_deposit' => ['customer_deposits', 'customer_deposit', 'created_by', 'created_at'],
                'customer_portal_token' => ['customer_portal_tokens', 'customer_portal_token', 'created_by', 'created_at'],
                'design_attachment' => ['design_attachments', 'design_attachment', 'uploaded_by', 'uploaded_at'],
            ];
            $createdByRows = [];
            if ($includeCreateAction) {
                $toFetch = $entityType === ''
                    ? array_keys($createdByMap)
                    : (isset($createdByMap[$entityType]) ? [$entityType] : []);
                foreach ($toFetch as $entType) {
                    [$table, $_, $col, $tsCol] = $createdByMap[$entType];
                    try {
                        $cbSql = "SELECT id as entity_id, $tsCol as created_at FROM `$table` WHERE $col = ?";
                        $cbParams = [$userId];
                        if ($dateFrom !== '') {
                            $cbSql .= " AND DATE($tsCol) >= ?";
                            $cbParams[] = $dateFrom;
                        }
                        if ($dateTo !== '') {
                            $cbSql .= " AND DATE($tsCol) <= ?";
                            $cbParams[] = $dateTo;
                        }
                        $cbSql .= " ORDER BY $tsCol DESC LIMIT " . (int) $fetchLimit;
                        $cbStmt = $pdo->prepare($cbSql);
                        $cbStmt->execute($cbParams);
                        foreach ($cbStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                            $createdByRows[] = [
                                'id' => 'cb-' . $entType . '-' . $r['entity_id'],
                                'entity_type' => $entType,
                                'entity_id' => (int) $r['entity_id'],
                                'action' => 'create',
                                'old_value' => null,
                                'new_value' => null,
                                'user_id' => $userId,
                                'created_at' => $r['created_at'],
                            ];
                        }
                    } catch (PDOException $e) {
                        // table/column may not exist
                    }
                }
            }

            // 4. Merge and sort
            $items = [];
            foreach ($auditRows as $r) {
                $items[] = [
                    'id' => $r['id'],
                    'entity_type' => $r['entity_type'],
                    'entity_id' => (int) $r['entity_id'],
                    'action' => $r['action'],
                    'old_value' => $r['old_value'],
                    'new_value' => $r['new_value'],
                    'user_id' => (int) $r['user_id'],
                    'created_at' => $r['created_at'],
                ];
            }
            foreach ($ccRows as $r) {
                $items[] = [
                    'id' => 'cc-' . $r['id'],
                    'entity_type' => 'order',
                    'entity_id' => (int) $r['order_id'],
                    'action' => 'confirm',
                    'old_value' => null,
                    'new_value' => json_encode(['source' => 'customer_confirmations']),
                    'user_id' => $userId,
                    'created_at' => $r['confirmed_at'],
                ];
            }
            $items = array_merge($items, $createdByRows);
            usort($items, function ($a, $b) {
                return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
            });

            $total = count($items);
            $items = array_slice($items, $offset, $limit + 1);
            $hasMore = count($items) > $limit;
            if ($hasMore) array_pop($items);

            jsonResponse(['data' => $items, 'has_more' => $hasMore]);
        }
        $stmt = $pdo->prepare("SELECT id, email, full_name, is_active FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) jsonError('User not found', 404);
        $roleStmt = $pdo->prepare("SELECT r.code FROM roles r JOIN user_roles ur ON r.id = ur.role_id WHERE ur.user_id = ?");
        $roleStmt->execute([$id]);
        $row['roles'] = array_column($roleStmt->fetchAll(PDO::FETCH_ASSOC), 'code');
        $deptStmt = $pdo->prepare("SELECT d.id, d.code, d.name, ud.is_primary FROM departments d JOIN user_departments ud ON d.id = ud.department_id WHERE ud.user_id = ?");
        $deptStmt->execute([$id]);
        $row['departments'] = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(['data' => $row]);
    }

    if ($method === 'POST' && $id === null) {
        $email = normalizeUserLoginIdentifier((string) ($input['email'] ?? ''));
        $fullName = trim($input['full_name'] ?? '');
        $password = normalizeUserPassword($input['password'] ?? '');
        $roles = $input['roles'] ?? [];
        $departmentIds = $input['department_ids'] ?? [];

        if ($email === '') {
            jsonError('Email/username is required', 400);
        }
        if ($fullName === '') {
            jsonError('Full name is required', 400);
        }
        if (strlen($password) < 6) {
            jsonError('Password must be at least 6 characters', 400);
        }
        if (!is_array($roles) || empty($roles)) {
            jsonError('At least one role is required', 400);
        }

        $chk = $pdo->prepare("SELECT 1 FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))");
        $chk->execute([$email]);
        if ($chk->fetch()) {
            jsonError('Email/username already in use', 400);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO users (email, password_hash, full_name, is_active) VALUES (?, ?, ?, 1)")
                ->execute([$email, $hash, $fullName]);
            $newId = (int) $pdo->lastInsertId();

            $roleMap = [];
            foreach ($pdo->query("SELECT id, code FROM roles")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $roleMap[$r['code']] = $r['id'];
            }
            $validRoleIds = [];
            foreach ($roles as $code) {
                if (isset($roleMap[$code])) {
                    $validRoleIds[] = (int) $roleMap[$code];
                }
            }
            $validRoleIds = array_values(array_unique($validRoleIds));
            if (!$validRoleIds) {
                throw new RuntimeException('At least one valid role is required');
            }

            $insRole = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
            foreach ($validRoleIds as $roleId) {
                $insRole->execute([$newId, $roleId]);
            }

            if (is_array($departmentIds) && !empty($departmentIds)) {
                $insDept = $pdo->prepare("INSERT INTO user_departments (user_id, department_id, is_primary) VALUES (?, ?, ?)");
                foreach ($departmentIds as $i => $deptId) {
                    if ($deptId) {
                        $insDept->execute([$newId, (int) $deptId, $i === 0 ? 1 : 0]);
                    }
                }
            }

            $userId = getAuthUserId();
            $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('user', ?, 'create', ?, ?)")
                ->execute([$newId, json_encode(['email' => $email, 'full_name' => $fullName]), $userId]);

            $stmt = $pdo->prepare("SELECT id, email, full_name, is_active, created_at FROM users WHERE id = ?");
            $stmt->execute([$newId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $roleStmt = $pdo->prepare("SELECT r.code FROM roles r JOIN user_roles ur ON r.id = ur.role_id WHERE ur.user_id = ?");
            $roleStmt->execute([$newId]);
            $row['roles'] = array_column($roleStmt->fetchAll(PDO::FETCH_ASSOC), 'code');
            $deptStmt = $pdo->prepare("SELECT d.id, d.code, d.name FROM departments d JOIN user_departments ud ON d.id = ud.department_id WHERE ud.user_id = ? ORDER BY ud.is_primary DESC");
            $deptStmt->execute([$newId]);
            $row['departments'] = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
            $pdo->commit();
            jsonResponse(['data' => $row]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof RuntimeException) {
                jsonError($e->getMessage(), 400);
            }
            throw $e;
        }
    }

    if ($method === 'POST' && $id && $action === 'reset-password') {
        $newPassword = normalizeUserPassword($input['password'] ?? '');
        if (strlen($newPassword) < 6) {
            jsonError('Password must be at least 6 characters', 400);
        }
        $stmt = $pdo->prepare("SELECT id, email FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) jsonError('User not found', 404);
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $id]);
        jsonResponse(['data' => ['user_id' => (int) $id, 'email' => $user['email'], 'new_password' => $newPassword]]);
    }

    if ($method === 'PUT' && $id) {
        if ($id === 'sidebar-access') {
            $settings = $input['settings'] ?? null;
            if (!is_array($settings)) {
                jsonError('Sidebar settings payload is required', 400);
            }

            $oldSettings = clmsLoadRoleSidebarPageSettings($pdo);
            $sanitizedSettings = clmsSanitizeRoleSidebarPageSettings($settings);
            clmsSaveRoleSidebarPageSettings($pdo, $sanitizedSettings);

            $userId = getAuthUserId();
            $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, old_value, new_value, user_id) VALUES ('system_config', 0, 'update', ?, ?, ?)")
                ->execute([
                    json_encode(['key' => clmsSidebarConfigKey(), 'settings' => $oldSettings], JSON_UNESCAPED_UNICODE),
                    json_encode(['key' => clmsSidebarConfigKey(), 'settings' => $sanitizedSettings], JSON_UNESCAPED_UNICODE),
                    $userId,
                ]);

            jsonResponse(['data' => ['settings' => $sanitizedSettings]]);
        }

        $roles = $input['roles'] ?? null;
        $departmentIds = $input['department_ids'] ?? null;
        $isActive = isset($input['is_active']) ? (int) (bool) $input['is_active'] : null;
        $pdo->beginTransaction();
        try {
            if ($isActive !== null) {
                $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$isActive, $id]);
            }
            if (is_array($roles)) {
                $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$id]);
                $roleMap = [];
                foreach ($pdo->query("SELECT id, code FROM roles")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $roleMap[$r['code']] = $r['id'];
                }
                $insRole = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                foreach ($roles as $code) {
                    if (isset($roleMap[$code])) {
                        $insRole->execute([$id, $roleMap[$code]]);
                    }
                }
            }
            if (is_array($departmentIds)) {
                $pdo->prepare("DELETE FROM user_departments WHERE user_id = ?")->execute([$id]);
                $insDept = $pdo->prepare("INSERT INTO user_departments (user_id, department_id, is_primary) VALUES (?, ?, ?)");
                foreach ($departmentIds as $i => $deptId) {
                    if ($deptId) {
                        $insDept->execute([$id, (int) $deptId, $i === 0 ? 1 : 0]);
                    }
                }
            }
            $pdo->commit();
            $stmt = $pdo->prepare("SELECT id, email, full_name, is_active FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $roleStmt = $pdo->prepare("SELECT r.code FROM roles r JOIN user_roles ur ON r.id = ur.role_id WHERE ur.user_id = ?");
            $roleStmt->execute([$id]);
            $row['roles'] = array_column($roleStmt->fetchAll(PDO::FETCH_ASSOC), 'code');
            $deptStmt = $pdo->prepare("SELECT d.id, d.code, d.name FROM departments d JOIN user_departments ud ON d.id = ud.department_id WHERE ud.user_id = ?");
            $deptStmt->execute([$id]);
            $row['departments'] = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(['data' => $row]);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    jsonError('Method not allowed', 405);
};
