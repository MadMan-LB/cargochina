<?php
$currentPage = 'suppliers';
$pageTitle = 'Suppliers';
require 'includes/layout.php';
?>
<link rel="stylesheet" href="frontend/css/style.css">
<div class="col-12">
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
<?php $pageScript = 'frontend/js/suppliers.js';
require 'includes/footer.php'; ?>