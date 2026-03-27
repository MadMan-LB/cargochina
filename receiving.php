<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['WarehouseStaff', 'SuperAdmin']);
$currentPage = 'receiving';
$pageTitle = 'Warehouse Receiving';
require 'includes/layout.php';
?>
<div class="card page-hero-card mb-4">
  <div class="card-body d-flex flex-wrap justify-content-between align-items-start gap-3">
    <div>
      <h1 class="mb-2">Warehouse Receiving</h1>
      <p class="text-muted mb-0">Review inbound orders, spot special handling early, and record actual warehouse measurements with evidence before the next workflow step.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <button type="button" class="btn btn-primary" onclick="document.getElementById('receiveOrderSearch')?.focus()">Quick Receive</button>
      <button type="button" class="btn btn-outline-success" onclick="exportReceivingXlsx()" title="Export queue to XLSX">Export XLSX</button>
    </div>
  </div>
</div>

<div class="metric-card-grid mb-4">
  <div class="metric-card">
    <div class="eyebrow">Visible Orders</div>
    <div class="value" id="receiveVisibleCount">0</div>
    <div class="detail" id="receiveVisibleDetail">Orders matching the current filters.</div>
  </div>
  <div class="metric-card">
    <div class="eyebrow">Priority Accounts</div>
    <div class="value" id="receivePriorityCount">0</div>
    <div class="detail" id="receivePriorityDetail">Customers that need closer handling.</div>
  </div>
  <div class="metric-card">
    <div class="eyebrow">Cartons In View</div>
    <div class="value" id="receiveCartonCount">0</div>
    <div class="detail" id="receiveCartonDetail">Total cartons across the current queue.</div>
  </div>
  <div class="metric-card">
    <div class="eyebrow">Orders With Alerts</div>
    <div class="value" id="receiveAlertCount">0</div>
    <div class="detail" id="receiveAlertDetail">High-alert notes or product-level warnings.</div>
  </div>
</div>

<div class="filter-toolbar-grid mb-4">
  <div class="filter-toolbar-card soft">
    <div class="filter-toolbar-head">
      <div>
        <div class="title">Statuses & Focus</div>
        <div class="filter-toolbar-subtext">Keep the intake queue tight, then pull urgent or sensitive work to the top.</div>
      </div>
    </div>
    <div class="mb-3">
      <label class="form-label small mb-2 d-block">Statuses</label>
      <div class="filter-chip-grid" id="receivingStatusList">
        <div class="form-check filter-chip">
          <input class="form-check-input receiving-status-filter" type="checkbox" value="Approved" id="receiveStatusApproved">
          <label class="form-check-label" for="receiveStatusApproved">Approved</label>
        </div>
        <div class="form-check filter-chip">
          <input class="form-check-input receiving-status-filter" type="checkbox" value="InTransitToWarehouse" id="receiveStatusInTransit">
          <label class="form-check-label" for="receiveStatusInTransit">In Transit</label>
        </div>
      </div>
    </div>
    <div>
      <label class="form-label small mb-2 d-block">Quick focus</label>
      <div class="filter-chip-grid">
        <div class="form-check filter-chip">
          <input class="form-check-input receiving-focus-filter" type="checkbox" value="priority" id="filterPriorityOnly">
          <label class="form-check-label" for="filterPriorityOnly">Priority accounts only</label>
        </div>
        <div class="form-check filter-chip">
          <input class="form-check-input receiving-focus-filter" type="checkbox" value="alerts" id="filterAlertsOnly">
          <label class="form-check-label" for="filterAlertsOnly">High-alert orders only</label>
        </div>
      </div>
    </div>
    <div class="filter-summary-row">
      <div class="summary-text" id="receiveStatusSummary">Core intake statuses: Approved, In Transit.</div>
    </div>
  </div>
  <div class="filter-toolbar-card soft">
    <div class="filter-toolbar-head">
      <div>
        <h6>Filter The Queue</h6>
        <div class="filter-toolbar-subtext">Search by supplier, customer, dates, or shipping code before switching between list, calendar, and schedule views.</div>
      </div>
      <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearReceivingFilters()">Clear</button>
    </div>
    <div class="row g-3 align-items-end">
      <div class="col-12 col-md-6 col-xl-2">
        <label class="form-label small mb-0">Supplier</label>
        <input type="text" class="form-control form-control-sm" id="filterSupplier" placeholder="Type to search supplier..." autocomplete="off">
        <input type="hidden" id="filterSupplierId">
      </div>
      <div class="col-12 col-md-6 col-xl-2">
        <label class="form-label small mb-0">Customer</label>
        <input type="text" class="form-control form-control-sm" id="filterCustomer" placeholder="Type to search customer..." autocomplete="off">
        <input type="hidden" id="filterCustomerId">
      </div>
      <div class="col-12 col-md-6 col-xl-2">
        <label class="form-label small mb-0">Date from</label>
        <input type="date" class="form-control form-control-sm" id="filterDateFrom">
      </div>
      <div class="col-12 col-md-6 col-xl-2">
        <label class="form-label small mb-0">Date to</label>
        <input type="date" class="form-control form-control-sm" id="filterDateTo">
      </div>
      <div class="col-12 col-md-6 col-xl-2">
        <label class="form-label small mb-0">Shipping code</label>
        <input type="text" class="form-control form-control-sm" id="filterShippingCode" placeholder="e.g. DUM_C003">
      </div>
      <div class="col-12 col-md-6 col-xl-2 d-grid gap-2">
        <button type="button" class="btn btn-primary btn-sm" id="applyFiltersBtn" onclick="applyFilters()">Apply</button>
        <button type="button" class="btn btn-outline-success btn-sm" onclick="exportReceivingXlsx()" title="Export queue to XLSX">Export XLSX</button>
      </div>
    </div>
    <div class="filter-summary-row">
      <div class="summary-text" id="receiveFilterSummary">Showing the full inbound receiving queue.</div>
      <div class="summary-text">Tip: the calendar helps spot clustered arrival days, while the schedule keeps the day-by-day receiving plan compact.</div>
    </div>
  </div>
</div>

<!-- Tabs: List | Calendar | Schedule -->
<ul class="nav nav-tabs mb-3" role="tablist">
  <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabList">List</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabCalendar">Calendar</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabSchedule">Schedule</a></li>
</ul>

<div class="tab-content">
  <div class="tab-pane fade show active" id="tabList">
    <div id="warehouseList" class="row g-3"></div>
    <div id="warehouseListEmpty" class="text-muted text-center py-5 d-none">No orders match filters.</div>
  </div>
  <div class="tab-pane fade" id="tabCalendar">
    <div class="card">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h6 class="mb-0">Incoming shipments</h6>
          <div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="calPrev">←</button>
            <span class="mx-2" id="calMonthLabel">—</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="calNext">→</button>
          </div>
        </div>
        <div id="calendarGrid" class="warehouse-calendar"></div>
      </div>
    </div>
  </div>
  <div class="tab-pane fade" id="tabSchedule">
    <div class="card">
      <div class="card-body">
        <h6 class="mb-3">Warehouse activity by date</h6>
        <div id="scheduleList" class="stack-card-list"></div>
      </div>
    </div>
  </div>
</div>

<p class="text-muted small mb-3">To merge orders into the same container or keep them in separate containers, use <a href="<?= $basePath ?? '/cargochina' ?>/consolidation.php">Consolidation</a>: add orders to a shipment draft = one container; create multiple drafts for multiple containers.</p>

<!-- Receive Order Card -->
<div class="card mt-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>Receive Order</span>
    <button class="btn btn-primary btn-sm" id="receiveNewBtn" onclick="showReceiveForm()">+ New receive</button>
  </div>
  <div class="card-body">
    <div id="receiveSelectSection">
      <div class="row mb-3 form-row-responsive">
        <div class="col-12 col-md-6">
          <label class="form-label">Select Order (Approved / In Transit)</label>
          <input type="text" class="form-control" id="receiveOrderSearch" placeholder="Search: #ID, customer, supplier, phone, shipping code, item description — verify order details below, then enter actuals" autocomplete="off">
          <input type="hidden" id="receiveOrderId">
          <small class="text-muted">Selected order shows: #ID, customer, supplier, expected date, items. Verify before entering actual cartons, CBM, and weight.</small>
        </div>
      </div>
    </div>
    <div id="receiveForm" class="d-none">
      <div id="receiveDeclaredSummary" class="alert alert-light border mb-3 py-2 small">
        <strong>Declared (verify before entering actuals):</strong>
        <span id="receiveDeclaredText">—</span>
      </div>
      <div class="row mb-3 form-row-responsive">
        <div class="col-12 col-md-4 mb-2"><label class="form-label">Actual Cartons *</label><input type="number" class="form-control" id="actualCartons" min="0" required></div>
        <div class="col-12 col-md-4 mb-2">
          <label class="form-label">Actual CBM</label>
          <input type="number" step="0.0001" class="form-control" id="actualCbm" min="0" placeholder="Direct or from L×W×H">
        </div>
        <div class="col-12 col-md-4 mb-2">
          <label class="form-label">L / W / H (cm)</label>
          <div class="input-group input-group-sm">
            <input type="number" step="0.01" class="form-control" id="actualLength" placeholder="L" title="Length cm">
            <input type="number" step="0.01" class="form-control" id="actualWidth" placeholder="W" title="Width cm">
            <input type="number" step="0.01" class="form-control" id="actualHeight" placeholder="H" title="Height cm">
          </div>
          <small class="text-muted">Optional: auto-calculates CBM</small>
        </div>
        <div class="col-12 col-md-4 mb-2"><label class="form-label">Actual Weight *</label><input type="number" step="0.0001" class="form-control" id="actualWeight" min="0" required></div>
      </div>
      <div class="row mb-3 form-row-responsive">
        <div class="col-12 col-md-4 mb-2"><label class="form-label">Condition</label><select class="form-select" id="condition">
            <option value="good">Good</option>
            <option value="damaged">Damaged</option>
            <option value="partial">Partial</option>
          </select></div>
        <div class="col-12 col-md-8 mb-2"><label class="form-label">Notes</label><input type="text" class="form-control" id="receiveNotes"></div>
      </div>
      <div class="mb-3" id="itemLevelSection">
        <button type="button" class="btn btn-outline-secondary btn-sm mb-2" id="toggleItemLevel" aria-expanded="false" aria-controls="itemLevelTable">Record per-item actuals (optional)</button>
        <div id="itemLevelTable" class="d-none table-responsive">
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
      <div class="mb-3">
        <label class="form-label">Evidence Photos <span class="text-danger">*required if variance or damage</span></label>
        <div id="variancePhotoAlert" class="alert alert-warning py-2 d-none" role="alert">
          <strong>Photo evidence required.</strong> Variance or damage detected — add at least one photo before recording receipt.
        </div>
        <input type="file" class="d-none" id="receivePhotos" multiple accept="image/*">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="receiveAddPhotoBtn" onclick="document.getElementById('receivePhotos').click()">Add Photo</button>
        <span class="ms-2 text-muted small">camera or gallery</span>
        <div id="photoPreview" class="mt-2 d-flex flex-wrap gap-2"></div>
      </div>
      <button type="button" class="btn btn-primary" id="submitReceiveBtn" onclick="submitReceive()">Record Receipt</button>
    </div>
  </div>
</div>
<?php $pageScripts = [
  'frontend/js/photo_uploader.js?v=' . filemtime(__DIR__ . '/frontend/js/photo_uploader.js'),
  'frontend/js/autocomplete.js?v=' . filemtime(__DIR__ . '/frontend/js/autocomplete.js'),
];
$pageScript = 'frontend/js/receiving.js?v=' . filemtime(__DIR__ . '/frontend/js/receiving.js');
require 'includes/footer.php'; ?>
