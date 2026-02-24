<?php

/**
 * Area layout - wraps into the unified sidebar layout.
 * Sets $currentPage context and includes the main layout.
 * Requires: $area, $areaBase, $currentPage, $pageTitle, $breadcrumbs (optional)
 */
$userRoles = $_SESSION['user_roles'] ?? [];
$userName = $_SESSION['user_name'] ?? 'User';
$isSuperAdmin = in_array('SuperAdmin', $userRoles);
$basePath = '/cargochina';
$breadcrumbs = $breadcrumbs ?? [];
require __DIR__ . '/layout.php';
