<?php

/**
 * RBAC - endpoint to required roles
 * Public: auth/login, auth/logout
 * All authenticated: most GET, POST customers/suppliers/products/orders
 */

return [
    'public' => ['auth', 'confirm'],
    'orders' => [
        'approve' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
        'receive' => ['WarehouseStaff', 'SuperAdmin'],
        'confirm' => ['ChinaAdmin', 'LebanonAdmin', 'WarehouseStaff', 'SuperAdmin'],
    ],
    'containers' => [
        'read' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
        'write' => ['SuperAdmin'],
    ],
    'shipment-drafts' => [
        'create' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
        'finalize' => ['LebanonAdmin', 'SuperAdmin'],
        'push' => ['LebanonAdmin', 'SuperAdmin'],
    ],
    'users' => ['SuperAdmin'],
    'config' => ['SuperAdmin'],
];
