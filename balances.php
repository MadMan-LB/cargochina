<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'SuperAdmin']);
$currentPage = 'balances';
$pageTitle = 'Balances';
require 'includes/layout.php';
?>

<div class="card page-hero-card balance-hero-card mb-4">
  <div class="card-body">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
      <div>
        <h1 class="mb-2"><?= htmlspecialchars(clmsT('Balances')) ?></h1>
        <p class="text-muted mb-0"><?= htmlspecialchars(clmsT('View customer and supplier balances, record payments, and check daily history in one simple workspace.')) ?></p>
      </div>
      <button type="button" class="btn btn-primary" onclick="openBalanceTransactionModal()">
        <?= htmlspecialchars(clmsT('Record Transaction')) ?>
      </button>
    </div>
  </div>
</div>

<div class="metric-card-grid balance-summary-grid mb-4">
  <div class="metric-card">
    <div class="eyebrow"><?= htmlspecialchars(clmsT('Total Customer Balances')) ?></div>
    <div class="value balance-summary-value" id="summaryCustomerBalances">USD 0.00 | RMB 0.00</div>
    <div class="detail" id="summaryCustomerDetail"><?= htmlspecialchars(clmsT('Amounts customers still owe, grouped by currency.')) ?></div>
  </div>
  <div class="metric-card">
    <div class="eyebrow"><?= htmlspecialchars(clmsT('Total Supplier Balances')) ?></div>
    <div class="value balance-summary-value" id="summarySupplierBalances">USD 0.00 | RMB 0.00</div>
    <div class="detail" id="summarySupplierDetail"><?= htmlspecialchars(clmsT('Amounts still payable to suppliers, grouped by currency.')) ?></div>
  </div>
  <div class="metric-card">
    <div class="eyebrow"><?= htmlspecialchars(clmsT('Payments Received Today')) ?></div>
    <div class="value balance-summary-value" id="summaryReceivedToday">USD 0.00 | RMB 0.00</div>
    <div class="detail"><?= htmlspecialchars(clmsT('Customer payments recorded today.')) ?></div>
  </div>
  <div class="metric-card">
    <div class="eyebrow"><?= htmlspecialchars(clmsT('Payments Sent Today')) ?></div>
    <div class="value balance-summary-value" id="summarySentToday">USD 0.00 | RMB 0.00</div>
    <div class="detail"><?= htmlspecialchars(clmsT('Outgoing payments recorded today.')) ?></div>
  </div>
</div>

<div class="filter-toolbar-card soft mb-4">
  <div class="filter-toolbar-head">
    <div>
      <h6><?= htmlspecialchars(clmsT('Filter Balances')) ?></h6>
      <div class="filter-toolbar-subtext"><?= htmlspecialchars(clmsT('Search by name, phone, code, account number, or reference number.')) ?></div>
    </div>
    <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" onclick="clearBalancePageFilters()"><?= htmlspecialchars(clmsT('Clear')) ?></button>
  </div>
  <div class="row g-3">
    <div class="col-lg-3 col-md-6">
      <label class="form-label small" for="balanceSearch"><?= htmlspecialchars(clmsT('Search')) ?></label>
      <input type="search" class="form-control form-control-sm" id="balanceSearch" placeholder="<?= htmlspecialchars(clmsT('Search by name, phone, code, account number, or reference number...')) ?>">
    </div>
    <div class="col-lg-2 col-md-6">
      <label class="form-label small" for="balanceDateFrom"><?= htmlspecialchars(clmsT('Date from')) ?></label>
      <input type="date" class="form-control form-control-sm" id="balanceDateFrom">
    </div>
    <div class="col-lg-2 col-md-6">
      <label class="form-label small" for="balanceDateTo"><?= htmlspecialchars(clmsT('Date to')) ?></label>
      <input type="date" class="form-control form-control-sm" id="balanceDateTo">
    </div>
    <div class="col-lg-2 col-md-6">
      <label class="form-label small" for="balancePartyType"><?= htmlspecialchars(clmsT('Type')) ?></label>
      <select class="form-select form-select-sm" id="balancePartyType">
        <option value=""><?= htmlspecialchars(clmsT('All types')) ?></option>
        <option value="customer"><?= htmlspecialchars(clmsT('Customer')) ?></option>
        <option value="supplier"><?= htmlspecialchars(clmsT('Supplier')) ?></option>
      </select>
    </div>
    <div class="col-lg-1 col-md-4">
      <label class="form-label small" for="balanceCurrency"><?= htmlspecialchars(clmsT('Currency')) ?></label>
      <select class="form-select form-select-sm" id="balanceCurrency">
        <option value=""><?= htmlspecialchars(clmsT('All')) ?></option>
        <option value="USD">USD</option>
        <option value="RMB">RMB</option>
      </select>
    </div>
    <div class="col-lg-2 col-md-4">
      <label class="form-label small" for="balancePaymentMethod"><?= htmlspecialchars(clmsT('Payment Method')) ?></label>
      <select class="form-select form-select-sm" id="balancePaymentMethod">
        <option value=""><?= htmlspecialchars(clmsT('All methods')) ?></option>
        <option value="Cash"><?= htmlspecialchars(clmsT('Cash')) ?></option>
        <option value="WeChat">WeChat</option>
        <option value="Alipay">Alipay</option>
        <option value="Bank Transfer"><?= htmlspecialchars(clmsT('Bank Transfer')) ?></option>
        <option value="Other"><?= htmlspecialchars(clmsT('Other')) ?></option>
      </select>
    </div>
    <div class="col-lg-2 col-md-4">
      <label class="form-label small" for="balanceStatus"><?= htmlspecialchars(clmsT('Balance Status')) ?></label>
      <select class="form-select form-select-sm" id="balanceStatus">
        <option value=""><?= htmlspecialchars(clmsT('All statuses')) ?></option>
        <option value="due"><?= htmlspecialchars(clmsT('Due')) ?></option>
        <option value="credit"><?= htmlspecialchars(clmsT('Credit')) ?></option>
        <option value="settled"><?= htmlspecialchars(clmsT('Settled')) ?></option>
      </select>
    </div>
  </div>
  <div class="filter-summary-row">
    <div class="summary-text" id="balanceFilterSummary"><?= htmlspecialchars(clmsT('Showing all current balances.')) ?></div>
    <div class="d-flex flex-wrap gap-2">
      <button type="button" class="btn btn-outline-secondary btn-sm" onclick="exportActiveBalanceView()"><?= htmlspecialchars(clmsT('Export')) ?></button>
      <button type="button" class="btn btn-primary btn-sm" onclick="loadBalancePageData()"><?= htmlspecialchars(clmsT('Apply Filters')) ?></button>
    </div>
  </div>
</div>

<ul class="nav nav-pills finance-tab-nav mb-4" id="balanceTabs" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active" id="customer-balances-tab" data-bs-toggle="tab" data-bs-target="#customer-balances-pane" type="button" role="tab"><?= htmlspecialchars(clmsT('Customer Balances')) ?></button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="supplier-balances-tab" data-bs-toggle="tab" data-bs-target="#supplier-balances-pane" type="button" role="tab"><?= htmlspecialchars(clmsT('Supplier Balances')) ?></button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="balance-transactions-tab" data-bs-toggle="tab" data-bs-target="#balance-transactions-pane" type="button" role="tab"><?= htmlspecialchars(clmsT('Transactions History')) ?></button>
  </li>
</ul>

<div class="tab-content" id="balanceTabContent">
  <div class="tab-pane fade show active" id="customer-balances-pane" role="tabpanel">
    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><?= htmlspecialchars(clmsT('Customer Balances')) ?></span>
        <small class="text-muted" id="customerBalancesSummary"><?= htmlspecialchars(clmsT('Waiting for data...')) ?></small>
      </div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-hover align-middle">
            <thead>
              <tr>
                <th><?= htmlspecialchars(clmsT('Name')) ?></th>
                <th><?= htmlspecialchars(clmsT('Phone')) ?></th>
                <th><?= htmlspecialchars(clmsT('Currency')) ?></th>
                <th><?= htmlspecialchars(clmsT('Current Balance')) ?></th>
                <th><?= htmlspecialchars(clmsT('Total Paid')) ?></th>
                <th><?= htmlspecialchars(clmsT('Total Due')) ?></th>
                <th><?= htmlspecialchars(clmsT('Last Payment Date')) ?></th>
                <th><?= htmlspecialchars(clmsT('Status')) ?></th>
                <th><?= htmlspecialchars(clmsT('Actions')) ?></th>
              </tr>
            </thead>
            <tbody id="customerBalancesBody"></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="tab-pane fade" id="supplier-balances-pane" role="tabpanel">
    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><?= htmlspecialchars(clmsT('Supplier Balances')) ?></span>
        <small class="text-muted" id="supplierBalancesSummary"><?= htmlspecialchars(clmsT('Waiting for data...')) ?></small>
      </div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-hover align-middle">
            <thead>
              <tr>
                <th><?= htmlspecialchars(clmsT('Name')) ?></th>
                <th><?= htmlspecialchars(clmsT('Phone')) ?></th>
                <th><?= htmlspecialchars(clmsT('Currency')) ?></th>
                <th><?= htmlspecialchars(clmsT('Current Balance')) ?></th>
                <th><?= htmlspecialchars(clmsT('Total Paid')) ?></th>
                <th><?= htmlspecialchars(clmsT('Total Due')) ?></th>
                <th><?= htmlspecialchars(clmsT('Last Payment Date')) ?></th>
                <th><?= htmlspecialchars(clmsT('Status')) ?></th>
                <th><?= htmlspecialchars(clmsT('Actions')) ?></th>
              </tr>
            </thead>
            <tbody id="supplierBalancesBody"></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="tab-pane fade" id="balance-transactions-pane" role="tabpanel">
    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><?= htmlspecialchars(clmsT('Transactions History')) ?></span>
        <small class="text-muted" id="transactionsSummary"><?= htmlspecialchars(clmsT('Waiting for data...')) ?></small>
      </div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-hover align-middle table-sm">
            <thead>
              <tr>
                <th><?= htmlspecialchars(clmsT('Date')) ?></th>
                <th><?= htmlspecialchars(clmsT('Type')) ?></th>
                <th><?= htmlspecialchars(clmsT('Name')) ?></th>
                <th><?= htmlspecialchars(clmsT('Transaction Type')) ?></th>
                <th><?= htmlspecialchars(clmsT('Amount')) ?></th>
                <th><?= htmlspecialchars(clmsT('Payment Method')) ?></th>
                <th><?= htmlspecialchars(clmsT('Account Number')) ?></th>
                <th><?= htmlspecialchars(clmsT('Linked Order')) ?></th>
                <th><?= htmlspecialchars(clmsT('Reference Number')) ?></th>
                <th><?= htmlspecialchars(clmsT('Recorded By')) ?></th>
                <th><?= htmlspecialchars(clmsT('Notes')) ?></th>
              </tr>
            </thead>
            <tbody id="transactionsBody"></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="balanceTransactionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-fullscreen-md-down">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="balanceTxnModalTitle"><?= htmlspecialchars(clmsT('Record Transaction')) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(clmsT('Close')) ?>"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-danger d-none" id="balanceTxnValidationSummary"><?= htmlspecialchars(clmsT('Please complete the highlighted fields before saving.')) ?></div>
        <div class="row g-3">
          <div class="col-12 d-none" id="balanceLinkedOrderWrap">
            <div class="linked-order-panel">
              <div class="small text-muted"><?= htmlspecialchars(clmsT('Linked Order')) ?></div>
              <div class="fw-semibold" id="balanceLinkedOrderLabel" data-no-translate></div>
            </div>
            <input type="hidden" id="balanceTxnOrderId">
            <input type="hidden" id="balanceTxnOrderReference">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="balanceTxnPartyType"><?= htmlspecialchars(clmsT('Party Type')) ?></label>
            <select class="form-select" id="balanceTxnPartyType">
              <option value="customer"><?= htmlspecialchars(clmsT('Customer')) ?></option>
              <option value="supplier"><?= htmlspecialchars(clmsT('Supplier')) ?></option>
            </select>
          </div>
          <div class="col-md-8" id="balanceTxnCustomerWrap">
            <label class="form-label" for="balanceTxnCustomerSearch"><?= htmlspecialchars(clmsT('Select Customer')) ?></label>
            <input type="text" class="form-control" id="balanceTxnCustomerSearch" placeholder="<?= htmlspecialchars(clmsT('Type to search customer...')) ?>" autocomplete="off">
            <input type="hidden" id="balanceTxnCustomerId">
          </div>
          <div class="col-md-8 d-none" id="balanceTxnSupplierWrap">
            <label class="form-label" for="balanceTxnSupplierSearch"><?= htmlspecialchars(clmsT('Select Supplier')) ?></label>
            <input type="text" class="form-control" id="balanceTxnSupplierSearch" placeholder="<?= htmlspecialchars(clmsT('Type to search supplier...')) ?>" autocomplete="off">
            <input type="hidden" id="balanceTxnSupplierId">
          </div>
          <div class="col-md-6">
            <label class="form-label" for="balanceTxnType"><?= htmlspecialchars(clmsT('Transaction Type')) ?></label>
            <select class="form-select" id="balanceTxnType">
              <option value="deposit"><?= htmlspecialchars(clmsT('Deposit')) ?></option>
              <option value="payment_received"><?= htmlspecialchars(clmsT('Payment Received')) ?></option>
              <option value="payment_sent"><?= htmlspecialchars(clmsT('Payment Sent')) ?></option>
              <option value="adjustment"><?= htmlspecialchars(clmsT('Adjustment')) ?></option>
              <option value="refund"><?= htmlspecialchars(clmsT('Refund')) ?></option>
              <option value="other"><?= htmlspecialchars(clmsT('Other')) ?></option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="balanceTxnDirection"><?= htmlspecialchars(clmsT('Balance Direction')) ?></label>
            <select class="form-select" id="balanceTxnDirection">
              <option value="reduce_balance"><?= htmlspecialchars(clmsT('Reduce balance')) ?></option>
              <option value="increase_balance"><?= htmlspecialchars(clmsT('Increase balance')) ?></option>
            </select>
            <div class="form-text" id="balanceTxnDirectionHelp"><?= htmlspecialchars(clmsT('Payments usually reduce the selected balance.')) ?></div>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="balanceTxnAmount"><?= htmlspecialchars(clmsT('Amount')) ?></label>
            <input type="number" step="0.01" min="0" class="form-control" id="balanceTxnAmount">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="balanceTxnCurrency"><?= htmlspecialchars(clmsT('Currency')) ?></label>
            <select class="form-select" id="balanceTxnCurrency">
              <option value="RMB">RMB</option>
              <option value="USD">USD</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="balanceTxnDate"><?= htmlspecialchars(clmsT('Date')) ?></label>
            <input type="date" class="form-control" id="balanceTxnDate">
          </div>
          <div class="col-md-6">
            <label class="form-label" for="balanceTxnMethod"><?= htmlspecialchars(clmsT('Payment Method')) ?></label>
            <select class="form-select" id="balanceTxnMethod">
              <option value=""><?= htmlspecialchars(clmsT('Choose payment method...')) ?></option>
              <option value="Cash"><?= htmlspecialchars(clmsT('Cash')) ?></option>
              <option value="WeChat">WeChat</option>
              <option value="Alipay">Alipay</option>
              <option value="Bank Transfer"><?= htmlspecialchars(clmsT('Bank Transfer')) ?></option>
              <option value="Other"><?= htmlspecialchars(clmsT('Other')) ?></option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="balanceTxnAccountOption"><?= htmlspecialchars(clmsT('Saved Account')) ?></label>
            <select class="form-select" id="balanceTxnAccountOption">
              <option value=""><?= htmlspecialchars(clmsT('Choose saved account...')) ?></option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="balanceTxnAccountDetail"><?= htmlspecialchars(clmsT('Account Number')) ?></label>
            <div class="balance-account-picker">
              <input type="text" class="form-control" id="balanceTxnAccountDetail" autocomplete="off" placeholder="<?= htmlspecialchars(clmsT('Account / number / URL / account detail')) ?>">
              <div class="balance-account-suggestions d-none" id="balanceTxnAccountSuggestions" role="listbox"></div>
            </div>
            <div class="form-text" id="balanceTxnAccountHelp"><?= htmlspecialchars(clmsT('Choose a saved account or type a new account number.')) ?></div>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="balanceTxnReference"><?= htmlspecialchars(clmsT('Reference Number')) ?></label>
            <input type="text" class="form-control" id="balanceTxnReference" placeholder="<?= htmlspecialchars(clmsT('Receipt, transfer, or reference number')) ?>">
          </div>
          <div class="col-12">
            <label class="form-label" for="balanceTxnNotes"><?= htmlspecialchars(clmsT('Notes')) ?></label>
            <textarea class="form-control" id="balanceTxnNotes" rows="3" placeholder="<?= htmlspecialchars(clmsT('Optional notes')) ?>"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= htmlspecialchars(clmsT('Cancel')) ?></button>
        <button type="button" class="btn btn-primary" id="balanceTxnSubmitBtn" onclick="submitBalanceTransaction()"><?= htmlspecialchars(clmsT('Record Transaction')) ?></button>
      </div>
    </div>
  </div>
</div>

<?php
$pageScripts = ['frontend/js/autocomplete.js?v=' . filemtime(__DIR__ . '/frontend/js/autocomplete.js')];
$pageScript = 'frontend/js/balances.js?v=' . filemtime(__DIR__ . '/frontend/js/balances.js');
require 'includes/footer.php';
?>
