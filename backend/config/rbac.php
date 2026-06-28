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
        'write' => $operationalRoles,
        'approve' => $operationalRoles,
        'receive' => $operationalRoles,
        'confirm' => $operationalRoles,
    ],
    'customers' => [
        'read' => ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'ContainersStaff', 'SuperAdmin'],
        'lookup' => $operationalRoles,
        'write' => ['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin'],
        'create' => ['ChinaAdmin', 'SuperAdmin'],
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
        'read' => ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'SuperAdmin'],
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
    'business-settings' => ['SuperAdmin'],
    'customer-portal-tokens' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
    'design-attachments' => ['ChinaAdmin', 'ChinaEmployee', 'WarehouseStaff', 'SuperAdmin'],
];
