<?php
$area = 'buyers';
require __DIR__ . '/../includes/area_bootstrap.php';
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
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-body">
        <h5 class="card-title">Orders</h5>
        <p class="card-text">Create and manage orders with items.</p>
        <a href="<?= $areaBase ?>/orders.php" class="btn btn-primary">Go to Orders</a>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-body">
        <h5 class="card-title">Master Data</h5>
        <p class="card-text">Manage customers, suppliers, and products.</p>
        <div class="d-flex gap-2 flex-wrap">
          <a href="<?= $areaBase ?>/customers.php" class="btn btn-outline-primary">Customers</a>
          <a href="<?= $areaBase ?>/suppliers.php" class="btn btn-outline-primary">Suppliers</a>
          <a href="<?= $areaBase ?>/products.php" class="btn btn-outline-primary">Products</a>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/area_footer.php'; ?>
