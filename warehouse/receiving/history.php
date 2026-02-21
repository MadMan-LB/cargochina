<?php
$area = 'warehouse';
require __DIR__ . '/../../includes/area_bootstrap.php';
$currentPage = 'receiving-history';
$pageTitle = 'Receiving History';
$breadcrumbs = [['Warehouse', '/cargochina/warehouse/'], ['Receiving', ''], ['History', '']];
require __DIR__ . '/../../includes/area_layout.php';
?>
<div class="alert alert-info">
  Receiving history will be implemented here. For now, use the legacy receiving page:
  <a href="/cargochina/receiving.php">Go to Receiving</a>
</div>
<?php require __DIR__ . '/../../includes/area_footer.php'; ?>
