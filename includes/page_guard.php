<?php
/**
 * Page-level role enforcement for root pages.
 * Call after auth_check.php. Redirects to 403 if the current user lacks
 * explicit access to the resolved CLMS page. The legacy allowed-role array is
 * still used as a fallback for unregistered pages.
 *
 * Usage: requireRoleForPage(['WarehouseStaff', 'SuperAdmin']);  // receiving
 *        requireRoleForPage(['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin']);  // consolidation, orders
 *        requireRoleForPage(['SuperAdmin']);  // admin pages
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/sidebar_permissions.php';

function requireRoleForPage(array $allowedRoles): void
{
    $userRoles = $_SESSION['user_roles'] ?? [];
    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $pageId = clmsResolveCurrentPageId($_SERVER['PHP_SELF'] ?? '');
    $registry = clmsSidebarPageRegistry();
    $pageMeta = $pageId !== null ? ($registry[$pageId] ?? null) : null;

    if ($pageMeta !== null) {
        if (!empty($pageMeta['superadmin_only'])
            && !in_array('SuperAdmin', $userRoles, true)
            && !clmsUserHasPermissionOverride('page:' . $pageId, $userId)) {
            include __DIR__ . '/../403.php';
            exit;
        }
        if (!clmsCanRolesAccessPage($userRoles, $pageId, null, $userId)) {
            include __DIR__ . '/../403.php';
            exit;
        }
        return;
    }

    if (empty(array_intersect($userRoles, $allowedRoles))) {
        include __DIR__ . '/../403.php';
        exit;
    }
}
