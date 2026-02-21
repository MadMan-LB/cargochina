<?php
$area = 'superadmin';
require __DIR__ . '/../includes/area_bootstrap.php';
$currentPage = 'dashboard';
$pageTitle = 'Super Admin Dashboard';
$breadcrumbs = [['Super Admin', '/cargochina/superadmin/'], ['Dashboard', '']];
require __DIR__ . '/../includes/area_layout.php';
?>
<div class="row g-4">
  <div class="col-12">
    <h1 class="mb-4">Super Admin Dashboard</h1>
    <p class="lead text-muted">System administration and configuration.</p>
  </div>
  <div class="col-md-6 col-lg-4">
    <div class="card h-100">
      <div class="card-body">
        <h5 class="card-title">Users</h5>
        <p class="card-text">Manage user accounts and roles.</p>
        <a href="<?= $areaBase ?>/users.php" class="btn btn-primary">Manage Users</a>
      </div>
    </div>
  </div>
  <div class="col-md-6 col-lg-4">
    <div class="card h-100">
      <div class="card-body">
        <h5 class="card-title">Configuration</h5>
        <p class="card-text">System settings and parameters.</p>
        <a href="<?= $areaBase ?>/configuration.php" class="btn btn-outline-primary">Configuration</a>
      </div>
    </div>
  </div>
  <div class="col-md-6 col-lg-4">
    <div class="card h-100">
      <div class="card-body">
        <h5 class="card-title">Diagnostics</h5>
        <p class="card-text">System health and diagnostics.</p>
        <a href="<?= $areaBase ?>/diagnostics.php" class="btn btn-outline-primary">Diagnostics</a>
      </div>
    </div>
  </div>
  <div class="col-md-6 col-lg-4">
    <div class="card h-100">
      <div class="card-body">
        <h5 class="card-title">Tracking Push Log</h5>
        <p class="card-text">View tracking API push history.</p>
        <a href="<?= $areaBase ?>/tracking-push-log.php" class="btn btn-outline-primary">View Log</a>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/area_footer.php'; ?>
