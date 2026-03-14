<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin']);
$currentPage = 'procurement_drafts';
$pageTitle = 'Procurement Drafts';
require 'includes/layout.php';
?>
<h1 class="mb-4">Procurement Drafts</h1>
<p class="text-muted mb-4">Draft order lists for suppliers before converting to formal orders.</p>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>Drafts</span>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#draftModal" onclick="openDraftForm()">+ New Draft</button>
  </div>
  <div class="card-body">
    <div id="draftsTable" class="table-responsive">
      <table class="table table-hover table-sm">
        <thead>
          <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Supplier</th>
            <th>Status</th>
            <th>Items</th>
            <th>Created</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>

<!-- Convert to Order Modal -->
<div class="modal fade" id="convertModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Convert Draft to Order</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="convertDraftId">
        <div class="mb-3">
          <label class="form-label">Customer *</label>
          <input type="text" class="form-control" id="convertCustomer" placeholder="Type customer name...">
        </div>
        <div class="mb-3">
          <label class="form-label">Expected Ready Date *</label>
          <input type="date" class="form-control" id="convertExpectedDate" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Currency</label>
          <select class="form-select" id="convertCurrency">
            <option value="USD">USD</option>
            <option value="RMB">RMB</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="convertSubmitBtn" onclick="doConvertDraft()">Convert to Order</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="draftModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="draftModalTitle">New Procurement Draft</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="draftForm">
          <input type="hidden" id="draftId">
          <div class="mb-3">
            <label class="form-label">Name *</label>
            <input type="text" class="form-control" id="draftName" required placeholder="e.g. March 2025 - Supplier A">
          </div>
          <div class="mb-3">
            <label class="form-label">Supplier</label>
            <input type="text" class="form-control" id="draftSupplierSearch" placeholder="Type supplier name..." autocomplete="off">
            <input type="hidden" id="draftSupplierId">
          </div>
          <div class="mb-3">
            <label class="form-label">Items</label>
            <div id="draftItemsContainer"></div>
            <button type="button" class="btn btn-outline-secondary btn-sm mt-2" onclick="addDraftItem()">+ Add Item</button>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" onclick="saveDraft()">Save</button>
      </div>
    </div>
  </div>
</div>

<?php $pageScripts = ['frontend/js/autocomplete.js'];
$pageScript = 'frontend/js/procurement_drafts.js';
require 'includes/footer.php'; ?>
