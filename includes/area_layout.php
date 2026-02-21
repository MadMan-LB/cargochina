<?php

/**
 * Area layout: sidebar (left, collapsible on mobile) + topbar (right).
 * Requires: $area, $areaBase, $currentPage, $pageTitle, $breadcrumbs (optional)
 */
$userRoles = $_SESSION['user_roles'] ?? [];
$userName = $_SESSION['user_name'] ?? 'User';
$isSuperAdmin = in_array('SuperAdmin', $userRoles);
$basePath = '/cargochina';
$breadcrumbs = $breadcrumbs ?? [];
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? 'CLMS') ?> | CLMS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="<?= $basePath ?>/frontend/css/style.css">
  <style>
    .area-sidebar {
      width: 220px;
      min-height: 100vh;
    }

    @media (max-width: 767.98px) {
      .area-sidebar {
        position: fixed;
        left: 0;
        top: 0;
        bottom: 0;
        z-index: 1050;
        transform: translateX(-100%);
        transition: transform 0.25s;
      }

      .area-sidebar.show {
        transform: translateX(0);
      }

      .sidebar-backdrop {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1040;
      }

      .sidebar-backdrop.show {
        display: block;
      }
    }
  </style>
</head>

<body class="d-flex">
  <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="document.getElementById('sidebar').classList.remove('show'); this.classList.remove('show');"></div>
  <!-- Sidebar -->
  <aside class="area-sidebar sidebar bg-dark text-white flex-shrink-0" id="sidebar">
    <div class="sidebar-header p-3 border-bottom border-secondary d-flex align-items-center">
      <a href="<?= $areaBase ?>/" class="text-white text-decoration-none fw-bold">CLMS</a>
      <button class="btn btn-link text-white p-0 ms-auto d-md-none" type="button" onclick="document.getElementById('sidebar').classList.toggle('show'); document.getElementById('sidebarBackdrop').classList.toggle('show');">
        <span class="navbar-toggler-icon"></span>
      </button>
    </div>
    <nav class="sidebar-nav p-2">
      <?php if ($area === 'warehouse'): ?>
        <a class="nav-link text-white-50 py-2 <?= ($currentPage ?? '') === 'dashboard' ? 'text-white' : '' ?>" href="<?= $areaBase ?>/">Dashboard</a>
        <a class="nav-link text-white-50 py-2 <?= in_array($currentPage ?? '', ['receiving-queue', 'receiving-history', 'receiving-receive', 'receiving-receipt']) ? 'text-white' : '' ?>" href="<?= $areaBase ?>/receiving/">Receiving</a>
      <?php elseif ($area === 'buyers'): ?>
        <a class="nav-link text-white-50 py-2 <?= ($currentPage ?? '') === 'dashboard' ? 'text-white' : '' ?>" href="<?= $areaBase ?>/">Dashboard</a>
        <a class="nav-link text-white-50 py-2 <?= ($currentPage ?? '') === 'orders' ? 'text-white' : '' ?>" href="<?= $areaBase ?>/orders.php">Orders</a>
        <div class="dropdown">
          <a class="nav-link text-white-50 py-2 dropdown-toggle <?= in_array($currentPage ?? '', ['customers', 'suppliers', 'products']) ? 'text-white' : '' ?>" href="#" data-bs-toggle="dropdown">Master Data</a>
          <ul class="dropdown-menu dropdown-menu-dark">
            <li><a class="dropdown-item" href="<?= $areaBase ?>/customers.php">Customers</a></li>
            <li><a class="dropdown-item" href="<?= $areaBase ?>/suppliers.php">Suppliers</a></li>
            <li><a class="dropdown-item" href="<?= $areaBase ?>/products.php">Products</a></li>
          </ul>
        </div>
      <?php elseif ($area === 'admin'): ?>
        <a class="nav-link text-white-50 py-2 <?= ($currentPage ?? '') === 'dashboard' ? 'text-white' : '' ?>" href="<?= $areaBase ?>/">Dashboard</a>
        <a class="nav-link text-white-50 py-2 <?= ($currentPage ?? '') === 'consolidation' ? 'text-white' : '' ?>" href="<?= $areaBase ?>/consolidation.php">Consolidation</a>
        <div class="dropdown">
          <a class="nav-link text-white-50 py-2 dropdown-toggle <?= in_array($currentPage ?? '', ['notifications', 'notification-preferences']) ? 'text-white' : '' ?>" href="#" data-bs-toggle="dropdown">Notifications</a>
          <ul class="dropdown-menu dropdown-menu-dark">
            <li><a class="dropdown-item" href="<?= $areaBase ?>/notifications.php">View all</a></li>
            <li><a class="dropdown-item" href="<?= $areaBase ?>/notification_preferences.php">Preferences</a></li>
          </ul>
        </div>
      <?php elseif ($area === 'superadmin'): ?>
        <a class="nav-link text-white-50 py-2 <?= ($currentPage ?? '') === 'dashboard' ? 'text-white' : '' ?>" href="<?= $areaBase ?>/">Dashboard</a>
        <a class="nav-link text-white-50 py-2 <?= ($currentPage ?? '') === 'users' ? 'text-white' : '' ?>" href="<?= $areaBase ?>/users.php">Users</a>
        <a class="nav-link text-white-50 py-2 <?= ($currentPage ?? '') === 'configuration' ? 'text-white' : '' ?>" href="<?= $areaBase ?>/configuration.php">Configuration</a>
        <a class="nav-link text-white-50 py-2 <?= ($currentPage ?? '') === 'diagnostics' ? 'text-white' : '' ?>" href="<?= $areaBase ?>/diagnostics.php">Diagnostics</a>
        <a class="nav-link text-white-50 py-2 <?= ($currentPage ?? '') === 'tracking-push-log' ? 'text-white' : '' ?>" href="<?= $areaBase ?>/tracking-push-log.php">Tracking Push Log</a>
      <?php endif; ?>
    </nav>
  </aside>

  <div class="d-flex flex-column flex-grow-1 min-vh-100">
    <!-- Topbar -->
    <header class="topbar bg-white border-bottom px-3 py-2 d-flex align-items-center">
      <button class="btn btn-outline-secondary btn-sm d-md-none me-2" type="button" onclick="document.getElementById('sidebar').classList.toggle('show'); document.getElementById('sidebarBackdrop').classList.toggle('show');">
        <span class="navbar-toggler-icon"></span>
      </button>
      <?php if (!empty($breadcrumbs)): ?>
        <nav aria-label="breadcrumb" class="me-auto">
          <ol class="breadcrumb mb-0">
            <?php foreach ($breadcrumbs as $i => $bc): ?>
              <li class="breadcrumb-item <?= $i === count($breadcrumbs) - 1 ? 'active' : '' ?>">
                <?php if ($i < count($breadcrumbs) - 1 && !empty($bc[1])): ?>
                  <a href="<?= htmlspecialchars($bc[1]) ?>"><?= htmlspecialchars($bc[0]) ?></a>
                <?php else: ?>
                  <?= htmlspecialchars($bc[0]) ?>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ol>
        </nav>
      <?php endif; ?>
      <div class="d-flex align-items-center gap-2 ms-auto">
        <div class="dropdown">
          <a class="btn btn-link text-dark text-decoration-none dropdown-toggle py-0" href="#" data-bs-toggle="dropdown" id="notificationsDropdown">
            Notifications <span class="badge bg-danger d-none" id="notifBadge">0</span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="notificationsDropdown">
            <li><a class="dropdown-item" href="<?= $areaBase ?>/notifications.php">View all</a></li>
            <li><a class="dropdown-item" href="<?= $basePath ?>/notification_preferences.php">Preferences</a></li>
            <li>
              <hr class="dropdown-divider">
            </li>
            <li>
              <div class="dropdown-item text-muted" id="notifPlaceholder">Loading...</div>
            </li>
          </ul>
        </div>
        <span class="text-muted small"><?= htmlspecialchars($userName) ?></span>
        <a href="<?= $basePath ?>/login.php?logout=1" class="btn btn-outline-secondary btn-sm">Logout</a>
      </div>
    </header>

    <!-- Main content -->
    <main class="flex-grow-1 p-4">