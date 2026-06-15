<?php

/**
 * Diagnostics API - SuperAdmin only
 * GET /diagnostics/notification-delivery-log — list with filters
 * GET /diagnostics/config-health — config readiness booleans
 * POST /diagnostics/retry-delivery/{logId} — retry failed delivery
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 3) . '/includes/sidebar_permissions.php';
require_once dirname(__DIR__, 2) . '/services/NotificationService.php';

function diagnosticsTableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool) $stmt->rowCount();
    } catch (Throwable $e) {
        return false;
    }
}

function diagnosticsTableHasColumn(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool) $stmt->rowCount();
    } catch (Throwable $e) {
        return false;
    }
}

function diagnosticsCountRows(PDO $pdo, string $table): ?int
{
    if (!diagnosticsTableExists($pdo, $table)) {
        return null;
    }
    try {
        return (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
}

return function (string $method, ?string $id, ?string $action, array $input) {
    requireRole(['SuperAdmin']);
    $pdo = getDb();

    if ($id === 'notification-delivery-log') {
        if ($method === 'GET') {
            $status = $_GET['status'] ?? null;
            $channel = $_GET['channel'] ?? null;
            $limit = min(100, max(1, (int) ($_GET['limit'] ?? 100)));
            $offset = max(0, (int) ($_GET['offset'] ?? 0));
            $dateFrom = $_GET['date_from'] ?? null;
            $dateTo = $_GET['date_to'] ?? null;

            $sql = "SELECT ndl.id, ndl.notification_id, ndl.channel, ndl.payload_hash, ndl.status, ndl.attempts, ndl.last_error, ndl.external_id, ndl.created_at, n.type as event_type, n.title
                FROM notification_delivery_log ndl
                JOIN notifications n ON ndl.notification_id = n.id
                WHERE 1=1";
            $params = [];
            if ($status) {
                $sql .= " AND ndl.status = ?";
                $params[] = $status;
            }
            if ($channel) {
                $sql .= " AND ndl.channel = ?";
                $params[] = $channel;
            }
            if ($dateFrom) {
                $sql .= " AND ndl.created_at >= ?";
                $params[] = $dateFrom;
            }
            if ($dateTo) {
                $sql .= " AND ndl.created_at <= ?";
                $params[] = $dateTo . ' 23:59:59';
            }
            $sql .= " ORDER BY ndl.created_at DESC LIMIT " . (int) $limit . " OFFSET " . (int) $offset;

            $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
            if ($params) $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                unset($r['payload_hash']);
            }
            jsonResponse(['data' => $rows]);
        }
    }

    if ($id === 'config-health') {
        if ($method === 'GET') {
            $config = require dirname(__DIR__, 2) . '/config/config.php';
            $emailConfigured = trim($config['email_from_address'] ?? '') !== '';
            $provider = $config['whatsapp_provider'] ?? 'generic';
            $waUrl = trim($config['whatsapp_api_url'] ?? '');
            $waToken = trim($config['whatsapp_api_token'] ?? '');
            $waSid = trim($config['whatsapp_twilio_account_sid'] ?? '');
            $waAuth = trim($config['whatsapp_twilio_auth_token'] ?? '');
            $waFrom = trim($config['whatsapp_twilio_from'] ?? '');
            $waTo = trim($config['whatsapp_twilio_to'] ?? '');
            $whatsappConfigured = ($provider === 'generic' && $waUrl !== '' && $waToken !== '')
                || ($provider === 'twilio' && $waSid !== '' && $waAuth !== '' && $waFrom !== '' && $waTo !== '');
            $itemLevelEnabled = (int) ($config['item_level_receiving_enabled'] ?? 0);
            $retryConfigured = ((int) ($config['notification_max_attempts'] ?? 3)) >= 1 && ((int) ($config['notification_retry_seconds'] ?? 60)) >= 1;
            jsonResponse(['data' => [
                'email_configured' => $emailConfigured,
                'whatsapp_configured' => $whatsappConfigured,
                'item_level_enabled' => (bool) $itemLevelEnabled,
                'retry_configured' => $retryConfigured,
            ]]);
        }
    }

    if ($id === 'balances-deployment') {
        if ($method === 'GET') {
            $roles = getUserRoles();
            $sidebarSettings = clmsLoadRoleSidebarPageSettings($pdo);
            $roleBalanceAccess = [];
            foreach (['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'ContainersStaff', 'FieldStaff', 'SuperAdmin'] as $role) {
                $roleBalanceAccess[$role] = clmsCanRolesAccessPage([$role], 'balances', $pdo);
            }
            $rolesExpectedByDefault = ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'SuperAdmin'];
            $migrationNames = [
                '061_balance_transactions.sql',
                '062_balance_sidebar_defaults.sql',
                '063_balance_transaction_payment_accounts.sql',
                '064_balance_order_linked_deposits.sql',
                '065_balances_deployment_hardening.sql',
                '066_balance_invoice_documents.sql',
            ];
            $appliedMigrations = [];
            if (diagnosticsTableExists($pdo, '_migrations')) {
                $placeholders = implode(',', array_fill(0, count($migrationNames), '?'));
                $stmt = $pdo->prepare("SELECT name, applied_at FROM _migrations WHERE name IN ($placeholders)");
                $stmt->execute($migrationNames);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $appliedMigrations[$row['name']] = $row['applied_at'];
                }
            }

            $balanceColumns = [];
            foreach ([
                'party_type',
                'party_id',
                'order_id',
                'order_reference',
                'transaction_type',
                'direction',
                'amount',
                'currency',
                'payment_method',
                'payment_account_label',
                'payment_account_value',
                'payment_account_qr_path',
                'reference_number',
                'transaction_date',
            ] as $column) {
                $balanceColumns[$column] = diagnosticsTableHasColumn($pdo, 'balance_transactions', $column);
            }

            $depositCheckPresent = false;
            $invoiceCheckPresent = false;
            try {
                $stmt = $pdo->prepare("
                    SELECT CHECK_CLAUSE
                    FROM information_schema.CHECK_CONSTRAINTS
                    WHERE CONSTRAINT_SCHEMA = DATABASE()
                      AND CONSTRAINT_NAME = 'chk_balance_tx_type'
                    LIMIT 1
                ");
                $stmt->execute();
                $clause = (string) ($stmt->fetchColumn() ?: '');
                $depositCheckPresent = $clause === '' || stripos($clause, 'deposit') !== false;
                $invoiceCheckPresent = $clause === '' || stripos($clause, 'invoice') !== false;
            } catch (Throwable $e) {
                $depositCheckPresent = true;
                $invoiceCheckPresent = true;
            }
            $sidebarPermissionsPath = dirname(__DIR__, 3) . '/includes/sidebar_permissions.php';

            jsonResponse(['data' => [
                'current_user_roles' => $roles,
                'current_user_can_access_balances' => clmsCanRolesAccessPage($roles, 'balances', $pdo, getAuthUserId()),
                'current_user_visible_pages' => clmsGetEffectivePageIdsForRoles($roles, $pdo, getAuthUserId()),
                'registry_has_balances' => array_key_exists('balances', clmsSidebarPageRegistry()),
                'script_map_has_balances' => (clmsSidebarScriptMap()['balances.php'] ?? null) === 'balances',
                'role_balance_access' => $roleBalanceAccess,
                'role_balance_access_expected_by_default' => array_fill_keys($rolesExpectedByDefault, true),
                'sidebar_settings_roles' => array_keys($sidebarSettings),
                'sidebar_settings_contains_balances' => array_map(
                    static fn($pages) => is_array($pages) && in_array('balances', $pages, true),
                    $sidebarSettings
                ),
                'tables' => [
                    'balance_transactions' => diagnosticsTableExists($pdo, 'balance_transactions'),
                    'customer_deposits' => diagnosticsTableExists($pdo, 'customer_deposits'),
                    'supplier_payments' => diagnosticsTableExists($pdo, 'supplier_payments'),
                    'orders' => diagnosticsTableExists($pdo, 'orders'),
                    'order_items' => diagnosticsTableExists($pdo, 'order_items'),
                ],
                'balance_transaction_columns' => $balanceColumns,
                'deposit_transaction_type_allowed' => $depositCheckPresent,
                'invoice_transaction_type_allowed' => $invoiceCheckPresent,
                'row_counts' => [
                    'customers' => diagnosticsCountRows($pdo, 'customers'),
                    'suppliers' => diagnosticsCountRows($pdo, 'suppliers'),
                    'orders' => diagnosticsCountRows($pdo, 'orders'),
                    'order_items' => diagnosticsCountRows($pdo, 'order_items'),
                    'customer_deposits' => diagnosticsCountRows($pdo, 'customer_deposits'),
                    'supplier_payments' => diagnosticsCountRows($pdo, 'supplier_payments'),
                    'balance_transactions' => diagnosticsCountRows($pdo, 'balance_transactions'),
                ],
                'migrations' => array_map(
                    static fn($name) => [
                        'name' => $name,
                        'applied' => array_key_exists($name, $appliedMigrations),
                        'applied_at' => $appliedMigrations[$name] ?? null,
                    ],
                    $migrationNames
                ),
                'asset_versions' => [
                    'orders_js' => @filemtime(dirname(__DIR__, 3) . '/frontend/js/orders.js') ?: null,
                    'balances_js' => @filemtime(dirname(__DIR__, 3) . '/frontend/js/balances.js') ?: null,
                    'style_css' => @filemtime(dirname(__DIR__, 3) . '/frontend/css/style.css') ?: null,
                    'sidebar_permissions_php' => @filemtime($sidebarPermissionsPath) ?: null,
                    'orders_php' => @filemtime(dirname(__DIR__, 3) . '/orders.php') ?: null,
                ],
                'source_fingerprints' => [
                    'sidebar_permissions_sha1' => is_file($sidebarPermissionsPath) ? sha1_file($sidebarPermissionsPath) : null,
                    'balances_meta_helper_loaded' => function_exists('clmsBalancesSidebarPageMeta'),
                    'balances_registry_guard_loaded' => function_exists('clmsEnsureBalancesRegistryEntry'),
                ],
            ]]);
        }
    }

    if ($id === 'retry-delivery' && $method === 'POST' && is_numeric($action)) {
        $logId = (int) $action;
        $svc = new \NotificationService($pdo);
        $result = $svc->retryDelivery($logId);
        jsonResponse(['data' => $result]);
    }

    jsonError('Not found', 404);
};
