<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin']);
$currentPage = 'financials';
$pageTitle = 'Financials';
require 'includes/layout.php';
?>
<div class="card page-hero-card mb-4">
  <div class="card-body">
    <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3">
      <div>
        <h1 class="mb-2">Financials</h1>
        <p class="text-muted mb-0">Track sell-side performance, watch customer exposure, and keep supplier payables visible without leaving operations.</p>
      </div>
      <div class="filter-toolbar-card soft py-3 px-3 mb-0 finance-hero-note">
        <div class="eyebrow mb-1">Internal Only</div>
        <div class="small text-muted">Buy prices, commissions, receivables, and payables stay in this admin view only and do not surface in customer-facing flows.</div>
      </div>
    </div>
  </div>
</div>

<ul class="nav nav-pills finance-tab-nav mb-4" id="financialsTabs" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active" id="profit-tab" data-bs-toggle="tab" data-bs-target="#profit-pane" type="button">Profit</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="balances-tab" data-bs-toggle="tab" data-bs-target="#balances-pane" type="button">Balances</button>
  </li>
</ul>

<div class="tab-content" id="financialsTabContent">
  <div class="tab-pane fade show active" id="profit-pane">
    <div class="metric-card-grid mb-4">
      <div class="metric-card">
        <div class="eyebrow">Visible Orders</div>
        <div class="value" id="profitOrderCount">0</div>
        <div class="detail" id="profitOrderDetail">Orders in the current profit view.</div>
      </div>
      <div class="metric-card">
        <div class="eyebrow">Gross Profit</div>
        <div class="value" id="profitGrossCount">0.00</div>
        <div class="detail" id="profitGrossDetail">Sell minus buy before commission.</div>
      </div>
      <div class="metric-card">
        <div class="eyebrow">Net Profit</div>
        <div class="value" id="profitNetCount">0.00</div>
        <div class="detail" id="profitNetDetail">After commission. Expenses listed below.</div>
      </div>
      <div class="metric-card">
        <div class="eyebrow">Commission</div>
        <div class="value" id="profitCommissionCount">0.00</div>
        <div class="detail" id="profitCommissionDetail">Supplier commission impact on visible orders.</div>
      </div>
    </div>

    <div class="balanced-panels mb-4">
      <div class="filter-toolbar-card soft">
        <div class="filter-toolbar-head">
          <div>
            <h6>Filter Profit View</h6>
            <div class="filter-toolbar-subtext">Narrow the order margin table by status, date range, customer, and supplier.</div>
          </div>
          <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" onclick="clearProfitFilters()">Clear</button>
        </div>
        <div class="mb-3">
          <label class="form-label small mb-2 d-block">Statuses</label>
          <div class="filter-chip-grid" id="profitStatusList">
            <?php foreach ([
              'Draft' => 'Draft',
              'Submitted' => 'Submitted',
              'Approved' => 'Approved',
              'InTransitToWarehouse' => 'In Transit',
              'ReceivedAtWarehouse' => 'Received',
              'AwaitingCustomerConfirmation' => 'Awaiting Confirmation',
              'CustomerDeclined' => 'Customer Declined',
              'Confirmed' => 'Confirmed',
              'ReadyForConsolidation' => 'Ready for Consolidation',
              'ConsolidatedIntoShipmentDraft' => 'In Shipment Draft',
              'AssignedToContainer' => 'Assigned to Container',
              'FinalizedAndPushedToTracking' => 'Finalized',
            ] as $statusValue => $statusLabel): ?>
              <div class="form-check filter-chip">
                <input class="form-check-input profit-status-filter" type="checkbox" value="<?= htmlspecialchars($statusValue) ?>" id="profitStatus<?= htmlspecialchars($statusValue) ?>">
                <label class="form-check-label" for="profitStatus<?= htmlspecialchars($statusValue) ?>"><?= htmlspecialchars($statusLabel) ?></label>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="filter-summary-row mb-3">
          <div class="d-flex gap-2 align-items-center flex-wrap">
            <select class="form-select form-select-sm" id="profitStatusMode">
              <option value="include">Include selected</option>
              <option value="exclude">Exclude selected</option>
            </select>
            <small class="summary-text" id="profitStatusSummary">Default scope: all except Draft and Customer Declined.</small>
          </div>
        </div>
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label small">Date from</label>
            <input type="date" class="form-control form-control-sm" id="profitDateFrom">
          </div>
          <div class="col-md-3">
            <label class="form-label small">Date to</label>
            <input type="date" class="form-control form-control-sm" id="profitDateTo">
          </div>
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
        </div>
        <div class="filter-summary-row">
          <div class="summary-text" id="profitFilterSummary">Showing all non-draft orders in the profit view.</div>
          <button class="btn btn-primary btn-sm" onclick="loadProfit()">Apply Filters</button>
        </div>
      </div>

      <div class="filter-toolbar-card">
        <div class="filter-toolbar-head">
          <div>
            <h6>Reading Guide</h6>
            <div class="filter-toolbar-subtext">Keep the key business meaning visible while you filter.</div>
          </div>
        </div>
        <div class="stack-card-list">
          <div class="finance-callout">
            <strong>Gross Profit</strong>
            <div class="text-muted small">Customer sell value minus internal buy cost.</div>
          </div>
          <div class="finance-callout">
            <strong>Net Profit</strong>
            <div class="text-muted small">Gross profit after supplier commission. Expenses are listed separately by currency.</div>
          </div>
          <div class="finance-callout">
            <strong>Expense Snapshot</strong>
            <div class="text-muted small" id="profitExpenseSummary">No expense data loaded yet.</div>
          </div>
        </div>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>Profit by Order</span>
        <small class="text-muted" id="profitTableSummary">Waiting for data…</small>
      </div>
      <div class="card-body">
        <div id="profitSummary" class="finance-summary-strip mb-3"></div>
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
    <div class="metric-card-grid mb-4">
      <div class="metric-card">
        <div class="eyebrow">Visible Customers</div>
        <div class="value" id="balanceCustomerCount">0</div>
        <div class="detail" id="balanceCustomerDetail">Customers in the current receivables view.</div>
      </div>
      <div class="metric-card">
        <div class="eyebrow">Customer Credit</div>
        <div class="value" id="balanceCreditCount">0.00</div>
        <div class="detail" id="balanceCreditDetail">Prepaid balances still available to use.</div>
      </div>
      <div class="metric-card">
        <div class="eyebrow">Customer Outstanding</div>
        <div class="value" id="balanceOutstandingCount">0.00</div>
        <div class="detail" id="balanceOutstandingDetail">Amount still receivable from customers.</div>
      </div>
      <div class="metric-card">
        <div class="eyebrow">Supplier Payables</div>
        <div class="value" id="balanceSupplierPayableCount">0.00</div>
        <div class="detail" id="balanceSupplierPayableDetail">Open supplier payable total in view.</div>
      </div>
    </div>

    <div class="balanced-panels mb-4">
      <div class="filter-toolbar-card soft">
        <div class="filter-toolbar-head">
          <div>
            <h6>Filter Balances</h6>
            <div class="filter-toolbar-subtext">Search customer or supplier directly to narrow receivables and payables.</div>
          </div>
          <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" onclick="clearBalanceFilters()">Clear</button>
        </div>
        <div class="row g-3">
          <div class="col-md-5">
            <label class="form-label small">Customer</label>
            <input type="text" class="form-control form-control-sm" id="balanceCustomerSearch" placeholder="Type to search customer..." autocomplete="off">
            <input type="hidden" id="balanceCustomerId">
          </div>
          <div class="col-md-5">
            <label class="form-label small">Supplier</label>
            <input type="text" class="form-control form-control-sm" id="balanceSupplierSearch" placeholder="Type to search supplier..." autocomplete="off">
            <input type="hidden" id="balanceSupplierId">
          </div>
          <div class="col-md-2 d-flex align-items-end">
            <button class="btn btn-primary btn-sm w-100" type="button" onclick="loadBalances()">Apply Filters</button>
          </div>
        </div>
        <div class="filter-summary-row">
          <div class="summary-text" id="balancesFilterSummary">Showing customer receivables and supplier payables for the full list.</div>
        </div>
      </div>

      <div class="filter-toolbar-card">
        <div class="filter-toolbar-head">
          <div>
            <h6>Balance Reading Guide</h6>
            <div class="filter-toolbar-subtext">Separate who owes us from where we still owe suppliers.</div>
          </div>
        </div>
        <div class="stack-card-list">
          <div class="finance-callout">
            <strong>Customer Balance</strong>
            <div class="text-muted small">Positive means the customer has usable credit. Negative means we are still waiting to collect.</div>
          </div>
          <div class="finance-callout">
            <strong>Supplier Payable</strong>
            <div class="text-muted small">Positive means the supplier still needs to be paid based on recorded supplier payment data.</div>
          </div>
          <div class="finance-callout">
            <strong>Current Focus</strong>
            <div class="text-muted small" id="balancesSummaryText">No balance data loaded yet.</div>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-4">
      <div class="col-lg-6">
        <div class="card mb-4">
          <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span>Customer Balances (Receivables)</span>
            <small class="text-muted" id="customerBalancesSummary">Waiting for data…</small>
          </div>
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
          <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span>Supplier Payables</span>
            <small class="text-muted" id="supplierPayablesSummary">Waiting for data…</small>
          </div>
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

<?php $pageScripts = ['frontend/js/autocomplete.js?v=' . filemtime(__DIR__ . '/frontend/js/autocomplete.js')];
$pageScript = 'frontend/js/financials.js?v=' . filemtime(__DIR__ . '/frontend/js/financials.js');
require 'includes/footer.php'; ?>
