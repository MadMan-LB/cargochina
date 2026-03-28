<?php
$area = 'buyers';
require __DIR__ . '/../includes/area_bootstrap.php';
$roles = $_SESSION['user_roles'] ?? [];
$canViewOrders = clmsCanRolesAccessPage($roles, 'orders');
$canViewCustomers = clmsCanRolesAccessPage($roles, 'customers');
$canViewSuppliers = clmsCanRolesAccessPage($roles, 'suppliers');
$canViewProducts = clmsCanRolesAccessPage($roles, 'products');
$currentPage = 'dashboard';
$pageTitle = 'Buyers Dashboard';
$breadcrumbs = [['Buyers', '/cargochina/buyers/'], ['Dashboard', '']];
require __DIR__ . '/../includes/area_layout.php';
?>
<div class="row g-4">
  <div class="col-12">
    <h1 class="mb-4">Buyers Dashboard</h1>
    <p class="lead text-muted">Manage orders and master data.</p>
  </div>
  <?php if ($canViewOrders): ?>
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-body">
        <h5 class="card-title">Orders</h5>
        <p class="card-text">Create and manage orders with items.</p>
        <a href="<?= $areaBase ?>/orders.php" class="btn btn-primary">Go to Orders</a>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($canViewCustomers || $canViewSuppliers || $canViewProducts): ?>
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-body">
        <h5 class="card-title">Master Data</h5>
        <p class="card-text">Manage the master-data pages available to this role.</p>
        <div class="d-flex gap-2 flex-wrap">
          <?php if ($canViewCustomers): ?>
            <a href="<?= $areaBase ?>/customers.php" class="btn btn-outline-primary">Customers</a>
          <?php endif; ?>
          <?php if ($canViewSuppliers): ?>
            <a href="<?= $areaBase ?>/suppliers.php" class="btn btn-outline-primary">Suppliers</a>
          <?php endif; ?>
          <?php if ($canViewProducts): ?>
            <a href="<?= $areaBase ?>/products.php" class="btn btn-outline-primary">Products</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/area_footer.php'; ?>
