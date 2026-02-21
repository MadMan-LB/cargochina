<?php
$area = 'warehouse';
require __DIR__ . '/../../includes/area_bootstrap.php';
$receiptId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$receiptId) {
  header('Location: ' . $areaBase . '/receiving/');
  exit;
}
$currentPage = 'receiving-receipt';
$pageTitle = 'Receipt #' . $receiptId;
$breadcrumbs = [['Warehouse', '/cargochina/warehouse/'], ['Receiving', '/cargochina/warehouse/receiving/'], ['Receipt #' . $receiptId, '']];
require __DIR__ . '/../../includes/area_layout.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h1 class="h3 mb-0">Receipt #<?= (int)$receiptId ?></h1>
  <a href="<?= $areaBase ?>/receiving/" class="btn btn-outline-secondary btn-sm">← Back to Receiving</a>
</div>

<div id="receiptContent" class="card">
  <div class="card-body">
    <div class="placeholder-glow"><span class="placeholder col-8"></span></div>
  </div>
</div>
<?php
$pageScript = '/cargochina/frontend/js/receiving_receipt.js';
?>
<script>
  window.RECEIPT_ID = <?= (int)$receiptId ?>;
</script>
<?php require __DIR__ . '/../../includes/area_footer.php'; ?>