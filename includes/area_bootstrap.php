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

require_once __DIR__ . '/sidebar_permissions.php';

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
    'buyers' => ['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin'],
    'admin' => ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'],
    'superadmin' => ['SuperAdmin'],
];

$allowedRoles = $areaRoles[$area];
$hasAccess = !empty(array_intersect($userRoles, $allowedRoles));

$pageId = clmsResolveCurrentPageId($_SERVER['PHP_SELF'] ?? '');
    $normalizedPath = '/' . ltrim(str_replace('\\', '/', strtolower($_SERVER['PHP_SELF'] ?? '')), '/');
$isAreaDashboard = str_ends_with($normalizedPath, '/buyers/index.php')
    || str_ends_with($normalizedPath, '/warehouse/index.php')
    || str_ends_with($normalizedPath, '/admin/index.php')
    || str_ends_with($normalizedPath, '/superadmin/index.php');

if ($isAreaDashboard) {
    if (!$hasAccess) {
        include __DIR__ . '/../403.php';
        exit;
    }
} elseif ($pageId !== null) {
    $registry = clmsSidebarPageRegistry();
    $pageMeta = $registry[$pageId] ?? null;
    if ($pageMeta !== null) {
        if (!empty($pageMeta['superadmin_only']) && !in_array('SuperAdmin', $userRoles, true)) {
            include __DIR__ . '/../403.php';
            exit;
        }
        if (!clmsCanRolesAccessPage($userRoles, $pageId)) {
            include __DIR__ . '/../403.php';
            exit;
        }
    } elseif (!$hasAccess) {
        include __DIR__ . '/../403.php';
        exit;
    }
} elseif (!$hasAccess) {
    include __DIR__ . '/../403.php';
    exit;
}

$areaBase = '/cargochina/' . $area;
