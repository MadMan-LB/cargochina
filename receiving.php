<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['WarehouseStaff', 'SuperAdmin']);
$currentPage = 'receiving';
$pageTitle = clmsT('Warehouse Receiving');
require 'includes/layout.php';
?>
<div class="card page-hero-card mb-4">
  <div class="card-body d-flex flex-wrap justify-content-between align-items-start gap-3">
    <div>
      <h1 class="mb-2"><?= clmsT('Warehouse Receiving') ?></h1>
      <p class="text-muted mb-0"><?= clmsT('Review inbound orders, spot special handling early, and record actual warehouse measurements with evidence before the next workflow step.') ?></p>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <button type="button" class="btn btn-primary" onclick="document.getElementById('receiveOrderSearch')?.focus()"><?= clmsT('Quick Receive') ?></button>
      <button type="button" class="btn btn-outline-success" onclick="exportReceivingXlsx()" title="<?= clmsT('Export queue to XLSX') ?>"><?= clmsT('Export XLSX') ?></button>
    </div>
  </div>
</div>

<div class="metric-card-grid mb-4">
  <div class="metric-card">
    <div class="eyebrow"><?= clmsT('Visible Orders') ?></div>
    <div class="value" id="receiveVisibleCount">0</div>
    <div class="detail" id="receiveVisibleDetail"><?= clmsT('Orders matching the current filters.') ?></div>
  </div>
  <div class="metric-card">
    <div class="eyebrow"><?= clmsT('Priority Accounts') ?></div>
    <div class="value" id="receivePriorityCount">0</div>
    <div class="detail" id="receivePriorityDetail"><?= clmsT('Customers that need closer handling.') ?></div>
  </div>
  <div class="metric-card">
    <div class="eyebrow"><?= clmsT('Cartons In View') ?></div>
    <div class="value" id="receiveCartonCount">0</div>
    <div class="detail" id="receiveCartonDetail"><?= clmsT('Total cartons across the current queue.') ?></div>
  </div>
  <div class="metric-card">
    <div class="eyebrow"><?= clmsT('Orders With Alerts') ?></div>
    <div class="value" id="receiveAlertCount">0</div>
    <div class="detail" id="receiveAlertDetail"><?= clmsT('High-alert notes or product-level warnings.') ?></div>
  </div>
</div>

<div class="filter-toolbar-grid mb-4">
  <div class="filter-toolbar-card soft">
    <div class="filter-toolbar-head">
      <div>
        <div class="title"><?= clmsT('Statuses & Focus') ?></div>
        <div class="filter-toolbar-subtext"><?= clmsT('Keep the intake queue tight, then pull urgent or sensitive work to the top.') ?></div>
      </div>
    </div>
    <div class="mb-3">
      <label class="form-label small mb-2 d-block"><?= clmsT('Statuses') ?></label>
      <div class="filter-chip-grid" id="receivingStatusList">
        <div class="form-check filter-chip">
          <input class="form-check-input receiving-status-filter" type="checkbox" value="Approved" id="receiveStatusApproved">
          <label class="form-check-label" for="receiveStatusApproved"><?= clmsT('Approved') ?></label>
        </div>
        <div class="form-check filter-chip">
          <input class="form-check-input receiving-status-filter" type="checkbox" value="InTransitToWarehouse" id="receiveStatusInTransit">
          <label class="form-check-label" for="receiveStatusInTransit"><?= clmsT('In Transit') ?></label>
        </div>
      </div>
    </div>
    <div>
      <label class="form-label small mb-2 d-block"><?= clmsT('Quick focus') ?></label>
      <div class="filter-chip-grid">
        <div class="form-check filter-chip">
          <input class="form-check-input receiving-focus-filter" type="checkbox" value="priority" id="filterPriorityOnly">
          <label class="form-check-label" for="filterPriorityOnly"><?= clmsT('Priority accounts only') ?></label>
        </div>
        <div class="form-check filter-chip">
          <input class="form-check-input receiving-focus-filter" type="checkbox" value="alerts" id="filterAlertsOnly">
          <label class="form-check-label" for="filterAlertsOnly"><?= clmsT('High-alert orders only') ?></label>
        </div>
      </div>
    </div>
    <div class="filter-summary-row">
      <div class="summary-text" id="receiveStatusSummary"><?= clmsT('Core intake statuses: Approved, In Transit.') ?></div>
    </div>
  </div>
  <div class="filter-toolbar-card soft">
    <div class="filter-toolbar-head">
      <div>
        <h6><?= clmsT('Filter The Queue') ?></h6>
        <div class="filter-toolbar-subtext"><?= clmsT('Search by supplier, customer, dates, or shipping code before switching between list, calendar, and schedule views.') ?></div>
      </div>
      <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearReceivingFilters()"><?= clmsT('Clear') ?></button>
    </div>
    <div class="row g-3 align-items-end">
      <div class="col-12 col-md-6 col-xl-2">
        <label class="form-label small mb-0"><?= clmsT('Supplier') ?></label>
        <input type="text" class="form-control form-control-sm" id="filterSupplier" placeholder="<?= clmsT('Type to search supplier...') ?>" autocomplete="off">
        <input type="hidden" id="filterSupplierId">
      </div>
      <div class="col-12 col-md-6 col-xl-2">
        <label class="form-label small mb-0"><?= clmsT('Customer') ?></label>
        <input type="text" class="form-control form-control-sm" id="filterCustomer" placeholder="<?= clmsT('Type to search customer...') ?>" autocomplete="off">
        <input type="hidden" id="filterCustomerId">
      </div>
      <div class="col-12 col-md-6 col-xl-2">
        <label class="form-label small mb-0"><?= clmsT('Date from') ?></label>
        <input type="date" class="form-control form-control-sm" id="filterDateFrom">
      </div>
      <div class="col-12 col-md-6 col-xl-2">
        <label class="form-label small mb-0"><?= clmsT('Date to') ?></label>
        <input type="date" class="form-control form-control-sm" id="filterDateTo">
      </div>
      <div class="col-12 col-md-6 col-xl-2">
        <label class="form-label small mb-0"><?= clmsT('Shipping Code') ?></label>
        <input type="text" class="form-control form-control-sm" id="filterShippingCode" placeholder="<?= clmsT('e.g. DUM_C003') ?>">
      </div>
      <div class="col-12 col-md-6 col-xl-2 d-grid gap-2">
        <button type="button" class="btn btn-primary btn-sm" id="applyFiltersBtn" onclick="applyFilters()"><?= clmsT('Apply') ?></button>
        <button type="button" class="btn btn-outline-success btn-sm" onclick="exportReceivingXlsx()" title="<?= clmsT('Export queue to XLSX') ?>"><?= clmsT('Export XLSX') ?></button>
      </div>
    </div>
    <div class="filter-summary-row">
      <div class="summary-text" id="receiveFilterSummary"><?= clmsT('Showing the full inbound receiving queue.') ?></div>
      <div class="summary-text"><?= clmsT('Tip: the calendar helps spot clustered arrival days, while the schedule keeps the day-by-day receiving plan compact.') ?></div>
    </div>
  </div>
</div>

<!-- Tabs: List | Calendar | Schedule -->
<ul class="nav nav-tabs mb-3" role="tablist">
  <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabList"><?= clmsT('List') ?></a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabCalendar"><?= clmsT('Calendar') ?></a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabSchedule"><?= clmsT('Schedule') ?></a></li>
</ul>

<div class="tab-content">
  <div class="tab-pane fade show active" id="tabList">
    <div id="warehouseList" class="row g-3"></div>
    <div id="warehouseListEmpty" class="text-muted text-center py-5 d-none"><?= clmsT('No orders match filters.') ?></div>
  </div>
  <div class="tab-pane fade" id="tabCalendar">
    <div class="card">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h6 class="mb-0"><?= clmsT('Incoming shipments') ?></h6>
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
        <h6 class="mb-3"><?= clmsT('Warehouse activity by date') ?></h6>
        <div id="scheduleList" class="stack-card-list"></div>
      </div>
    </div>
  </div>
</div>

<p class="text-muted small mb-3"><?= clmsT('To merge orders into the same container or keep them in separate containers, use Consolidation') ?> <a href="<?= $basePath ?? '/cargochina' ?>/consolidation.php"><?= clmsT('Consolidation') ?></a><?= clmsT(': add orders to a shipment draft = one container; create multiple drafts for multiple containers.') ?></p>

<!-- Receive Order Card -->
<div class="card mt-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><?= clmsT('Receive Order') ?></span>
    <button class="btn btn-primary btn-sm" id="receiveNewBtn" onclick="showReceiveForm()"><?= clmsT('+ New receive') ?></button>
  </div>
  <div class="card-body">
    <div id="receiveSelectSection">
      <div class="row mb-3 form-row-responsive">
        <div class="col-12 col-md-6">
          <label class="form-label"><?= clmsT('Select Order (Approved / In Transit)') ?></label>
          <input type="text" class="form-control" id="receiveOrderSearch" placeholder="<?= clmsT('Search: #ID, customer, supplier, phone, shipping code, item description — verify order details below, then enter actuals') ?>" autocomplete="off">
          <input type="hidden" id="receiveOrderId">
          <small class="text-muted"><?= clmsT('Selected order shows: #ID, customer, supplier, expected date, items. Verify before entering actual cartons, CBM, and weight.') ?></small>
        </div>
      </div>
    </div>
    <div id="receiveForm" class="d-none">
      <div id="receiveDeclaredSummary" class="alert alert-light border mb-3 py-2 small">
        <strong><?= clmsT('Declared (verify before entering actuals):') ?></strong>
        <span id="receiveDeclaredText">—</span>
      </div>
      <div class="row mb-3 form-row-responsive">
        <div class="col-12 col-md-4 mb-2"><label class="form-label"><?= clmsT('Actual Cartons *') ?></label><input type="number" class="form-control" id="actualCartons" min="0" required></div>
        <div class="col-12 col-md-4 mb-2">
          <label class="form-label"><?= clmsT('Actual CBM') ?></label>
          <input type="number" step="0.0001" class="form-control" id="actualCbm" min="0" placeholder="<?= clmsT('Direct or from L×W×H') ?>">
        </div>
        <div class="col-12 col-md-4 mb-2">
          <label class="form-label"><?= clmsT('L / W / H (cm)') ?></label>
          <div class="input-group input-group-sm">
            <input type="number" step="0.01" class="form-control" id="actualLength" placeholder="<?= clmsT('L') ?>" title="<?= clmsT('Length cm') ?>">
            <input type="number" step="0.01" class="form-control" id="actualWidth" placeholder="<?= clmsT('W') ?>" title="<?= clmsT('Width cm') ?>">
            <input type="number" step="0.01" class="form-control" id="actualHeight" placeholder="<?= clmsT('H') ?>" title="<?= clmsT('Height cm') ?>">
          </div>
          <small class="text-muted"><?= clmsT('Optional: auto-calculates CBM') ?></small>
        </div>
        <div class="col-12 col-md-4 mb-2"><label class="form-label"><?= clmsT('Actual Weight *') ?></label><input type="number" step="0.0001" class="form-control" id="actualWeight" min="0" required></div>
      </div>
      <div class="row mb-3 form-row-responsive">
        <div class="col-12 col-md-4 mb-2"><label class="form-label"><?= clmsT('Condition') ?></label><select class="form-select" id="condition">
            <option value="good"><?= clmsT('Good') ?></option>
            <option value="damaged"><?= clmsT('Damaged') ?></option>
            <option value="partial"><?= clmsT('Partial') ?></option>
          </select></div>
        <div class="col-12 col-md-8 mb-2"><label class="form-label"><?= clmsT('Notes') ?></label><input type="text" class="form-control" id="receiveNotes"></div>
      </div>
      <div class="mb-3" id="itemLevelSection">
        <button type="button" class="btn btn-outline-secondary btn-sm mb-2" id="toggleItemLevel" aria-expanded="false" aria-controls="itemLevelTable"><?= clmsT('Record per-item actuals (optional)') ?></button>
        <div id="itemLevelTable" class="d-none table-responsive">
          <table class="table table-sm">
            <thead>
              <tr>
                <th><?= clmsT('Item') ?></th>
                <th><?= clmsT('Declared') ?></th>
                <th><?= clmsT('Actual Cartons *') ?></th>
                <th><?= clmsT('Actual CBM') ?></th>
                <th><?= clmsT('Actual Weight') ?></th>
                <th><?= clmsT('Condition') ?></th>
                <th><?= clmsT('Photos') ?></th>
              </tr>
            </thead>
            <tbody id="itemLevelBody"></tbody>
          </table>
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label"><?= clmsT('Evidence Photos') ?> <span class="text-danger"><?= clmsT('*required if variance or damage') ?></span></label>
        <div id="variancePhotoAlert" class="alert alert-warning py-2 d-none" role="alert">
          <strong><?= clmsT('Photo evidence required.') ?></strong> <?= clmsT('Variance or damage detected — add at least one photo before recording receipt.') ?>
        </div>
        <input type="file" class="d-none" id="receivePhotos" multiple accept="image/*">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="receiveAddPhotoBtn" onclick="document.getElementById('receivePhotos').click()"><?= clmsT('Add Photo') ?></button>
        <span class="ms-2 text-muted small"><?= clmsT('camera or gallery') ?></span>
        <div id="photoPreview" class="mt-2 d-flex flex-wrap gap-2"></div>
      </div>
      <button type="button" class="btn btn-primary" id="submitReceiveBtn" onclick="submitReceive()"><?= clmsT('Record Receipt') ?></button>
    </div>
  </div>
</div>
<?php $pageScripts = [
  'frontend/js/photo_uploader.js?v=' . filemtime(__DIR__ . '/frontend/js/photo_uploader.js'),
  'frontend/js/autocomplete.js?v=' . filemtime(__DIR__ . '/frontend/js/autocomplete.js'),
];
$pageScript = 'frontend/js/receiving.js?v=' . filemtime(__DIR__ . '/frontend/js/receiving.js');
require 'includes/footer.php'; ?>
