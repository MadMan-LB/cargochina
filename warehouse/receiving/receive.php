<?php
$area = 'warehouse';
require __DIR__ . '/../../includes/area_bootstrap.php';
$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if (!$orderId) {
  header('Location: ' . $areaBase . '/receiving/');
  exit;
}
$currentPage = 'receiving-receive';
$pageTitle = 'Receive Order #' . $orderId;
$breadcrumbs = [['Warehouse', '/cargochina/warehouse/'], ['Receiving', '/cargochina/warehouse/receiving/'], ['Receive #' . $orderId, '']];
require __DIR__ . '/../../includes/area_layout.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h1 class="h3 mb-0">Receive Order #<?= (int)$orderId ?></h1>
  <a href="<?= $areaBase ?>/receiving/" class="btn btn-outline-secondary btn-sm">← Back to Queue</a>
</div>

<div id="orderOverview" class="card mb-3">
  <div class="card-header">A) Order Overview</div>
  <div class="card-body" id="orderOverviewBody">
    <div class="placeholder-glow"><span class="placeholder col-6"></span><span class="placeholder col-4"></span></div>
  </div>
</div>

<div id="receiveForm" class="card mb-3">
  <div class="card-header">B) Enter Actual Totals</div>
  <div class="card-body">
    <div class="row mb-3">
      <div class="col-md-4"><label class="form-label">Actual Cartons *</label><input type="number" class="form-control" id="actualCartons" min="0" required></div>
      <div class="col-md-4"><label class="form-label">Actual CBM *</label><input type="number" step="0.0001" class="form-control" id="actualCbm" min="0" required></div>
      <div class="col-md-4"><label class="form-label">Actual Weight *</label><input type="number" step="0.0001" class="form-control" id="actualWeight" min="0" required></div>
    </div>
    <div class="row mb-3">
      <div class="col-md-4"><label class="form-label">Condition</label><select class="form-select" id="condition">
          <option value="good">Good</option>
          <option value="damaged">Damaged</option>
          <option value="partial">Partial</option>
        </select></div>
      <div class="col-md-8"><label class="form-label">Notes</label><input type="text" class="form-control" id="receiveNotes"></div>
    </div>
  </div>
</div>

<div class="card mb-3" id="itemLevelSection">
  <div class="card-header">C) Per-Item Actuals</div>
  <div class="card-body">
    <div id="itemLevelTable" class="table-responsive">
      <table class="table table-sm">
        <thead>
          <tr>
            <th>Item</th>
            <th>Declared</th>
            <th>Actual Cartons</th>
            <th>Actual CBM</th>
            <th>Actual Weight</th>
            <th>Condition</th>
            <th>Photos</th>
          </tr>
        </thead>
        <tbody id="itemLevelBody"></tbody>
      </table>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header">D) Evidence Photos <span class="text-danger">*required if variance or damage</span></div>
  <div class="card-body">
    <div id="variancePhotoAlert" class="alert alert-warning py-2 d-none" role="alert">Photo evidence required.</div>
    <input type="file" class="d-none" id="receivePhotos" multiple accept="image/*">
    <button type="button" class="btn btn-outline-secondary btn-sm" id="receiveAddPhotoBtn">Add Photo</button>
    <div id="photoPreview" class="mt-2 d-flex flex-wrap gap-2"></div>
  </div>
</div>

<div class="card mb-3" id="varianceResult" style="display:none">
  <div class="card-header">E) Variance Results</div>
  <div class="card-body" id="varianceResultBody"></div>
</div>

<button type="button" class="btn btn-primary" id="submitReceiveBtn">Record Receipt</button>
<?php
$pageScripts = ['/cargochina/frontend/js/upload-utils.js', '/cargochina/frontend/js/photo_uploader.js'];
$pageScript = '/cargochina/frontend/js/receiving_receive.js';
?>
<script>
  window.RECEIVE_ORDER_ID = <?= (int)$orderId ?>;
</script>
<?php require __DIR__ . '/../../includes/area_footer.php'; ?>