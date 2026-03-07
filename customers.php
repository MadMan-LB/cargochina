<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin']);
$currentPage = 'customers';
$pageTitle = 'Customers';
require 'includes/layout.php';
?>
<h1 class="mb-4">Customers</h1>
<div class="card">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
    <span class="fw-semibold">Customer List</span>
    <div class="d-flex gap-2 align-items-center">
      <input type="text" class="form-control form-control-sm" id="customerSearch" placeholder="Search by name, code, or phone..." style="min-width:220px">
      <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#customerModal" onclick="openCustomerForm()">+ Add Customer</button>
    </div>
  </div>
  <div class="card-body py-3">
    <div id="customersTable" class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Code</th>
            <th>Name</th>
            <th>Phone</th>
            <th>Address</th>
            <th>Payment Terms</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="customerModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="customerModalTitle">Add Customer</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="customerForm">
          <input type="hidden" id="customerId">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Code *</label>
              <input type="text" class="form-control" id="customerCode" required placeholder="e.g. CUST001">
            </div>
            <div class="col-md-6">
              <label class="form-label">Name *</label>
              <input type="text" class="form-control" id="customerName" required placeholder="Full name">
            </div>
            <div class="col-md-6">
              <label class="form-label">Phone</label>
              <input type="text" class="form-control" id="customerPhone" placeholder="Phone number">
            </div>
            <div class="col-md-6">
              <label class="form-label">Payment Terms</label>
              <input type="text" class="form-control" id="customerPaymentTerms" placeholder="e.g. 30 days, T/T">
            </div>
            <div class="col-12">
              <label class="form-label">Payment Links & Descriptions</label>
              <small class="text-muted d-block mb-1">e.g. weeecha, xxx xx xxxx xx — add name and value for each payment method</small>
              <div id="customerPaymentLinksContainer"></div>
              <button type="button" class="btn btn-outline-secondary btn-sm mt-1" onclick="addCustomerPaymentLink()">+ Add payment link</button>
            </div>
            <div class="col-12">
              <label class="form-label">Address</label>
              <textarea class="form-control" id="customerAddress" rows="2" placeholder="Full address"></textarea>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" onclick="saveCustomer()">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- Deposit Modal -->
<div class="modal fade" id="depositModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Record Deposit — <span id="depCustomerName"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="depCustomerId">
        <div class="row mb-3">
          <div class="col-6"><label class="form-label">Amount *</label><input type="number" step="0.01" class="form-control" id="depAmount" required></div>
          <div class="col-6"><label class="form-label">Currency *</label><select class="form-select" id="depCurrency">
              <option value="USD">USD</option>
              <option value="RMB">RMB</option>
            </select></div>
        </div>
        <div class="row mb-3">
          <div class="col-6"><label class="form-label">Payment Method</label><input type="text" class="form-control" id="depMethod" placeholder="Bank, Cash, etc."></div>
          <div class="col-6"><label class="form-label">Reference No</label><input type="text" class="form-control" id="depReference" placeholder="Receipt/TT number"></div>
        </div>
        <div class="mb-2"><label class="form-label">Notes</label><textarea class="form-control" id="depNotes" rows="2"></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="depSubmitBtn" onclick="submitDeposit()">Record Deposit</button>
      </div>
    </div>
  </div>
</div>

<!-- Balance Modal -->
<div class="modal fade" id="balanceModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Balance — <span id="balCustomerName"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="balSummary" class="mb-3"></div>
        <div class="table-responsive">
          <table class="table table-sm">
            <thead>
              <tr>
                <th>Date</th>
                <th>Amount</th>
                <th>Currency</th>
                <th>Method</th>
                <th>Reference</th>
                <th>Notes</th>
              </tr>
            </thead>
            <tbody id="balHistoryBody"></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Orders & Shipments Modal -->
<div class="modal fade" id="ordersModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Orders & Shipments — <span id="ordersCustomerName"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="text" class="form-control form-control-sm mb-3" id="ordersFilter" placeholder="Filter by status or ID..." style="max-width:280px">
        <div class="table-responsive">
          <table class="table table-sm table-hover">
            <thead>
              <tr>
                <th>ID</th>
                <th>Supplier</th>
                <th>Expected Ready</th>
                <th>Status</th>
                <th>CBM</th>
                <th>Weight</th>
              </tr>
            </thead>
            <tbody id="ordersModalBody"></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php $pageScript = '/cargochina/frontend/js/customers.js';
require 'includes/footer.php'; ?>