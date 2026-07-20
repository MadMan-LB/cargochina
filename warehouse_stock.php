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
            'InTransitToWarehouse' => 'Partially Received / In Transit',
            'WarehouseReceived' => 'Received',
            'ReceivedAtWarehouse' => 'Legacy Received Status',
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
      <div class="col-md-2"><label class="form-label small"><?= htmlspecialchars(clmsT('Item Type')) ?></label><select class="form-select form-select-sm" id="filterStockItemType"><option value=""><?= htmlspecialchars(clmsT('All types')) ?></option><option value="normal"><?= htmlspecialchars(clmsT('Normal')) ?></option><option value="replica"><?= htmlspecialchars(clmsT('Copy / replica')) ?></option><option value="cosmetics"><?= htmlspecialchars(clmsT('Cosmetics')) ?></option><option value="branded"><?= htmlspecialchars(clmsT('Branded')) ?></option><option value="food"><?= htmlspecialchars(clmsT('Food')) ?></option><option value="dangerous"><?= htmlspecialchars(clmsT('Dangerous')) ?></option><option value="other"><?= htmlspecialchars(clmsT('Other')) ?></option><option value="unclassified"><?= htmlspecialchars(clmsT('Needs classification')) ?></option></select></div>
      <div class="col-md-2 d-flex flex-wrap align-items-end gap-2"><button class="btn btn-primary btn-sm" onclick="loadStock()">Apply</button><button class="btn btn-outline-secondary btn-sm" onclick="clearWarehouseStockFilters()"><?= htmlspecialchars(clmsT('Clear filters')) ?></button><button class="btn btn-outline-success btn-sm" onclick="exportWarehouseStockXlsx()"><?= htmlspecialchars(clmsT('Download')) ?></button></div>
    </div>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div class="form-check mb-0">
        <input class="form-check-input" type="checkbox" id="stockDownloadSelectAll">
        <label class="form-check-label" for="stockDownloadSelectAll"><?= htmlspecialchars(clmsT('Select all on this page')) ?></label>
      </div>
      <div class="d-flex align-items-center gap-2">
        <span class="small text-muted" id="stockDownloadSelectedCount"><?= htmlspecialchars(clmsT('{count} selected', ['count' => 0])) ?></span>
        <button type="button" class="btn btn-success btn-sm" id="stockDownloadSelectedBtn" disabled><?= htmlspecialchars(clmsT('Download selected')) ?></button>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-hover table-sm">
        <thead>
          <tr>
            <th class="text-center"><span class="visually-hidden"><?= htmlspecialchars(clmsT('Select')) ?></span></th>
            <th>Order</th>
            <th>Customer</th>
            <th>Supplier</th>
            <th>Status</th>
            <th>Item</th>
            <th>Qty</th>
            <th>Declared CBM</th>
            <th>Actual CBM</th>
            <th>Dimensions</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="stockTableBody"></tbody>
      </table>
    </div>
    <div class="d-flex justify-content-between align-items-center mt-3"><small class="text-muted" id="stockPageSummary"></small><div class="btn-group btn-group-sm"><button type="button" class="btn btn-outline-secondary" id="stockPrevPage">Previous</button><button type="button" class="btn btn-outline-secondary" id="stockNextPage">Next</button></div></div>
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

<?php $pageScripts = [
  'frontend/js/autocomplete.js',
  'frontend/js/bulk_excel_download.js?v=' . filemtime(__DIR__ . '/frontend/js/bulk_excel_download.js'),
];
$pageScript = 'frontend/js/warehouse_stock.js?v=' . filemtime(__DIR__ . '/frontend/js/warehouse_stock.js');
require 'includes/footer.php'; ?>
