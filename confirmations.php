<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['ChinaAdmin', 'LebanonAdmin', 'WarehouseStaff', 'SuperAdmin']);
$currentPage = 'confirmations';
$pageTitle = 'Order Confirmations';
require 'includes/layout.php';
?>
<h1 class="mb-4">Order Confirmations</h1>
<p class="text-muted mb-4">Confirm orders on behalf of customers. This system is internal — admins can confirm without customer action.</p>
<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 py-3">
    <span class="fw-semibold">Orders Awaiting Confirmation</span>
    <button type="button" class="btn btn-success btn-sm" id="bulkConfirmBtn" onclick="bulkConfirm()" disabled>Confirm Selected</button>
  </div>
  <div class="card-body">
    <div class="row mb-3 g-2 form-row-responsive">
      <div class="col-12 col-md-6 col-lg-2">
        <label class="form-label small">Search</label>
        <input type="text" class="form-control form-control-sm" id="filterSearch" placeholder="Customer, order ID…" autocomplete="off">
      </div>
      <div class="col-12 col-md-6 col-lg-2">
        <label class="form-label small">Date from</label>
        <input type="date" class="form-control form-control-sm" id="filterDateFrom">
      </div>
      <div class="col-12 col-md-6 col-lg-2">
        <label class="form-label small">Date to</label>
        <input type="date" class="form-control form-control-sm" id="filterDateTo">
      </div>
      <div class="col-12 col-md-6 col-lg-2">
        <label class="form-label small">Order</label>
        <input type="text" class="form-control form-control-sm" id="filterOrderId" placeholder="Type to search…" autocomplete="off">
      </div>
      <div class="col-12 col-md-6 col-lg-2">
        <label class="form-label small">Customer</label>
        <input type="text" class="form-control form-control-sm" id="filterCustomer" placeholder="Type to search">
      </div>
      <div class="col-12 col-md-6 col-lg-2">
        <label class="form-label small">Supplier</label>
        <input type="text" class="form-control form-control-sm" id="filterSupplier" placeholder="Type to search">
      </div>
      <div class="col-12 col-md-6 col-lg-2 d-flex align-items-end gap-2">
        <button type="button" class="btn btn-primary btn-sm" onclick="loadConfirmations()">Apply</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearFilters()">Clear</button>
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