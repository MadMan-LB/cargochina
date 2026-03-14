<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin']);
$currentPage = 'hs_code_tax';
$pageTitle = 'HS Code Tax';
require 'includes/layout.php';
?>
<h1 class="mb-4">HS Code Tax</h1>
<p class="text-muted mb-4">Internal lookup and planning tool for HS code tax rates and estimated import-duty exposure.</p>

<div class="row g-4">
  <div class="col-12 col-xl-7">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>Tax Rate Library</span>
        <button class="btn btn-primary btn-sm" type="button" onclick="openTaxRateForm()">+ Add Rate</button>
      </div>
      <div class="card-body">
        <div class="row g-2 mb-3">
          <div class="col-12 col-md-7">
            <label class="form-label small">Search</label>
            <input type="text" class="form-control form-control-sm" id="taxRateSearch" placeholder="HS code, country, notes..." autocomplete="off">
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label small">Country</label>
            <input type="text" class="form-control form-control-sm text-uppercase" id="taxRateCountryFilter" value="LB" maxlength="10" placeholder="LB">
          </div>
          <div class="col-12 col-md-2 d-flex align-items-end">
            <button class="btn btn-outline-secondary btn-sm w-100" type="button" onclick="clearTaxRateFilters()">Clear</button>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table table-hover table-striped table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>HS Code</th>
                <th>Country</th>
                <th>Rate %</th>
                <th>Effective</th>
                <th>Notes</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody id="taxRatesTableBody">
              <tr><td colspan="6" class="text-muted text-center py-4">Loading...</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-5">
    <div class="card h-100">
      <div class="card-header">Estimator</div>
      <div class="card-body">
        <form id="taxEstimateForm" onsubmit="event.preventDefault();runHsTaxEstimate();">
          <div class="row g-3">
            <div class="col-12 col-md-4">
              <label class="form-label">Country</label>
              <input type="text" class="form-control text-uppercase" id="estimateCountryCode" value="LB" maxlength="10" placeholder="LB">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Valuation Mode</label>
              <select class="form-select" id="estimateValuationMode">
                <option value="auto">Auto</option>
                <option value="buy">Buy-first</option>
                <option value="sell">Sell-first</option>
              </select>
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Context</label>
              <select class="form-select" id="estimateContextType" onchange="toggleEstimateContext()">
                <option value="hs_code">HS Code</option>
                <option value="product">Product</option>
                <option value="order">Order</option>
                <option value="container">Container</option>
              </select>
            </div>
            <div class="col-12 estimate-context" data-context="hs_code">
              <label class="form-label">HS Code</label>
              <input type="text" class="form-control text-uppercase" id="estimateHsCode" placeholder="e.g. 940320">
            </div>
            <div class="col-12 estimate-context d-none" data-context="product">
              <label class="form-label">Product</label>
              <input type="text" class="form-control" id="estimateProductSearch" placeholder="Search product by name or HS code..." autocomplete="off">
              <input type="hidden" id="estimateProductId">
              <small class="text-muted">Uses stored product pricing unless you override the customs value below.</small>
            </div>
            <div class="col-12 estimate-context d-none" data-context="order">
              <label class="form-label">Order ID</label>
              <input type="number" min="1" class="form-control" id="estimateOrderId" placeholder="Order ID">
            </div>
            <div class="col-12 estimate-context d-none" data-context="container">
              <label class="form-label">Container ID</label>
              <input type="number" min="1" class="form-control" id="estimateContainerId" placeholder="Container ID">
            </div>
            <div class="col-12">
              <label class="form-label">Declared / Customs Value Override</label>
              <input type="number" min="0" step="0.01" class="form-control" id="estimateDeclaredValue" placeholder="Optional. Used directly when provided.">
              <small class="text-muted">Leave blank to use stored item pricing. Order and container estimates calculate per line automatically.</small>
            </div>
            <div class="col-12">
              <button class="btn btn-primary" type="submit">Run Estimate</button>
            </div>
          </div>
        </form>

        <div class="alert alert-light border mt-4 mb-3" id="estimateSummary">
          Choose a context and run an estimate.
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Reference</th>
                <th>HS Code</th>
                <th>Basis</th>
                <th>Rate %</th>
                <th>Estimated Tax</th>
              </tr>
            </thead>
            <tbody id="estimateResultsBody">
              <tr><td colspan="5" class="text-muted text-center py-4">No estimate run yet.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="taxRateModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="taxRateModalTitle">Add Tax Rate</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="taxRateForm">
          <input type="hidden" id="taxRateId">
          <div class="mb-3">
            <label class="form-label">HS Code *</label>
            <input type="text" class="form-control text-uppercase" id="taxRateHsCode" required>
          </div>
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label">Country *</label>
              <input type="text" class="form-control text-uppercase" id="taxRateCountryCode" value="LB" maxlength="10" required>
            </div>
            <div class="col-6">
              <label class="form-label">Rate % *</label>
              <input type="number" min="0" step="0.0001" class="form-control" id="taxRatePercent" required>
            </div>
          </div>
          <div class="mt-3">
            <label class="form-label">Effective From</label>
            <input type="date" class="form-control" id="taxRateEffectiveFrom">
          </div>
          <div class="mt-3">
            <label class="form-label">Notes</label>
            <textarea class="form-control" id="taxRateNotes" rows="3"></textarea>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" type="button" onclick="saveTaxRate()">Save</button>
      </div>
    </div>
  </div>
</div>

<?php $pageScripts = ['frontend/js/autocomplete.js', 'frontend/js/hs_code_tax.js'];
require 'includes/footer.php'; ?>
