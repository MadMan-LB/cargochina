<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['ChinaAdmin', 'ChinaEmployee', 'FieldStaff', 'SuperAdmin']);
$currentPage = 'suppliers';
$pageTitle = 'Suppliers';
require 'includes/layout.php';
?>
<h1 class="mb-4">Suppliers</h1>
<div class="card mb-3">
  <div class="card-body py-3">
    <div class="row g-2 align-items-end flex-wrap">
      <div class="col-12 col-md-4 col-lg-3">
        <label class="form-label small mb-0">Search</label>
        <input type="text" class="form-control form-control-sm" id="supplierSearch" placeholder="Code, name, phone, store, location...">
      </div>
      <div class="col-12 col-md-4 col-lg-2">
        <label class="form-label small mb-0">Payment status</label>
        <select class="form-select form-select-sm" id="supplierPaymentFilter">
          <option value="">All</option>
          <option value="outstanding">Outstanding balance</option>
          <option value="fully_paid">Fully paid</option>
        </select>
      </div>
      <div class="col-12 col-md-4 col-lg-2">
        <label class="form-label small mb-0">Sort by</label>
        <select class="form-select form-select-sm" id="supplierSort">
          <option value="name">Name</option>
          <option value="code">Code</option>
          <option value="store_id">Store ID</option>
          <option value="phone">Phone</option>
          <option value="factory_location">Factory</option>
        </select>
      </div>
      <div class="col-12 col-md-4 col-lg-2">
        <label class="form-label small mb-0">Order</label>
        <select class="form-select form-select-sm" id="supplierOrder">
          <option value="asc">A → Z</option>
          <option value="desc">Z → A</option>
        </select>
      </div>
      <div class="col-12 col-md-4 col-lg-2">
        <button type="button" class="btn btn-primary btn-sm w-100" id="supplierApplyBtn" onclick="applySupplierFilters()">Apply</button>
      </div>
    </div>
  </div>
</div>
<div class="card" data-is-buyer="<?= $isBuyer ? '1' : '0' ?>">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span>Supplier List</span>
    <?php if ($isBuyer): ?>
      <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#importModal" onclick="openImportModal('suppliers')">Import CSV</button>
      <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#supplierModal" onclick="openSupplierForm()">+ Add Supplier</button>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <div id="suppliersTable" class="table-responsive">
      <table class="table table-hover table-striped table-sm align-middle">
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
            <div class="col-12 col-md-4 mb-2"><label class="form-label">Phone</label>
              <div class="input-group"><span class="input-group-text">+</span><input type="tel" class="form-control" id="supplierPhone" placeholder="e.g. +86 123 4567 8900"></div>
            </div>
            <div class="col-12 col-md-4 mb-2"><label class="form-label">Fax</label><input type="text" class="form-control" id="supplierFax" placeholder="Fax number (optional)"></div>
            <div class="col-12 col-md-4 mb-2"><label class="form-label">Factory Location</label><input type="text" class="form-control" id="supplierFactory"></div>
          </div>
          <div class="mb-2"><label class="form-label">Address</label><input type="text" class="form-control" id="supplierAddress" placeholder="Full address (used in order export header)"></div>
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
<!-- Import CSV Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Import Suppliers CSV</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small">Paste CSV or choose file. Columns: <code>code</code>, <code>name</code>, <code>store_id</code>, <code>phone</code>, <code>factory_location</code>, <code>notes</code>. Duplicate codes are skipped.</p>
        <input type="file" class="form-control form-control-sm mb-2" id="importCsvFile" accept=".csv,.txt" title="Choose CSV file">
        <textarea class="form-control font-monospace" id="importCsvData" rows="10" placeholder="code,name,store_id,phone,factory_location,notes&#10;S001,Acme Store,ST,+,Yiwu,notes"></textarea>
        <div id="importResult" class="alert d-none mt-2"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="importBtn" onclick="doImport()">Import</button>
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

<!-- Log Visit / Interactions Modal -->
<div class="modal fade" id="visitModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Log visit — <span id="visitSupplierName"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="visitSupplierId">
        <div class="mb-3">
          <label class="form-label">Type</label>
          <select class="form-select" id="visitType">
            <option value="visit">Visit</option>
            <option value="quote">Quote</option>
            <option value="note">Note</option>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label">Notes / content</label>
          <textarea class="form-control" id="visitContent" rows="3" placeholder="What did you observe? Products, quality, availability..."></textarea>
        </div>
        <hr>
        <h6 class="mb-2">Recent visits</h6>
        <div id="visitHistory" class="small text-muted"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="visitSubmitBtn" onclick="submitVisit()">Log visit</button>
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