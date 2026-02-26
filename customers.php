<?php
$currentPage = 'customers';
$pageTitle = 'Customers';
require 'includes/layout.php';
?>
<h1 class="mb-4">Customers</h1>
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>Customer List</span>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#customerModal" onclick="openCustomerForm()">+ Add Customer</button>
  </div>
  <div class="card-body">
    <div id="customersTable" class="table-responsive">
      <table class="table table-hover">
        <thead>
          <tr>
            <th>Code</th>
            <th>Name</th>
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
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="customerModalTitle">Add Customer</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="customerForm">
          <input type="hidden" id="customerId">
          <div class="mb-2"><label class="form-label">Code *</label><input type="text" class="form-control" id="customerCode" required></div>
          <div class="mb-2"><label class="form-label">Name *</label><input type="text" class="form-control" id="customerName" required></div>
          <div class="mb-2"><label class="form-label">Payment Terms</label><input type="text" class="form-control" id="customerPaymentTerms"></div>
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
<?php $pageScript = 'frontend/js/customers.js';
require 'includes/footer.php'; ?>