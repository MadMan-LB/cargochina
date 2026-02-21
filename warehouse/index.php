<?php
$area = 'warehouse';
require __DIR__ . '/../includes/area_bootstrap.php';
$currentPage = 'dashboard';
$pageTitle = 'Warehouse Dashboard';
$breadcrumbs = [['Warehouse', '/cargochina/warehouse/'], ['Dashboard', '']];
require __DIR__ . '/../includes/area_layout.php';
?>
<div class="row g-4">
  <div class="col-12">
    <h1 class="mb-4">Warehouse Dashboard</h1>
    <p class="lead text-muted">Record receiving, manage queue and history.</p>
  </div>
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-body">
        <h5 class="card-title">Receiving Queue</h5>
        <p class="card-text">Process orders awaiting receipt at the warehouse.</p>
        <a href="<?= $areaBase ?>/receiving/queue.php" class="btn btn-primary">Go to Queue</a>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-body">
        <h5 class="card-title">Receiving History</h5>
        <p class="card-text">View past receiving records.</p>
        <a href="<?= $areaBase ?>/receiving/history.php" class="btn btn-outline-primary">View History</a>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/area_footer.php'; ?>
