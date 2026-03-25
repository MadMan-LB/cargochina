<?php

/**
 * RBAC - endpoint to required roles
 * Public: auth/login, auth/logout
 * Resource read/write permissions are enforced in backend/api/index.php.
 */

return [
    'public' => ['auth', 'confirm'],
    'orders' => [
        'read' => ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'ContainersStaff', 'SuperAdmin'],
        'write' => ['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin'],
        'approve' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
        'receive' => ['WarehouseStaff', 'SuperAdmin'],
        'confirm' => ['ChinaAdmin', 'LebanonAdmin', 'WarehouseStaff', 'SuperAdmin'],
    ],
    'customers' => [
        'read' => ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'ContainersStaff', 'SuperAdmin'],
        'write' => ['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin'],
        'create' => ['ChinaAdmin', 'SuperAdmin'],
        'import' => ['ChinaAdmin', 'SuperAdmin'],
    ],
    'products' => [
        'read' => ['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin'],
        'write' => ['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin'],
    ],
    'hs-code-tax' => [
        'read' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
        'write' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
    ],
    'hs-code-catalog' => [
        'read' => ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'SuperAdmin'],
        'write' => ['SuperAdmin'],
    ],
    'containers' => [
        'read' => ['ChinaAdmin', 'LebanonAdmin', 'ContainersStaff', 'SuperAdmin'],
        'write' => ['ChinaAdmin', 'LebanonAdmin', 'ContainersStaff', 'SuperAdmin'],
    ],
    'countries' => [
        'read' => ['ChinaAdmin', 'LebanonAdmin', 'ContainersStaff', 'SuperAdmin'],
    ],
    'shipment-drafts' => [
        'create' => ['ChinaAdmin', 'LebanonAdmin', 'ContainersStaff', 'SuperAdmin'],
        'finalize' => ['LebanonAdmin', 'ContainersStaff', 'SuperAdmin'],
        'push' => ['LebanonAdmin', 'ContainersStaff', 'SuperAdmin'],
    ],
    'users' => ['SuperAdmin'],
    'config' => ['SuperAdmin'],
    'expenses' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin', 'WarehouseStaff'],
    'financials' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
    'internal-messages' => ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'SuperAdmin', 'WarehouseStaff'],
    'warehouse-stock' => ['WarehouseStaff', 'ChinaAdmin', 'LebanonAdmin', 'ContainersStaff', 'SuperAdmin'],
    'procurement-drafts' => ['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin'],
    'draft-orders' => ['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin'],
    'business-settings' => ['SuperAdmin'],
    'customer-portal-tokens' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
    'design-attachments' => ['ChinaAdmin', 'ChinaEmployee', 'WarehouseStaff', 'SuperAdmin'],
];
