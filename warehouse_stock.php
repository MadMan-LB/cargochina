<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['WarehouseStaff', 'ChinaAdmin', 'LebanonAdmin', 'SuperAdmin', 'ContainersStaff']);
$currentPage = 'warehouse_stock';
$pageTitle = 'Warehouse Stock';
require 'includes/layout.php';
?>
<h1 class="mb-4">Warehouse Stock</h1>
<p class="text-muted mb-4">Current stock at warehouse — auto-confirmed and ready-to-move orders that still remain operationally in stock.</p>

<div class="card">
  <div class="card-header">Stock by Order</div>
  <div class="card-body">
    <div class="row mb-3 g-2">
      <div class="col-md-3">
        <label class="form-label small">Customer</label>
        <input type="text" class="form-control form-control-sm" id="filterCustomerSearch" placeholder="Type to search customer..." autocomplete="off">
        <input type="hidden" id="filterCustomerId">
      </div>
      <div class="col-md-3">
        <label class="form-label small">Supplier</label>
        <input type="text" class="form-control form-control-sm" id="filterSupplierSearch" placeholder="Type to search supplier..." autocomplete="off">
        <input type="hidden" id="filterSupplierId">
      </div>
      <div class="col-md-4">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <label class="form-label small mb-0">Statuses</label>
          <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" onclick="clearStockStatusFilter()">Clear</button>
        </div>
        <div class="filter-chip-grid" id="filterStatusList">
          <?php foreach ([
            'ReceivedAtWarehouse' => 'Received',
            'AwaitingCustomerConfirmation' => 'Legacy Awaiting Confirmation',
            'Confirmed' => 'Confirmed',
            'ReadyForConsolidation' => 'Ready for Consolidation',
          ] as $statusValue => $statusLabel): ?>
            <div class="form-check filter-chip">
              <input class="form-check-input stock-status-filter" type="checkbox" value="<?= htmlspecialchars($statusValue) ?>" id="stockStatus<?= htmlspecialchars($statusValue) ?>" onchange="updateStockStatusFilterSummary();loadStock()">
              <label class="form-check-label" for="stockStatus<?= htmlspecialchars($statusValue) ?>"><?= htmlspecialchars($statusLabel) ?></label>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="row g-2 mt-1">
          <div class="col-6">
            <select class="form-select form-select-sm" id="filterStatusMode" onchange="updateStockStatusFilterSummary();loadStock()">
              <option value="include">Include selected</option>
              <option value="exclude">Exclude selected</option>
            </select>
          </div>
          <div class="col-6 d-flex align-items-center">
            <small class="text-muted" id="filterStatusSummary">All statuses</small>
          </div>
        </div>
      </div>
      <div class="col-md-2"><label class="form-label small">Search</label><input type="text" class="form-control form-control-sm" id="filterQ" placeholder="Description..."></div>
      <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary btn-sm" onclick="loadStock()">Apply</button></div>
    </div>
    <div class="table-responsive">
      <table class="table table-hover table-sm">
        <thead>
          <tr>
            <th>Order</th>
            <th>Customer</th>
            <th>Supplier</th>
            <th>Status</th>
            <th>Item</th>
            <th>Qty</th>
            <th>Declared CBM</th>
            <th>Actual CBM</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="stockTableBody"></tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="stockOrderInfoModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="stockOrderInfoTitle">Order Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="stockOrderInfoBody">
        <div class="text-center py-4 text-muted">Loading order details…</div>
      </div>
    </div>
  </div>
</div>

<?php $pageScripts = ['frontend/js/autocomplete.js'];
$pageScript = 'frontend/js/warehouse_stock.js';
require 'includes/footer.php'; ?>
