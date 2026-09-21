<?php

/**
 * RBAC - endpoint to required roles
 * Public: auth/login, auth/logout
 * Resource read/write permissions are enforced in backend/api/index.php.
 */

$operationalRoles = ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'ContainersStaff', 'FieldStaff', 'SuperAdmin'];

return [
    'public' => ['auth', 'confirm'],
    'orders' => [
        'read' => $operationalRoles,
        'write' => ['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin'],
        'approve' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
        'receive' => ['WarehouseStaff', 'SuperAdmin'],
        'confirm' => ['ChinaAdmin', 'LebanonAdmin', 'WarehouseStaff', 'SuperAdmin'],
    ],
    'customers' => [
        'read' => $operationalRoles,
        'lookup' => $operationalRoles,
        'write' => ['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin'],
        'create' => $operationalRoles,
        'import' => ['ChinaAdmin', 'SuperAdmin'],
    ],
    'products' => [
        'read' => $operationalRoles,
        'write' => ['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin'],
    ],
    'hs-code-tax' => [
        'read' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
        'write' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
    ],
    'hs-code-catalog' => [
        'read' => $operationalRoles,
        'write' => ['SuperAdmin'],
    ],
    'containers' => [
        'read' => ['ChinaAdmin', 'LebanonAdmin', 'ContainersStaff', 'SuperAdmin'],
        'write' => ['ChinaAdmin', 'LebanonAdmin', 'ContainersStaff', 'SuperAdmin'],
    ],
    'countries' => [
        'read' => $operationalRoles,
    ],
    'shipment-drafts' => [
        'create' => ['ChinaAdmin', 'LebanonAdmin', 'ContainersStaff', 'SuperAdmin'],
        'finalize' => ['LebanonAdmin', 'ContainersStaff', 'SuperAdmin'],
        'push' => ['LebanonAdmin', 'ContainersStaff', 'SuperAdmin'],
    ],
    'users' => ['SuperAdmin'],
    'owner-control' => ['SuperAdmin'],
    'client-incidents' => $operationalRoles,
    'config' => ['SuperAdmin'],
    'expenses' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin', 'WarehouseStaff'],
    'financials' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
    'balances' => [
        'read' => ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'SuperAdmin'],
        'write' => ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'SuperAdmin'],
    ],
    'internal-messages' => ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'SuperAdmin', 'WarehouseStaff'],
    'warehouse-stock' => ['WarehouseStaff', 'ChinaAdmin', 'LebanonAdmin', 'ContainersStaff', 'SuperAdmin'],
    'procurement-drafts' => $operationalRoles,
    'draft-orders' => $operationalRoles,
    'translations' => [
        'write' => $operationalRoles,
    ],
    'business-settings' => ['SuperAdmin'],
    'customer-portal-tokens' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
    'design-attachments' => ['ChinaAdmin', 'ChinaEmployee', 'WarehouseStaff', 'SuperAdmin'],
];
