<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin']);
$currentPage = 'financials';
$pageTitle = 'Financials';
require 'includes/layout.php';
?>
<h1 class="mb-4">Financials</h1>
<p class="text-muted mb-4">Profit overview, customer balances, and supplier payables.</p>

<ul class="nav nav-tabs mb-4" id="financialsTabs" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active" id="profit-tab" data-bs-toggle="tab" data-bs-target="#profit-pane" type="button">Profit</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="balances-tab" data-bs-toggle="tab" data-bs-target="#balances-pane" type="button">Balances</button>
  </li>
</ul>

<div class="tab-content" id="financialsTabContent">
  <div class="tab-pane fade show active" id="profit-pane">
    <div class="card mb-4">
      <div class="card-header">Profit by Order</div>
      <div class="card-body">
        <div class="row mb-3 g-2">
          <div class="col-md-2"><label class="form-label small">Date from</label><input type="date" class="form-control form-control-sm" id="profitDateFrom"></div>
          <div class="col-md-2"><label class="form-label small">Date to</label><input type="date" class="form-control form-control-sm" id="profitDateTo"></div>
          <div class="col-md-3">
            <label class="form-label small">Customer</label>
            <input type="text" class="form-control form-control-sm" id="profitCustomerSearch" placeholder="Type to search customer..." autocomplete="off">
            <input type="hidden" id="profitCustomerId">
          </div>
          <div class="col-md-3">
            <label class="form-label small">Supplier</label>
            <input type="text" class="form-control form-control-sm" id="profitSupplierSearch" placeholder="Type to search supplier..." autocomplete="off">
            <input type="hidden" id="profitSupplierId">
          </div>
          <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary btn-sm w-100" onclick="loadProfit()">Apply</button></div>
        </div>
        <div id="profitSummary" class="alert alert-light border mb-3"></div>
        <div class="table-responsive">
          <table class="table table-hover table-sm">
            <thead>
              <tr>
                <th>Order</th>
                <th>Customer</th>
                <th>Supplier</th>
                <th>Status</th>
                <th>Sell</th>
                <th>Buy</th>
                <th>Commission</th>
                <th>Margin</th>
              </tr>
            </thead>
            <tbody id="profitTableBody"></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <div class="tab-pane fade" id="balances-pane">
    <div class="card mb-4">
      <div class="card-header">Balances Filters</div>
      <div class="card-body">
        <div class="row g-2">
          <div class="col-md-4">
            <label class="form-label small">Customer</label>
            <input type="text" class="form-control form-control-sm" id="balanceCustomerSearch" placeholder="Type to search customer..." autocomplete="off">
            <input type="hidden" id="balanceCustomerId">
          </div>
          <div class="col-md-4">
            <label class="form-label small">Supplier</label>
            <input type="text" class="form-control form-control-sm" id="balanceSupplierSearch" placeholder="Type to search supplier..." autocomplete="off">
            <input type="hidden" id="balanceSupplierId">
          </div>
          <div class="col-md-2 d-flex align-items-end">
            <button class="btn btn-primary btn-sm w-100" type="button" onclick="loadBalances()">Apply</button>
          </div>
          <div class="col-md-2 d-flex align-items-end">
            <button class="btn btn-outline-secondary btn-sm w-100" type="button" onclick="clearBalanceFilters()">Clear</button>
          </div>
        </div>
      </div>
    </div>
    <div class="row">
      <div class="col-lg-6">
        <div class="card mb-4">
          <div class="card-header">Customer Balances (Receivables)</div>
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-hover table-sm">
                <thead>
                  <tr>
                    <th>Customer</th>
                    <th>Deposits</th>
                    <th>Receivable</th>
                    <th>Balance</th>
                  </tr>
                </thead>
                <tbody id="customerBalancesBody"></tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card mb-4">
          <div class="card-header">Supplier Payables</div>
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-hover table-sm">
                <thead>
                  <tr>
                    <th>Supplier</th>
                    <th>Invoiced</th>
                    <th>Paid</th>
                    <th>Payable</th>
                  </tr>
                </thead>
                <tbody id="supplierPayablesBody"></tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php $pageScripts = ['frontend/js/autocomplete.js'];
$pageScript = 'frontend/js/financials.js';
require 'includes/footer.php'; ?>
