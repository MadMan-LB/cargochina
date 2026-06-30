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

function buildUserPermissionOverrideRegistry(PDO $pdo): array
{
    $registry = [];

    foreach (clmsSidebarPageRegistry() as $pageId => $meta) {
        if (!empty($meta['superadmin_only'])) {
            continue;
        }
        $key = clmsNormalizePermissionKey('page:' . $pageId);
        $registry[$key] = [
            'key' => $key,
            'label' => 'View ' . ($meta['title'] ?? $pageId) . ' page',
            'description' => $meta['description'] ?? '',
            'section' => 'Pages',
            'default_roles' => !empty($meta['superadmin_only']) ? ['SuperAdmin'] : array_values(array_unique(array_merge($meta['default_roles'] ?? [], ['SuperAdmin']))),
        ];
    }

    $rbac = require dirname(__DIR__, 2) . '/config/rbac.php';
    foreach ($rbac as $resource => $definition) {
        if ($resource === 'public') {
            continue;
        }
        $isList = is_array($definition) && array_keys($definition) === range(0, count($definition) - 1);
        if ($isList) {
            continue;
        }
        if (!is_array($definition)) {
            continue;
        }
        foreach ($definition as $action => $roles) {
            if (!is_array($roles)) {
                continue;
            }
            $key = clmsNormalizePermissionKey($resource . '.' . $action);
            $registry[$key] = [
                'key' => $key,
                'label' => ucwords(str_replace('-', ' ', $resource)) . ' ' . ucwords(str_replace('_', ' ', $action)),
                'description' => 'Override ' . $action . ' permission for ' . $resource . '.',
                'section' => 'Actions',
                'default_roles' => array_values(array_unique($roles)),
            ];
        }
    }

    $operationalRoles = ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'ContainersStaff', 'FieldStaff', 'SuperAdmin'];
    $manual = [
        'customers.create' => ['Add customer', 'Allow creating customers from the customer page.', 'Customer', $operationalRoles],
        'customers.import' => ['Import customers', 'Allow customer CSV import without changing the user role.', 'Customer', ['ChinaAdmin', 'SuperAdmin']],
        'page:customers' => ['View customer page', 'Allow opening the Customers page and seeing it in the sidebar.', 'Customer', $operationalRoles],
        'page:receiving' => ['View receiving page', 'Allow opening the Warehouse Receiving page and seeing it in the sidebar.', 'Receiving', $operationalRoles],
        'orders.receive' => ['Receiving from warehouse', 'Allow recording warehouse receipts for approved or in-transit orders.', 'Receiving', $operationalRoles],
        'receiving.import' => ['Import Excel receiving', 'Allow previewing and committing warehouse receiving Excel imports.', 'Receiving', $operationalRoles],
    ];
    foreach ($manual as $key => [$label, $description, $section, $defaultRoles]) {
        $key = clmsNormalizePermissionKey($key);
        if (!isset($registry[$key])) {
            $registry[$key] = ['key' => $key, 'default_roles' => []];
        }
        $registry[$key]['label'] = $label;
        $registry[$key]['description'] = $description;
        $registry[$key]['section'] = $section;
        $registry[$key]['default_roles'] = array_values(array_unique($defaultRoles));
    }

    uasort($registry, static function (array $a, array $b): int {
        $sectionCmp = strcmp((string) ($a['section'] ?? ''), (string) ($b['section'] ?? ''));
        if ($sectionCmp !== 0) {
            return $sectionCmp;
        }
        return strcmp((string) ($a['label'] ?? $a['key']), (string) ($b['label'] ?? $b['key']));
    });

    return $registry;
}

function loadUserPermissionOverrides(PDO $pdo, ?int $userId = null): array
{
    if (!clmsPermissionOverridesTableExists($pdo)) {
        return [];
    }
    $sql = "SELECT user_id, permission_key, is_allowed, notes, granted_by, created_at, updated_at FROM user_permission_overrides";
    $params = [];
    if ($userId !== null) {
        $sql .= " WHERE user_id = ?";
        $params[] = $userId;
    }
    $sql .= " ORDER BY user_id, permission_key";
    $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
    if ($params) {
        $stmt->execute($params);
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $grouped = [];
    foreach ($rows as $row) {
        $uid = (int) $row['user_id'];
        $row['permission_key'] = clmsNormalizePermissionKey((string) $row['permission_key']);
        $row['is_allowed'] = (int) $row['is_allowed'];
        $grouped[$uid][] = $row;
    }
    return $userId !== null ? ($grouped[$userId] ?? []) : $grouped;
}

function ensureUserPermissionOverrideTable(PDO $pdo): void
{
    if (!clmsPermissionOverridesTableExists($pdo)) {
        jsonError('Permission override table is missing. Run database migrations first.', 500);
    }
}

function ensureCustomerVisibilityTables(PDO $pdo): void
{
    if (!clmsCustomerCreatedByColumnExists($pdo)
        || !clmsCustomerVisibilityTableExists($pdo, 'customer_visibility_exceptions')
        || !clmsCustomerVisibilityTableExists($pdo, 'customer_visibility_allowed_creators')) {
        jsonError('Customer visibility tables are missing. Run database migrations first.', 500);
    }
}

function loadCustomerVisibilitySettings(PDO $pdo, ?int $userId = null): array
{
    ensureCustomerVisibilityTables($pdo);
    $sql = "SELECT user_id, can_see_all_customers, created_by, updated_by, created_at, updated_at FROM customer_visibility_exceptions";
    $params = [];
    if ($userId !== null) {
        $sql .= " WHERE user_id = ?";
        $params[] = $userId;
    }
    $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
    if ($params) {
        $stmt->execute($params);
    }
    $settings = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $uid = (int) $row['user_id'];
        $settings[$uid] = [
            'user_id' => $uid,
            'can_see_all_customers' => (int) $row['can_see_all_customers'],
            'allowed_creator_user_ids' => [],
            'created_by' => isset($row['created_by']) ? (int) $row['created_by'] : null,
            'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    $allowedSql = "SELECT user_id, allowed_creator_user_id FROM customer_visibility_allowed_creators";
    $allowedParams = [];
    if ($userId !== null) {
        $allowedSql .= " WHERE user_id = ?";
        $allowedParams[] = $userId;
    }
    $allowedStmt = $allowedParams ? $pdo->prepare($allowedSql) : $pdo->query($allowedSql);
    if ($allowedParams) {
        $allowedStmt->execute($allowedParams);
    }
    foreach ($allowedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $uid = (int) $row['user_id'];
        if (!isset($settings[$uid])) {
            $settings[$uid] = [
                'user_id' => $uid,
                'can_see_all_customers' => 0,
                'allowed_creator_user_ids' => [],
            ];
        }
        $settings[$uid]['allowed_creator_user_ids'][] = (int) $row['allowed_creator_user_id'];
    }

    foreach ($settings as &$setting) {
        $setting['allowed_creator_user_ids'] = array_values(array_unique($setting['allowed_creator_user_ids']));
        $setting['mode'] = !empty($setting['can_see_all_customers'])
            ? 'all'
            : (!empty($setting['allowed_creator_user_ids']) ? 'selected' : 'own');
    }
    unset($setting);

    return $userId !== null ? ($settings[$userId] ?? [
        'user_id' => $userId,
        'can_see_all_customers' => 0,
        'allowed_creator_user_ids' => [],
        'mode' => 'own',
    ]) : $settings;
}

return function (string $method, ?string $id, ?string $action, array $input) {
    requireRole(['SuperAdmin']);

    $pdo = getDb();

    if ($method === 'GET') {
        if ($id === 'permission-overrides' && $action === null) {
            ensureUserPermissionOverrideTable($pdo);
            $users = $pdo->query("SELECT id, email, full_name, is_active FROM users ORDER BY full_name, email")->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse([
                'data' => [
                    'registry' => buildUserPermissionOverrideRegistry($pdo),
                    'overrides' => loadUserPermissionOverrides($pdo),
                    'users' => $users,
                ],
            ]);
        }
        if ($id === 'customer-visibility' && $action === null) {
            ensureCustomerVisibilityTables($pdo);
            $users = $pdo->query("SELECT id, email, full_name, is_active FROM users ORDER BY full_name, email")->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse([
                'data' => [
                    'users' => $users,
                    'settings' => loadCustomerVisibilitySettings($pdo),
                    'full_visibility_roles' => clmsCustomerFullVisibilityRoles(),
                ],
            ]);
        }
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
        if ($id && $action === 'permission-overrides') {
            ensureUserPermissionOverrideTable($pdo);
            $userId = (int) $id;
            $chk = $pdo->prepare("SELECT id, email, full_name, is_active FROM users WHERE id = ?");
            $chk->execute([$userId]);
            $user = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$user) jsonError('User not found', 404);
            jsonResponse([
                'data' => [
                    'user' => $user,
                    'registry' => buildUserPermissionOverrideRegistry($pdo),
                    'overrides' => loadUserPermissionOverrides($pdo, $userId),
                ],
            ]);
        }
        if ($id && $action === 'customer-visibility') {
            ensureCustomerVisibilityTables($pdo);
            $userId = (int) $id;
            $chk = $pdo->prepare("SELECT id, email, full_name, is_active FROM users WHERE id = ?");
            $chk->execute([$userId]);
            $user = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$user) jsonError('User not found', 404);
            $users = $pdo->query("SELECT id, email, full_name, is_active FROM users ORDER BY full_name, email")->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse([
                'data' => [
                    'user' => $user,
                    'users' => $users,
                    'setting' => loadCustomerVisibilitySettings($pdo, $userId),
                    'full_visibility_roles' => clmsCustomerFullVisibilityRoles(),
                ],
            ]);
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
        if ($action === 'customer-visibility') {
            ensureCustomerVisibilityTables($pdo);
            $targetUserId = (int) $id;
            $chk = $pdo->prepare("SELECT id FROM users WHERE id = ?");
            $chk->execute([$targetUserId]);
            if (!$chk->fetch()) jsonError('User not found', 404);

            $mode = trim((string) ($input['mode'] ?? 'own'));
            if (!in_array($mode, ['own', 'selected', 'all'], true)) {
                jsonError('Invalid customer visibility mode', 400);
            }
            $allowedCreatorIds = is_array($input['allowed_creator_user_ids'] ?? null)
                ? array_values(array_unique(array_filter(array_map('intval', $input['allowed_creator_user_ids']))))
                : [];
            $allowedCreatorIds = array_values(array_filter($allowedCreatorIds, static fn($uid) => $uid > 0 && $uid !== $targetUserId));
            if ($mode !== 'selected') {
                $allowedCreatorIds = [];
            }
            if ($allowedCreatorIds) {
                $placeholders = implode(',', array_fill(0, count($allowedCreatorIds), '?'));
                $existsStmt = $pdo->prepare("SELECT id FROM users WHERE id IN ($placeholders)");
                $existsStmt->execute($allowedCreatorIds);
                $existingIds = array_map('intval', array_column($existsStmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
                $missing = array_values(array_diff($allowedCreatorIds, $existingIds));
                if ($missing) {
                    jsonError('Unknown creator user selected', 400, ['allowed_creator_user_ids' => implode(', ', $missing)]);
                }
            }

            $old = loadCustomerVisibilitySettings($pdo, $targetUserId);
            $adminUserId = getAuthUserId();
            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM customer_visibility_allowed_creators WHERE user_id = ?")->execute([$targetUserId]);
                if ($mode === 'own') {
                    $pdo->prepare("DELETE FROM customer_visibility_exceptions WHERE user_id = ?")->execute([$targetUserId]);
                } else {
                    $canSeeAll = $mode === 'all' ? 1 : 0;
                    $pdo->prepare(
                        "INSERT INTO customer_visibility_exceptions (user_id, can_see_all_customers, created_by, updated_by)
                         VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE can_see_all_customers = VALUES(can_see_all_customers), updated_by = VALUES(updated_by)"
                    )->execute([$targetUserId, $canSeeAll, $adminUserId, $adminUserId]);
                    if ($allowedCreatorIds) {
                        $ins = $pdo->prepare("INSERT INTO customer_visibility_allowed_creators (user_id, allowed_creator_user_id, created_by) VALUES (?, ?, ?)");
                        foreach ($allowedCreatorIds as $creatorUserId) {
                            $ins->execute([$targetUserId, $creatorUserId, $adminUserId]);
                        }
                    }
                }
                $new = loadCustomerVisibilitySettings($pdo, $targetUserId);
                $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, old_value, new_value, user_id) VALUES ('customer_visibility_exception', ?, 'update', ?, ?, ?)")
                    ->execute([
                        $targetUserId,
                        json_encode($old, JSON_UNESCAPED_UNICODE),
                        json_encode($new, JSON_UNESCAPED_UNICODE),
                        $adminUserId,
                    ]);
                logClms('customer_visibility_update', [
                    'target_user_id' => $targetUserId,
                    'user_id' => $adminUserId,
                    'mode' => $mode,
                    'allowed_creator_user_ids' => $allowedCreatorIds,
                ]);
                $pdo->commit();
                jsonResponse(['data' => ['user_id' => $targetUserId, 'setting' => $new]]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        if ($action === 'permission-overrides') {
            ensureUserPermissionOverrideTable($pdo);
            $targetUserId = (int) $id;
            $chk = $pdo->prepare("SELECT id FROM users WHERE id = ?");
            $chk->execute([$targetUserId]);
            if (!$chk->fetch()) jsonError('User not found', 404);

            $permissions = $input['permissions'] ?? $input['permission_keys'] ?? [];
            if (!is_array($permissions)) {
                jsonError('permissions must be an array', 400);
            }

            $registry = buildUserPermissionOverrideRegistry($pdo);
            $allowedKeys = array_keys($registry);
            $selected = array_values(array_unique(array_filter(array_map(
                static fn($key) => clmsNormalizePermissionKey((string) $key),
                $permissions
            ))));
            $invalid = array_values(array_diff($selected, $allowedKeys));
            if ($invalid) {
                jsonError('Unknown permission override key', 400, ['permissions' => implode(', ', $invalid)]);
            }

            $old = loadUserPermissionOverrides($pdo, $targetUserId);
            $adminUserId = getAuthUserId();
            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM user_permission_overrides WHERE user_id = ?")->execute([$targetUserId]);
                if ($selected) {
                    $ins = $pdo->prepare("INSERT INTO user_permission_overrides (user_id, permission_key, is_allowed, granted_by) VALUES (?, ?, 1, ?)");
                    foreach ($selected as $permissionKey) {
                        $ins->execute([$targetUserId, $permissionKey, $adminUserId]);
                    }
                }
                $new = loadUserPermissionOverrides($pdo, $targetUserId);
                $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, old_value, new_value, user_id) VALUES ('user_permission_override', ?, 'update', ?, ?, ?)")
                    ->execute([
                        $targetUserId,
                        json_encode(['permissions' => array_column($old, 'permission_key')], JSON_UNESCAPED_UNICODE),
                        json_encode(['permissions' => $selected], JSON_UNESCAPED_UNICODE),
                        $adminUserId,
                    ]);
                logClms('permission_overrides_update', [
                    'target_user_id' => $targetUserId,
                    'user_id' => $adminUserId,
                    'permissions' => $selected,
                ]);
                $pdo->commit();
                jsonResponse(['data' => ['user_id' => $targetUserId, 'overrides' => $new]]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

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
