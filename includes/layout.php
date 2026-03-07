<?php
require_once __DIR__ . '/auth_check.php';
$currentPage = $currentPage ?? 'dashboard';
$pageTitle = $pageTitle ?? 'CLMS Dashboard';
$userRoles = $_SESSION['user_roles'] ?? [];
$userName = $_SESSION['user_name'] ?? 'User';
$isSuperAdmin = in_array('SuperAdmin', $userRoles);
$isAdmin = in_array('LebanonAdmin', $userRoles) || in_array('ChinaAdmin', $userRoles) || $isSuperAdmin;
$isWarehouse = in_array('WarehouseStaff', $userRoles) || $isSuperAdmin;
$isBuyer = in_array('ChinaAdmin', $userRoles) || in_array('ChinaEmployee', $userRoles) || $isSuperAdmin;
$isFieldStaff = in_array('FieldStaff', $userRoles) || $isSuperAdmin;
$basePath = '/cargochina';
$breadcrumbs = $breadcrumbs ?? [];
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
  <link rel="stylesheet" href="<?= $basePath ?>/frontend/css/style.css">
</head>

<body>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
  <aside class="clms-sidebar" id="sidebar">
    <div class="sidebar-brand">
      <a href="<?= $basePath ?>/index.php">CLMS</a>
      <button class="sidebar-close d-lg-none" id="sidebarClose" aria-label="Close">&times;</button>
    </div>
    <nav class="sidebar-nav">
      <div class="sidebar-section-label">Main</div>
      <a class="sidebar-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>" href="<?= $basePath ?>/index.php">
        <svg class="sidebar-icon" viewBox="0 0 24 24">
          <path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z" />
        </svg>
        Dashboard
      </a>
      <?php if ($isBuyer): ?>
        <a class="sidebar-link <?= $currentPage === 'orders' ? 'active' : '' ?>" href="<?= $basePath ?>/orders.php">
          <svg class="sidebar-icon" viewBox="0 0 24 24">
            <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6zm4 18H6V4h7v5h5v11z" />
          </svg>
          Orders
        </a>
      <?php endif; ?>
      <?php if ($isWarehouse): ?>
        <a class="sidebar-link <?= $currentPage === 'receiving' ? 'active' : '' ?>" href="<?= $basePath ?>/receiving.php">
          <svg class="sidebar-icon" viewBox="0 0 24 24">
            <path d="M20 6h-4V4c0-1.1-.9-2-2-2h-4c-1.1 0-2 .9-2 2v2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zm-8-2h4v2h-4V4zm8 16H4V8h16v12z" />
          </svg>
          Receiving
        </a>
      <?php endif; ?>
      <?php if ($isAdmin): ?>
        <a class="sidebar-link <?= $currentPage === 'pipeline' ? 'active' : '' ?>" href="<?= $basePath ?>/pipeline.php">
          <svg class="sidebar-icon" viewBox="0 0 24 24">
            <path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z" />
          </svg>
          Pipeline
        </a>
        <a class="sidebar-link <?= $currentPage === 'consolidation' ? 'active' : '' ?>" href="<?= $basePath ?>/consolidation.php">
          <svg class="sidebar-icon" viewBox="0 0 24 24">
            <path d="M20 2H4c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-1 9h-4v4h-2v-4H9v4H7v-4H3V9h4V5h2v4h4V5h2v4h4v2z" />
          </svg>
          Consolidation
        </a>
      <?php endif; ?>

      <div class="sidebar-section-label">Data</div>
      <?php if ($isBuyer || $isFieldStaff): ?>
        <a class="sidebar-link <?= $currentPage === 'suppliers' ? 'active' : '' ?>" href="<?= $basePath ?>/suppliers.php">
          <svg class="sidebar-icon" viewBox="0 0 24 24">
            <path d="M20 8h-3V4H3c-1.1 0-2 .9-2 2v11h2c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3 3s3-1.34 3-3h2v-5l-3-4zM6 18.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zm13.5-9l1.96 2.5H17V9.5h2.5zm-1.5 9c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z" />
          </svg>
          Suppliers
        </a>
      <?php endif; ?>
      <?php if ($isBuyer): ?>
        <a class="sidebar-link <?= $currentPage === 'customers' ? 'active' : '' ?>" href="<?= $basePath ?>/customers.php">
          <svg class="sidebar-icon" viewBox="0 0 24 24">
            <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z" />
          </svg>
          Customers
        </a>
        <a class="sidebar-link <?= $currentPage === 'products' ? 'active' : '' ?>" href="<?= $basePath ?>/products.php">
          <svg class="sidebar-icon" viewBox="0 0 24 24">
            <path d="M12 2l-5.5 9h11L12 2zm0 3.84L13.93 9h-3.87L12 5.84zM17.5 13c-2.49 0-4.5 2.01-4.5 4.5s2.01 4.5 4.5 4.5 4.5-2.01 4.5-4.5-2.01-4.5-4.5-4.5zm0 7a2.5 2.5 0 010-5 2.5 2.5 0 010 5zM3 21.5h8v-8H3v8zm2-6h4v4H5v-4z" />
          </svg>
          Products
        </a>
      <?php endif; ?>

      <div class="sidebar-section-label">Notifications</div>
      <a class="sidebar-link <?= $currentPage === 'notifications' ? 'active' : '' ?>" href="<?= $basePath ?>/notifications.php">
        <svg class="sidebar-icon" viewBox="0 0 24 24">
          <path d="M12 22c1.1 0 2-.9 2-2h-4a2 2 0 002 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z" />
        </svg>
        Notifications <span class="badge bg-danger ms-auto d-none" id="notifBadge">0</span>
      </a>
      <a class="sidebar-link <?= $currentPage === 'notification_preferences' ? 'active' : '' ?>" href="<?= $basePath ?>/notification_preferences.php">
        <svg class="sidebar-icon" viewBox="0 0 24 24">
          <path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58a.49.49 0 00.12-.61l-1.92-3.32a.49.49 0 00-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54a.484.484 0 00-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.62-.07.94s.02.64.07.94l-2.03 1.58a.49.49 0 00-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6A3.6 3.6 0 1115.6 12 3.6 3.6 0 0112 15.6z" />
        </svg>
        Preferences
      </a>

      <?php if ($isSuperAdmin): ?>
        <div class="sidebar-section-label">Administration</div>
        <a class="sidebar-link <?= $currentPage === 'admin_config' ? 'active' : '' ?>" href="<?= $basePath ?>/admin_config.php">
          <svg class="sidebar-icon" viewBox="0 0 24 24">
            <path d="M17 11c.34 0 .67.04 1 .09V6.27L10.5 3 3 6.27v4.91c0 4.54 3.2 8.79 7.5 9.82.55-.13 1.08-.32 1.6-.55-.69-.98-1.1-2.17-1.1-3.45 0-3.31 2.69-6 6-6z" />
            <path d="M17 13c-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4-1.79-4-4-4zm0 1.38c.62 0 1.12.51 1.12 1.12s-.51 1.12-1.12 1.12-1.12-.51-1.12-1.12.5-1.12 1.12-1.12zm0 5.37c-.93 0-1.74-.46-2.24-1.17.05-.72 1.51-1.08 2.24-1.08s2.19.36 2.24 1.08c-.5.71-1.31 1.17-2.24 1.17z" />
          </svg>
          Configuration
        </a>
        <a class="sidebar-link <?= $currentPage === 'admin_users' ? 'active' : '' ?>" href="<?= $basePath ?>/admin_users.php">
          <svg class="sidebar-icon" viewBox="0 0 24 24">
            <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
          </svg>
          Users
        </a>
        <a class="sidebar-link <?= $currentPage === 'admin_diagnostics' ? 'active' : '' ?>" href="<?= $basePath ?>/admin_diagnostics.php">
          <svg class="sidebar-icon" viewBox="0 0 24 24">
            <path d="M19.35 10.04A7.49 7.49 0 0012 4C9.11 4 6.6 5.64 5.35 8.04A5.994 5.994 0 000 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96z" />
          </svg>
          Diagnostics
        </a>
        <a class="sidebar-link <?= $currentPage === 'admin_tracking' ? 'active' : '' ?>" href="<?= $basePath ?>/admin_tracking_push.php">
          <svg class="sidebar-icon" viewBox="0 0 24 24">
            <path d="M21 3H3c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h18c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H3V5h18v14zM5 15h14v2H5zm0-4h14v2H5zm0-4h14v2H5z" />
          </svg>
          Tracking Push Log
        </a>
        <a class="sidebar-link <?= $currentPage === 'admin_audit' ? 'active' : '' ?>" href="<?= $basePath ?>/admin_audit_log.php">
          <svg class="sidebar-icon" viewBox="0 0 24 24">
            <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z" />
          </svg>
          Audit Log
        </a>
      <?php endif; ?>
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
      <div class="topbar-actions">
        <span class="topbar-role badge bg-primary bg-opacity-10 text-primary"><?= htmlspecialchars(implode(', ', $userRoles)) ?></span>
      </div>
    </header>
    <div class="clms-content">