<?php
$currentPage = 'suppliers';
$pageTitle = 'Suppliers';
require 'includes/layout.php';
?>
<h1 class="mb-4">Suppliers</h1>
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span>Supplier List</span>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#supplierModal" onclick="openSupplierForm()">+ Add Supplier</button>
  </div>
  <div class="card-body">
    <div id="suppliersTable" class="table-responsive">
      <table class="table table-hover">
        <thead>
          <tr>
            <th>Code</th>
            <th>Store ID</th>
            <th>Name</th>
            <th>Phone</th>
            <th>Factory Location</th>
            <th>Additional IDs</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="supplierModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="supplierModalTitle">Add Supplier</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="supplierForm">
          <input type="hidden" id="supplierId">
          <div class="row form-row-responsive">
            <div class="col-12 col-md-4 mb-2"><label class="form-label">Code *</label><input type="text" class="form-control" id="supplierCode" required></div>
            <div class="col-12 col-md-4 mb-2"><label class="form-label">Store ID</label><input type="text" class="form-control" id="supplierStoreId" placeholder="Official China store identifier"></div>
            <div class="col-12 col-md-4 mb-2"><label class="form-label">Name *</label><input type="text" class="form-control" id="supplierName" required></div>
          </div>
          <div class="row form-row-responsive">
            <div class="col-12 col-md-6 mb-2"><label class="form-label">Phone</label>
              <div class="input-group"><span class="input-group-text">+</span><input type="tel" class="form-control" id="supplierPhone" placeholder="e.g. +86 123 4567 8900"></div>
            </div>
            <div class="col-12 col-md-6 mb-2"><label class="form-label">Factory Location</label><input type="text" class="form-control" id="supplierFactory"></div>
          </div>
          <div class="mb-2"><label class="form-label">Notes</label><textarea class="form-control" id="supplierNotes" rows="2"></textarea></div>
          <div class="mb-2">
            <label class="form-label">Additional IDs (e.g. Tax ID, VAT)</label>
            <div id="additionalIdsContainer"></div>
            <button type="button" class="btn btn-outline-secondary btn-sm mt-1" onclick="addAdditionalIdRow()">+ Add ID</button>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="supplierSaveBtn" onclick="saveSupplier()">Save</button>
      </div>
    </div>
  </div>
</div>
<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Record Payment — <span id="paymentSupplierName"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="paymentSupplierId">
        <div class="row mb-3">
          <div class="col-6"><label class="form-label">Invoice Amount</label><input type="number" step="0.01" class="form-control" id="payInvoiceAmount" placeholder="Total invoice"></div>
          <div class="col-6"><label class="form-label">Amount Paid *</label><input type="number" step="0.01" class="form-control" id="payAmount" required></div>
        </div>
        <div class="row mb-3">
          <div class="col-6"><label class="form-label">Currency *</label><select class="form-select" id="payCurrency">
              <option value="USD">USD</option>
              <option value="RMB">RMB</option>
            </select></div>
          <div class="col-6"><label class="form-label">Linked Order</label><input type="number" class="form-control" id="payOrderId" placeholder="Order ID (optional)"></div>
        </div>
        <div class="form-check mb-3">
          <input type="checkbox" class="form-check-input" id="payMarkedFull">
          <label class="form-check-label" for="payMarkedFull">Mark as fully paid (supplier accepted discount)</label>
        </div>
        <div id="payDiscountInfo" class="alert alert-info py-2 d-none"></div>
        <div class="mb-2"><label class="form-label">Notes</label><textarea class="form-control" id="payNotes" rows="2"></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="paySubmitBtn" onclick="submitPayment()">Record Payment</button>
      </div>
    </div>
  </div>
</div>

<!-- Payment History Modal -->
<div class="modal fade" id="payHistoryModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Payments — <span id="histSupplierName"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="balanceSummary" class="mb-3"></div>
        <div class="table-responsive">
          <table class="table table-sm">
            <thead>
              <tr>
                <th>Date</th>
                <th>Invoice</th>
                <th>Paid</th>
                <th>Discount</th>
                <th>Currency</th>
                <th>Type</th>
                <th>Order</th>
                <th>Notes</th>
              </tr>
            </thead>
            <tbody id="payHistoryBody"></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php $pageScript = 'frontend/js/suppliers.js';
require 'includes/footer.php'; ?>