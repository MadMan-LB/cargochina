<?php
/**
 * Area bootstrap: require auth, validate area access by role.
 * Call with: require __DIR__ . '/includes/area_bootstrap.php'; $area = 'warehouse';
 *
 * @param string $area One of: warehouse|buyers|admin|superadmin
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    $loginUrl = '/cargochina/login.php';
    header('Location: ' . $loginUrl);
    exit;
}

$validAreas = ['warehouse', 'buyers', 'admin', 'superadmin'];
if (empty($area) || !in_array($area, $validAreas, true)) {
    $area = 'warehouse';
}

$userRoles = $_SESSION['user_roles'] ?? [];

$areaRoles = [
    'warehouse' => ['WarehouseStaff', 'SuperAdmin'],
    'buyers' => ['ChinaAdmin', 'SuperAdmin'],
    'admin' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
    'superadmin' => ['SuperAdmin'],
];

$allowedRoles = $areaRoles[$area];
$hasAccess = !empty(array_intersect($userRoles, $allowedRoles));

if (!$hasAccess) {
    include __DIR__ . '/../403.php';
    exit;
}

$areaBase = '/cargochina/' . $area;
