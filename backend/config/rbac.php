<?php

/**
 * RBAC - endpoint to required roles
 * Public: auth/login, auth/logout
 * Resource read/write permissions are enforced in backend/api/index.php.
 */

return [
    'public' => ['auth', 'confirm'],
    'orders' => [
        'read' => ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'SuperAdmin'],
        'write' => ['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin'],
        'approve' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
        'receive' => ['WarehouseStaff', 'SuperAdmin'],
        'confirm' => ['ChinaAdmin', 'LebanonAdmin', 'WarehouseStaff', 'SuperAdmin'],
    ],
    'customers' => [
        'read' => ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'SuperAdmin'],
        'write' => ['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin'],
    ],
    'products' => [
        'read' => ['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin'],
        'write' => ['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin'],
    ],
    'hs-code-tax' => [
        'read' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
        'write' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
    ],
    'containers' => [
        'read' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
        'write' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
    ],
    'shipment-drafts' => [
        'create' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
        'finalize' => ['LebanonAdmin', 'SuperAdmin'],
        'push' => ['LebanonAdmin', 'SuperAdmin'],
    ],
    'users' => ['SuperAdmin'],
    'config' => ['SuperAdmin'],
    'expenses' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
    'financials' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
    'internal-messages' => ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'SuperAdmin', 'WarehouseStaff'],
    'warehouse-stock' => ['WarehouseStaff', 'ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
    'procurement-drafts' => ['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin'],
    'business-settings' => ['SuperAdmin'],
    'customer-portal-tokens' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
    'design-attachments' => ['ChinaAdmin', 'ChinaEmployee', 'WarehouseStaff', 'SuperAdmin'],
];
