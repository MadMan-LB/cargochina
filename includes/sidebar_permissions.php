<?php

require_once __DIR__ . '/../backend/config/database.php';

function clmsSidebarConfigKey(): string
{
    return 'ROLE_SIDEBAR_PAGES_JSON';
}

function clmsSidebarPageRegistry(): array
{
    static $registry = null;
    if ($registry !== null) {
        return $registry;
    }

    $registry = [
        'dashboard' => [
            'title' => 'Dashboard',
            'description' => 'Overview and daily work queue.',
            'href' => '/cargochina/index.php',
            'section' => 'main',
            'icon_svg' => '<svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z" /></svg>',
            'default_roles' => ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'FieldStaff'],
        ],
        'orders' => [
            'title' => 'Orders',
            'description' => 'Create and manage orders.',
            'href' => '/cargochina/orders.php',
            'section' => 'main',
            'icon_svg' => '<svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6zm4 18H6V4h7v5h5v11z" /></svg>',
            'default_roles' => ['ChinaAdmin', 'ChinaEmployee'],
        ],
        'receiving' => [
            'title' => 'Receiving',
            'description' => 'Warehouse receiving queue.',
            'href' => '/cargochina/receiving.php',
            'section' => 'main',
            'icon_svg' => '<svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M20 6h-4V4c0-1.1-.9-2-2-2h-4c-1.1 0-2 .9-2 2v2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zm-8-2h4v2h-4V4zm8 16H4V8h16v12z" /></svg>',
            'default_roles' => ['WarehouseStaff'],
        ],
        'pipeline' => [
            'title' => 'Pipeline',
            'description' => 'Operational stage tracking.',
            'href' => '/cargochina/pipeline.php',
            'section' => 'main',
            'icon_svg' => '<svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z" /></svg>',
            'default_roles' => ['ChinaAdmin', 'LebanonAdmin'],
        ],
        'consolidation' => [
            'title' => 'Consolidation',
            'description' => 'Shipment draft and consolidation work.',
            'href' => '/cargochina/consolidation.php',
            'section' => 'main',
            'icon_svg' => '<svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-1 9h-4v4h-2v-4H9v4H7v-4H3V9h4V5h2v4h4V5h2v4h4v2z" /></svg>',
            'default_roles' => ['ChinaAdmin', 'LebanonAdmin', 'ContainersStaff'],
        ],
        'containers' => [
            'title' => 'Containers',
            'description' => 'Manage container list and status.',
            'href' => '/cargochina/containers.php',
            'section' => 'main',
            'icon_svg' => '<svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M20 8h-3V4H3c-1.1 0-2 .9-2 2v11h2c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3 3s3-1.34 3-3h2v-5l-3-4zM6 18.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zm13.5-9l1.96 2.5H17V9.5h2.5zm-1.5 9c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z" /></svg>',
            'default_roles' => ['ChinaAdmin', 'LebanonAdmin', 'ContainersStaff'],
        ],
        'assign_container' => [
            'title' => 'Assign to Container',
            'description' => 'Assign eligible orders into containers.',
            'href' => '/cargochina/assign_container.php',
            'section' => 'main',
            'icon_svg' => '<svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M19 3H5a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2V5a2 2 0 00-2-2zm-7 3a1 1 0 011 1v3h3a1 1 0 010 2h-3v3a1 1 0 01-2 0v-3H8a1 1 0 010-2h3V7a1 1 0 011-1z" /></svg>',
            'default_roles' => ['ChinaAdmin', 'LebanonAdmin', 'ContainersStaff'],
        ],
        'expenses' => [
            'title' => 'Expenses',
            'description' => 'Order and warehouse expenses.',
            'href' => '/cargochina/expenses.php',
            'section' => 'main',
            'icon_svg' => '<svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z" /></svg>',
            'default_roles' => ['ChinaAdmin', 'LebanonAdmin', 'WarehouseStaff'],
        ],
        'financials' => [
            'title' => 'Financials',
            'description' => 'Receivables, payables, and balances.',
            'href' => '/cargochina/financials.php',
            'section' => 'main',
            'icon_svg' => '<svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M3.5 18.49l6-6.01 4 4L22 6.92l-1.41-1.41-7.09 7.97-4-4L2 16.99z" /></svg>',
            'default_roles' => ['ChinaAdmin', 'LebanonAdmin'],
        ],
        'hs_code_tax' => [
            'title' => 'HS Code Tax',
            'description' => 'HS and tariff planning tools.',
            'href' => '/cargochina/hs_code_tax.php',
            'section' => 'main',
            'icon_svg' => '<svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M4 4h16v2H4zm0 7h10v2H4zm0 7h16v2H4zm12-8h4v6h-4z" /></svg>',
            'default_roles' => ['ChinaAdmin', 'LebanonAdmin'],
        ],
        'calendar' => [
            'title' => 'Calendar',
            'description' => 'Timeline and date visibility.',
            'href' => '/cargochina/calendar.php',
            'section' => 'main',
            'icon_svg' => '<svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10zm0-12H5V6h14v2z" /></svg>',
            'default_roles' => ['ChinaAdmin', 'LebanonAdmin'],
        ],
        'warehouse_stock' => [
            'title' => 'Warehouse Stock',
            'description' => 'Operational warehouse stock view.',
            'href' => '/cargochina/warehouse_stock.php',
            'section' => 'main',
            'icon_svg' => '<svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M20 8h-4V4H3c-1.1 0-2 .9-2 2v11h2c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3 3s3-1.34 3-3h2v-5l-3-4z" /></svg>',
            'default_roles' => ['ChinaAdmin', 'LebanonAdmin', 'WarehouseStaff', 'ContainersStaff'],
        ],
        'procurement_drafts' => [
            'title' => 'Draft an Order',
            'description' => 'Procurement draft builder.',
            'href' => '/cargochina/procurement_drafts.php',
            'section' => 'main',
            'icon_svg' => '<svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6zm4 18H6V4h7v5h5v11z" /></svg>',
            'default_roles' => ['ChinaAdmin', 'ChinaEmployee'],
        ],
        'downloads' => [
            'title' => 'Downloads',
            'description' => 'Template and export downloads.',
            'href' => '/cargochina/downloads.php',
            'section' => 'main',
            'icon_svg' => '<svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M5 20h14v-2H5v2zm7-18l-5 5h3v6h4V7h3l-5-5zm-7 9H3v6c0 1.1.9 2 2 2h2v-2H5v-6zm16 0h-2v6h-2v2h2c1.1 0 2-.9 2-2v-6z" /></svg>',
            'default_roles' => ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff'],
        ],
        'suppliers' => [
            'title' => 'Suppliers',
            'description' => 'Supplier master data.',
            'href' => '/cargochina/suppliers.php',
            'section' => 'data',
            'icon_svg' => '<svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M20 8h-3V4H3c-1.1 0-2 .9-2 2v11h2c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3 3s3-1.34 3-3h2v-5l-3-4zM6 18.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zm13.5-9l1.96 2.5H17V9.5h2.5zm-1.5 9c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z" /></svg>',
            'default_roles' => ['ChinaAdmin', 'ChinaEmployee', 'FieldStaff'],
        ],
        'customers' => [
            'title' => 'Customers',
            'description' => 'Customer master data and balances.',
            'href' => '/cargochina/customers.php',
            'section' => 'data',
            'icon_svg' => '<svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z" /></svg>',
            'default_roles' => ['ChinaAdmin'],
        ],
        'products' => [
            'title' => 'Products',
            'description' => 'Product catalog and pricing.',
            'href' => '/cargochina/products.php',
            'section' => 'data',
            'icon_svg' => '<svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M12 2l-5.5 9h11L12 2zm0 3.84L13.93 9h-3.87L12 5.84zM17.5 13c-2.49 0-4.5 2.01-4.5 4.5s2.01 4.5 4.5 4.5 4.5-2.01 4.5-4.5-2.01-4.5-4.5-4.5zm0 7a2.5 2.5 0 010-5 2.5 2.5 0 010 5zM3 21.5h8v-8H3v8zm2-6h4v4H5v-4z" /></svg>',
            'default_roles' => ['ChinaAdmin', 'ChinaEmployee'],
        ],
        'notifications' => [
            'title' => 'Notifications',
            'description' => 'Operational notifications feed.',
            'href' => '/cargochina/notifications.php',
            'section' => 'notifications',
            'icon_svg' => '<svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M12 22c1.1 0 2-.9 2-2h-4a2 2 0 002 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z" /></svg>',
            'default_roles' => ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'FieldStaff'],
        ],
        'notification_preferences' => [
            'title' => 'Preferences',
            'description' => 'Notification preferences and alerts.',
            'href' => '/cargochina/notification_preferences.php',
            'section' => 'notifications',
            'icon_svg' => '<svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58a.49.49 0 00.12-.61l-1.92-3.32a.49.49 0 00-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54a.484.484 0 00-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.62-.07.94s.02.64.07.94l-2.03 1.58a.49.49 0 00-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6A3.6 3.6 0 1115.6 12 3.6 3.6 0 0112 15.6z" /></svg>',
            'default_roles' => ['ChinaAdmin', 'LebanonAdmin'],
        ],
        'business_settings' => [
            'title' => 'Business Settings',
            'description' => 'Core business rules and presets.',
            'href' => '/cargochina/business_settings.php',
            'section' => 'administration',
            'icon_svg' => '<svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z" /></svg>',
            'default_roles' => ['SuperAdmin'],
            'superadmin_only' => true,
        ],
        'admin_config' => [
            'title' => 'Configuration',
            'description' => 'System-wide configuration.',
            'href' => '/cargochina/admin_config.php',
            'section' => 'administration',
            'icon_svg' => '<svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M17 11c.34 0 .67.04 1 .09V6.27L10.5 3 3 6.27v4.91c0 4.54 3.2 8.79 7.5 9.82.55-.13 1.08-.32 1.6-.55-.69-.98-1.1-2.17-1.1-3.45 0-3.31 2.69-6 6-6z" /><path d="M17 13c-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4-1.79-4-4-4zm0 1.38c.62 0 1.12.51 1.12 1.12s-.51 1.12-1.12 1.12-1.12-.51-1.12-1.12.5-1.12 1.12-1.12zm0 5.37c-.93 0-1.74-.46-2.24-1.17.05-.72 1.51-1.08 2.24-1.08s2.19.36 2.24 1.08c-.5.71-1.31 1.17-2.24 1.17z" /></svg>',
            'default_roles' => ['SuperAdmin'],
            'superadmin_only' => true,
        ],
        'admin_users' => [
            'title' => 'Users',
            'description' => 'Manage employees, roles, and sidebar access.',
            'href' => '/cargochina/admin_users.php',
            'section' => 'administration',
            'icon_svg' => '<svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" /></svg>',
            'default_roles' => ['SuperAdmin'],
            'superadmin_only' => true,
        ],
        'admin_diagnostics' => [
            'title' => 'Diagnostics',
            'description' => 'Environment and health diagnostics.',
            'href' => '/cargochina/admin_diagnostics.php',
            'section' => 'administration',
            'icon_svg' => '<svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M19.35 10.04A7.49 7.49 0 0012 4C9.11 4 6.6 5.64 5.35 8.04A5.994 5.994 0 000 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96z" /></svg>',
            'default_roles' => ['SuperAdmin'],
            'superadmin_only' => true,
        ],
        'admin_tracking' => [
            'title' => 'Tracking Push Log',
            'description' => 'Tracking system push history.',
            'href' => '/cargochina/admin_tracking_push.php',
            'section' => 'administration',
            'icon_svg' => '<svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M21 3H3c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h18c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H3V5h18v14zM5 15h14v2H5zm0-4h14v2H5zm0-4h14v2H5z" /></svg>',
            'default_roles' => ['SuperAdmin'],
            'superadmin_only' => true,
        ],
        'admin_audit' => [
            'title' => 'Audit Log',
            'description' => 'Cross-system audit log visibility.',
            'href' => '/cargochina/admin_audit_log.php',
            'section' => 'administration',
            'icon_svg' => '<svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z" /></svg>',
            'default_roles' => ['SuperAdmin'],
            'superadmin_only' => true,
        ],
    ];

    return $registry;
}

function clmsSidebarSectionLabels(): array
{
    return [
        'main' => 'Main',
        'data' => 'Data',
        'notifications' => 'Notifications',
        'administration' => 'Administration',
    ];
}

function clmsSidebarScriptMap(): array
{
    return [
        'index.php' => 'dashboard',
        'orders.php' => 'orders',
        'receiving.php' => 'receiving',
        'pipeline.php' => 'pipeline',
        'consolidation.php' => 'consolidation',
        'containers.php' => 'containers',
        'assign_container.php' => 'assign_container',
        'expenses.php' => 'expenses',
        'financials.php' => 'financials',
        'hs_code_tax.php' => 'hs_code_tax',
        'calendar.php' => 'calendar',
        'warehouse_stock.php' => 'warehouse_stock',
        'procurement_drafts.php' => 'procurement_drafts',
        'downloads.php' => 'downloads',
        'suppliers.php' => 'suppliers',
        'customers.php' => 'customers',
        'products.php' => 'products',
        'notifications.php' => 'notifications',
        'notification_preferences.php' => 'notification_preferences',
        'business_settings.php' => 'business_settings',
        'admin_config.php' => 'admin_config',
        'admin_users.php' => 'admin_users',
        'admin_diagnostics.php' => 'admin_diagnostics',
        'admin_tracking_push.php' => 'admin_tracking',
        'admin_audit_log.php' => 'admin_audit',
        'configuration.php' => 'admin_config',
        'diagnostics.php' => 'admin_diagnostics',
        'tracking-push-log.php' => 'admin_tracking',
        'users.php' => 'admin_users',
    ];
}

function clmsResolveCurrentPageId(?string $scriptPath = null): ?string
{
    $scriptPath = $scriptPath ?? ($_SERVER['PHP_SELF'] ?? '');
    $normalizedPath = '/' . ltrim(str_replace('\\', '/', strtolower($scriptPath)), '/');

    if (str_contains($normalizedPath, '/warehouse/receiving/')) {
        return 'receiving';
    }
    if (str_ends_with($normalizedPath, '/warehouse/expenses.php')) {
        return 'expenses';
    }
    if (str_ends_with($normalizedPath, '/buyers/index.php')
        || str_ends_with($normalizedPath, '/warehouse/index.php')
        || str_ends_with($normalizedPath, '/admin/index.php')
        || str_ends_with($normalizedPath, '/superadmin/index.php')) {
        return 'dashboard';
    }

    $basename = basename($normalizedPath);
    $map = clmsSidebarScriptMap();
    return $map[$basename] ?? null;
}

function clmsGetAssignablePageIdsForRole(string $roleCode): array
{
    if ($roleCode === 'SuperAdmin') {
        return array_keys(clmsSidebarPageRegistry());
    }

    $ids = [];
    foreach (clmsSidebarPageRegistry() as $pageId => $meta) {
        if (!empty($meta['superadmin_only'])) {
            continue;
        }
        $ids[] = $pageId;
    }
    return $ids;
}

function clmsGetDefaultPageIdsForRole(string $roleCode): array
{
    if ($roleCode === 'SuperAdmin') {
        return array_keys(clmsSidebarPageRegistry());
    }

    $ids = [];
    foreach (clmsSidebarPageRegistry() as $pageId => $meta) {
        if (!empty($meta['superadmin_only'])) {
            continue;
        }
        if (in_array($roleCode, $meta['default_roles'] ?? [], true)) {
            $ids[] = $pageId;
        }
    }
    return $ids;
}

function clmsLoadRoleSidebarPageSettings(?PDO $pdo = null): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    try {
        $pdo = $pdo ?: getDb();
        $stmt = @$pdo->prepare("SELECT key_value FROM system_config WHERE key_name = ? LIMIT 1");
        if (!$stmt) {
            return $cache;
        }
        $stmt->execute([clmsSidebarConfigKey()]);
        $raw = $stmt->fetchColumn();
        if ($raw === false || trim((string) $raw) === '') {
            return $cache;
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return $cache;
        }
        foreach ($decoded as $roleCode => $pageIds) {
            if (!is_string($roleCode) || !is_array($pageIds)) {
                continue;
            }
            $cache[$roleCode] = array_values(array_unique(array_filter(array_map('strval', $pageIds))));
        }
    } catch (Throwable $e) {
        $cache = [];
    }

    return $cache;
}

function clmsInvalidateRoleSidebarPageSettingsCache(): void
{
    $func = new ReflectionFunction('clmsLoadRoleSidebarPageSettings');
    $staticVariables = $func->getStaticVariables();
    if (array_key_exists('cache', $staticVariables)) {
        // no-op placeholder: request-scoped cache only
    }
}

function clmsSanitizeRoleSidebarPageSettings(array $settings): array
{
    $sanitized = [];
    foreach ($settings as $roleCode => $pageIds) {
        if (!is_string($roleCode) || $roleCode === 'SuperAdmin') {
            continue;
        }
        $assignable = clmsGetAssignablePageIdsForRole($roleCode);
        if (!$assignable) {
            continue;
        }
        $pageIds = is_array($pageIds) ? $pageIds : [];
        $pageIds = array_values(array_unique(array_intersect($assignable, array_map('strval', $pageIds))));
        $sanitized[$roleCode] = $pageIds;
    }
    return $sanitized;
}

function clmsSaveRoleSidebarPageSettings(PDO $pdo, array $settings): void
{
    $sanitized = clmsSanitizeRoleSidebarPageSettings($settings);
    $stmt = $pdo->prepare("INSERT INTO system_config (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
    $stmt->execute([clmsSidebarConfigKey(), json_encode($sanitized, JSON_UNESCAPED_UNICODE)]);
}

function clmsGetEffectivePageIdsForRole(string $roleCode, ?PDO $pdo = null): array
{
    if ($roleCode === 'SuperAdmin') {
        return array_keys(clmsSidebarPageRegistry());
    }

    $assignable = clmsGetAssignablePageIdsForRole($roleCode);
    if (!$assignable) {
        return [];
    }

    $settings = clmsLoadRoleSidebarPageSettings($pdo);
    if (array_key_exists($roleCode, $settings)) {
        return array_values(array_intersect($assignable, $settings[$roleCode]));
    }

    return clmsGetDefaultPageIdsForRole($roleCode);
}

function clmsGetEffectivePageIdsForRoles(array $roleCodes, ?PDO $pdo = null): array
{
    if (in_array('SuperAdmin', $roleCodes, true)) {
        return array_keys(clmsSidebarPageRegistry());
    }

    $orderedRegistryIds = array_keys(clmsSidebarPageRegistry());
    $visible = [];
    foreach ($roleCodes as $roleCode) {
        foreach (clmsGetEffectivePageIdsForRole((string) $roleCode, $pdo) as $pageId) {
            $visible[$pageId] = true;
        }
    }

    return array_values(array_filter($orderedRegistryIds, static fn($pageId) => isset($visible[$pageId])));
}

function clmsCanRolesAccessPage(array $roleCodes, string $pageId, ?PDO $pdo = null): bool
{
    if ($pageId === '') {
        return true;
    }
    return in_array($pageId, clmsGetEffectivePageIdsForRoles($roleCodes, $pdo), true);
}

function clmsGetSidebarSectionsForRoles(array $roleCodes, ?PDO $pdo = null): array
{
    $registry = clmsSidebarPageRegistry();
    $labels = clmsSidebarSectionLabels();
    $allowedIds = clmsGetEffectivePageIdsForRoles($roleCodes, $pdo);

    $sections = [];
    foreach ($labels as $sectionId => $label) {
        $pages = [];
        foreach ($allowedIds as $pageId) {
            $meta = $registry[$pageId] ?? null;
            if ($meta && ($meta['section'] ?? '') === $sectionId) {
                $pages[$pageId] = $meta;
            }
        }
        if ($pages) {
            $sections[$sectionId] = [
                'label' => $label,
                'pages' => $pages,
            ];
        }
    }

    return $sections;
}

function clmsGetAccessibleHomeUrl(array $roleCodes, ?PDO $pdo = null): string
{
    $registry = clmsSidebarPageRegistry();
    $pageIds = clmsGetEffectivePageIdsForRoles($roleCodes, $pdo);
    if (!empty($pageIds)) {
        $firstPageId = $pageIds[0];
        if (!empty($registry[$firstPageId]['href'])) {
            return $registry[$firstPageId]['href'];
        }
    }
    return '/cargochina/login.php?logout=1';
}
