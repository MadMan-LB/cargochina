<?php
/**
 * Page-level role enforcement for root pages.
 * Call after auth_check.php. Redirects to 403 if user lacks required role.
 *
 * Usage: requireRoleForPage(['WarehouseStaff', 'SuperAdmin']);  // receiving
 *        requireRoleForPage(['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin']);  // consolidation, orders
 *        requireRoleForPage(['SuperAdmin']);  // admin pages
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function requireRoleForPage(array $allowedRoles): void
{
    $userRoles = $_SESSION['user_roles'] ?? [];
    if (empty(array_intersect($userRoles, $allowedRoles))) {
        include __DIR__ . '/../403.php';
        exit;
    }
}
