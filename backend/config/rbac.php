<?php

/**
 * RBAC - endpoint to required roles
 * Public: auth/login, auth/logout
 * All authenticated: most GET, POST customers/suppliers/products/orders
 */

return [
    'public' => ['auth'],
    'orders' => [
        'approve' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
        'receive' => ['WarehouseStaff', 'SuperAdmin'],
        'confirm' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
    ],
    'containers' => ['SuperAdmin'],
    'shipment-drafts' => [
        'create' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
        'finalize' => ['LebanonAdmin', 'SuperAdmin'],
        'push' => ['LebanonAdmin', 'SuperAdmin'],
    ],
    'users' => ['SuperAdmin'],
    'config' => ['SuperAdmin'],
];
