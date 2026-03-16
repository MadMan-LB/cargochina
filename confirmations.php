<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['ChinaAdmin', 'LebanonAdmin', 'WarehouseStaff', 'SuperAdmin']);
$currentPage = 'confirmations';
$pageTitle = 'Order Confirmations';
require 'includes/layout.php';
?>
<div class="card page-hero-card mb-4">
  <div class="card-body d-flex flex-wrap justify-content-between align-items-start gap-3">
    <div>
      <h1 class="mb-2">Order Confirmations</h1>
      <p class="text-muted mb-0">Review warehouse actuals, keep priority accounts visible, and confirm orders internally without waiting for the customer portal flow.</p>
    </div>
    <div class="filter-toolbar-card" style="max-width: 360px;">
      <div class="filter-toolbar-head mb-2">
        <div>
          <h6>Internal Confirmation Mode</h6>
          <div class="filter-toolbar-subtext">Admins can confirm on the customer’s behalf once warehouse actuals look correct.</div>
        </div>
      </div>
      <div class="small text-muted">Use this queue for fast follow-up after receiving, especially when portal confirmation is not required operationally.</div>
    </div>
  </div>
</div>

<div class="metric-card-grid mb-4">
  <div class="metric-card">
    <div class="eyebrow">Awaiting Now</div>
    <div class="value" id="confirmQueueCount">0</div>
    <div class="detail" id="confirmQueueDetail">Orders currently waiting for confirmation.</div>
  </div>
  <div class="metric-card">
    <div class="eyebrow">Priority Customers</div>
    <div class="value" id="confirmPriorityCount">0</div>
    <div class="detail" id="confirmPriorityDetail">Flagged accounts in the current queue.</div>
  </div>
  <div class="metric-card">
    <div class="eyebrow">Due Soon</div>
    <div class="value" id="confirmDueSoonCount">0</div>
    <div class="detail" id="confirmDueSoonDetail">Expected-ready dates within the next 7 days.</div>
  </div>
  <div class="metric-card">
    <div class="eyebrow">Selected</div>
    <div class="value" id="confirmSelectedCount">0</div>
    <div class="detail" id="confirmSelectedDetail">Orders picked for bulk confirmation.</div>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 py-3">
    <span class="fw-semibold">Orders Awaiting Confirmation</span>
    <button type="button" class="btn btn-success btn-sm" id="bulkConfirmBtn" onclick="bulkConfirm()" disabled>Confirm Selected</button>
  </div>
  <div class="card-body">
    <div class="filter-toolbar-grid mb-4">
      <div class="filter-toolbar-card soft">
        <div class="filter-toolbar-head">
          <div>
            <h6>Filter The Queue</h6>
            <div class="filter-toolbar-subtext">Search by order, customer, supplier, or date range before confirming one by one or in bulk.</div>
          </div>
          <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearFilters()">Clear</button>
        </div>
        <div class="row g-3 form-row-responsive">
          <div class="col-12 col-md-6">
            <label class="form-label small">Search</label>
            <input type="text" class="form-control form-control-sm" id="filterSearch" placeholder="Customer, supplier, order ID…" autocomplete="off">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label small">Order</label>
            <input type="text" class="form-control form-control-sm" id="filterOrderId" placeholder="Type to search…" autocomplete="off">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label small">Customer</label>
            <input type="text" class="form-control form-control-sm" id="filterCustomer" placeholder="Type to search">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label small">Supplier</label>
            <input type="text" class="form-control form-control-sm" id="filterSupplier" placeholder="Type to search">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label small">Date from</label>
            <input type="date" class="form-control form-control-sm" id="filterDateFrom">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label small">Date to</label>
            <input type="date" class="form-control form-control-sm" id="filterDateTo">
          </div>
        </div>
        <div class="filter-summary-row">
          <div class="summary-text" id="confirmFilterSummary">Showing the full confirmation queue.</div>
          <button type="button" class="btn btn-primary btn-sm" onclick="loadConfirmations()">Apply</button>
        </div>
      </div>
      <div class="filter-toolbar-card">
        <div class="filter-toolbar-head">
          <div>
            <h6>Batch Confirmation Tips</h6>
            <div class="filter-toolbar-subtext">Use bulk confirm once the warehouse actuals and any high-priority notes are already reviewed.</div>
          </div>
        </div>
        <div class="stack-card-list">
          <div class="border rounded-3 px-3 py-2 bg-white">
            <div class="fw-semibold small">Check warehouse actuals first</div>
            <div class="text-muted small">CBM, weight, and cartons should already be recorded in receiving before confirmation.</div>
          </div>
          <div class="border rounded-3 px-3 py-2 bg-white">
            <div class="fw-semibold small">Priority customers stay visible</div>
            <div class="text-muted small">Flagged customers show an attention badge inside the table so they are not buried in the queue.</div>
          </div>
          <div class="border rounded-3 px-3 py-2 bg-white">
            <div class="fw-semibold small">Current bulk selection</div>
            <div class="text-muted small" id="confirmSelectionHint">No orders selected yet.</div>
          </div>
        </div>
      </div>
    </div>
    <div id="confirmationsTable" class="table-responsive">
      <table class="table table-hover table-striped table-sm align-middle mb-0">
        <thead>
          <tr>
            <th><input type="checkbox" class="form-check-input" id="selectAllConfirm" aria-label="Select all" title="Select all"></th>
            <th>ID</th>
            <th>Customer</th>
            <th>Supplier</th>
            <th>Expected Ready</th>
            <th>Status</th>
            <th>CBM</th>
            <th>Weight</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>
<?php
$pageScripts = ['frontend/js/autocomplete.js'];
$pageScript = 'frontend/js/confirmations.js';
require 'includes/footer.php';
?>
