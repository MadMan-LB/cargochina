<?php
require_once __DIR__ . '/auth_check.php';
$currentPage = $currentPage ?? 'dashboard';
$pageTitle = $pageTitle ?? 'CLMS Dashboard';
$userRoles = $_SESSION['user_roles'] ?? [];
$isSuperAdmin = in_array('SuperAdmin', $userRoles);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?> | CLMS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
  <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
      <a class="navbar-brand" href="index.php">CLMS</a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="navbarNav">
        <ul class="navbar-nav">
          <li class="nav-item">
            <a class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>" href="index.php">Dashboard</a>
          </li>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle <?= in_array($currentPage, ['customers', 'suppliers', 'products']) ? 'active' : '' ?>" href="#" data-bs-toggle="dropdown">Master Data</a>
            <ul class="dropdown-menu">
              <li><a class="dropdown-item" href="customers.php">Customers</a></li>
              <li><a class="dropdown-item" href="suppliers.php">Suppliers</a></li>
              <li><a class="dropdown-item" href="products.php">Products</a></li>
            </ul>
          </li>
          <li class="nav-item">
            <a class="nav-link <?= $currentPage === 'orders' ? 'active' : '' ?>" href="orders.php">Orders</a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?= $currentPage === 'receiving' ? 'active' : '' ?>" href="receiving.php">Receiving</a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?= $currentPage === 'consolidation' ? 'active' : '' ?>" href="consolidation.php">Consolidation</a>
          </li>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle <?= $currentPage === 'notifications' ? 'active' : '' ?>" href="#" data-bs-toggle="dropdown" id="notificationsDropdown">
              Notifications <span class="badge bg-danger d-none" id="notifBadge">0</span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end" id="notificationsMenu" aria-labelledby="notificationsDropdown">
              <li><a class="dropdown-item" href="notifications.php">View all</a></li>
              <li><a class="dropdown-item" href="notification_preferences.php">Preferences</a></li>
              <li>
                <hr class="dropdown-divider">
              </li>
              <li>
                <div class="dropdown-item text-muted" id="notifPlaceholder">Loading...</div>
              </li>
            </ul>
          </li>
          <?php if ($isSuperAdmin): ?>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">Admin</a>
              <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="admin_users.php">Users</a></li>
                <li><a class="dropdown-item" href="admin_config.php">Configuration</a></li>
                <li><a class="dropdown-item" href="admin_tracking_push.php">Tracking Push Log</a></li>
              </ul>
            </li>
          <?php endif; ?>
          <li class="nav-item">
            <a class="nav-link" href="login.php?logout=1">Logout</a>
          </li>
        </ul>
      </div>
    </div>
  </nav>
  <div class="container-fluid py-4">
    <div class="row">