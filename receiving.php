<?php
$currentPage = 'receiving';
$pageTitle = 'Warehouse Receiving';
require 'includes/layout.php';
?>
<link rel="stylesheet" href="frontend/css/style.css">
<div class="col-12">
  <h1 class="mb-4">Warehouse Receiving</h1>
  <div class="card">
    <div class="card-header">Receive Order</div>
    <div class="card-body">
      <div class="row mb-3">
        <div class="col-md-6">
          <label class="form-label">Select Order (Approved / In Transit)</label>
          <select class="form-select" id="receiveOrder">
            <option value="">— Select order —</option>
          </select>
        </div>
      </div>
      <div id="receiveForm" class="d-none">
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
        <div class="mb-3">
          <label class="form-label">Evidence Photos (required if variance or damage)</label>
          <input type="file" class="form-control" id="receivePhotos" multiple accept="image/*">
          <div id="photoPreview" class="mt-2"></div>
        </div>
        <button type="button" class="btn btn-primary" onclick="submitReceive()">Record Receipt</button>
      </div>
    </div>
  </div>
</div>
<?php $pageScript = 'frontend/js/receiving.js';
require 'includes/footer.php'; ?>