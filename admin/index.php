<?php
$area = 'admin';
require __DIR__ . '/../includes/area_bootstrap.php';
$currentPage = 'dashboard';
$pageTitle = 'Admin Dashboard';
$breadcrumbs = [['Admin', '/cargochina/admin/'], ['Dashboard', '']];
require __DIR__ . '/../includes/area_layout.php';
?>
<div class="row g-4">
  <div class="col-12">
    <h1 class="mb-4">Admin Dashboard</h1>
    <p class="lead text-muted">Consolidation and notifications.</p>
  </div>
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-body">
        <h5 class="card-title">Consolidation</h5>
        <p class="card-text">Manage containers and shipment drafts.</p>
        <a href="<?= $areaBase ?>/consolidation.php" class="btn btn-primary">Go to Consolidation</a>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-body">
        <h5 class="card-title">Notifications</h5>
        <p class="card-text">View and manage notifications.</p>
        <a href="<?= $areaBase ?>/notifications.php" class="btn btn-outline-primary">View Notifications</a>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/area_footer.php'; ?>
