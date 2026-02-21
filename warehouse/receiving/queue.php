<?php
$area = 'warehouse';
require __DIR__ . '/../../includes/area_bootstrap.php';
$currentPage = 'receiving-queue';
$pageTitle = 'Receiving Queue';
$breadcrumbs = [['Warehouse', '/cargochina/warehouse/'], ['Receiving', ''], ['Queue', '']];
require __DIR__ . '/../../includes/area_layout.php';
?>
<div class="alert alert-info">
  Receiving queue will be migrated here. For now, use the legacy page:
  <a href="/cargochina/receiving.php">Go to Receiving</a>
</div>
<?php require __DIR__ . '/../../includes/area_footer.php'; ?>
