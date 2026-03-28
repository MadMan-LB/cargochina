<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/sidebar_permissions.php';
$currentPage = $currentPage ?? 'dashboard';
$pageTitle = $pageTitle ?? 'CLMS Dashboard';
$userRoles = $_SESSION['user_roles'] ?? [];
$userName = $_SESSION['user_name'] ?? 'User';
$isSuperAdmin = in_array('SuperAdmin', $userRoles);
$hasContainersStaffRole = in_array('ContainersStaff', $userRoles, true);
$isAdmin = in_array('LebanonAdmin', $userRoles) || in_array('ChinaAdmin', $userRoles) || $isSuperAdmin;
$isWarehouse = in_array('WarehouseStaff', $userRoles) || $isSuperAdmin;
$isBuyer = in_array('ChinaAdmin', $userRoles) || in_array('ChinaEmployee', $userRoles) || $isSuperAdmin;
$isFieldStaff = in_array('FieldStaff', $userRoles) || $isSuperAdmin;
$isContainersOnly = $hasContainersStaffRole
  && !$isSuperAdmin
  && !in_array('ChinaAdmin', $userRoles, true)
  && !in_array('ChinaEmployee', $userRoles, true)
  && !in_array('LebanonAdmin', $userRoles, true)
  && !in_array('WarehouseStaff', $userRoles, true)
  && !in_array('FieldStaff', $userRoles, true);
$canViewCustomersPage = in_array('ChinaAdmin', $userRoles, true) || $isSuperAdmin;
$canViewPreferences = $isAdmin;
$canViewContainerOps = $isAdmin || $hasContainersStaffRole || $isSuperAdmin;
$canViewWarehouseStock = $isAdmin || $isWarehouse || $hasContainersStaffRole;
$canViewNotifications = !$isContainersOnly;
$canViewDownloads = $isAdmin || $isBuyer || $isWarehouse || $hasContainersStaffRole;
$basePath = '/cargochina';
$breadcrumbs = $breadcrumbs ?? [];
$layoutCssVersion = @filemtime(__DIR__ . '/../frontend/css/style.css') ?: time();
$sidebarSections = clmsGetSidebarSectionsForRoles($userRoles);
$visiblePageIds = clmsGetEffectivePageIdsForRoles($userRoles);
$canViewCustomersPage = in_array('customers', $visiblePageIds, true);
$canViewPreferences = in_array('notification_preferences', $visiblePageIds, true);
$canViewNotifications = in_array('notifications', $visiblePageIds, true);
$canViewDownloads = in_array('downloads', $visiblePageIds, true);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?> | CLMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="<?= $basePath ?>/frontend/css/style.css?v=<?= $layoutCssVersion ?>">
</head>

<body>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
  <aside class="clms-sidebar" id="sidebar">
    <div class="sidebar-brand">
      <a href="<?= $basePath ?>/index.php">CLMS</a>
      <button class="sidebar-close" id="sidebarClose" aria-label="Close sidebar">&times;</button>
    </div>
    <nav class="sidebar-nav">
      <?php foreach ($sidebarSections as $section): ?>
        <div class="sidebar-section-label"><?= htmlspecialchars($section['label']) ?></div>
        <?php foreach ($section['pages'] as $pageId => $pageMeta): ?>
          <a class="sidebar-link <?= $currentPage === $pageId ? 'active' : '' ?>" href="<?= htmlspecialchars($pageMeta['href']) ?>">
            <?= $pageMeta['icon_svg'] ?>
            <?= htmlspecialchars($pageMeta['title']) ?>
            <?php if ($pageId === 'notifications'): ?>
              <span class="badge bg-danger ms-auto d-none" id="notifBadge">0</span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
      <div class="sidebar-user">
        <svg class="sidebar-icon" viewBox="0 0 24 24">
          <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2a7.2 7.2 0 01-6-3.22c.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08a7.2 7.2 0 01-6 3.22z" />
        </svg>
        <span class="sidebar-user-name"><?= htmlspecialchars($userName) ?></span>
      </div>
      <a href="<?= $basePath ?>/login.php?logout=1" class="sidebar-link sidebar-logout">
        <svg class="sidebar-icon" viewBox="0 0 24 24">
          <path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z" />
        </svg>
        Logout
      </a>
    </div>
  </aside>

  <div class="clms-main" id="mainContent">
    <header class="clms-topbar">
      <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
        <svg viewBox="0 0 24 24" width="22" height="22">
          <path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z" fill="currentColor" />
        </svg>
      </button>
      <div class="topbar-title"><?= htmlspecialchars($pageTitle) ?></div>
      <div class="topbar-actions d-flex align-items-center gap-2">
        <span class="btn-group btn-group-sm" role="group" title="Description language">
          <button type="button" class="btn btn-outline-secondary btn-sm desc-lang-btn" data-lang="en">EN</button>
          <button type="button" class="btn btn-outline-secondary btn-sm desc-lang-btn" data-lang="cn">中文</button>
        </span>
        <span class="topbar-role badge bg-primary bg-opacity-10 text-primary"><?= htmlspecialchars(implode(', ', $userRoles)) ?></span>
      </div>
    </header>
    <div class="clms-content">
