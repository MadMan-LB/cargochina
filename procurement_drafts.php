<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin']);
$currentPage = 'procurement_drafts';
$pageTitle = 'Draft an Order';
require 'includes/layout.php';
?>
<h1 class="mb-3">Draft an Order</h1>
<p class="text-muted mb-4">Build one customer order across multiple suppliers, save it directly as a real order, and migrate any old procurement drafts that still need customer details or optional scheduling info.</p>

<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <div class="fw-semibold">Draft Orders</div>
      <small class="text-muted">These are real orders saved with the draft-order workflow.</small>
    </div>
    <button class="btn btn-primary btn-sm" type="button" onclick="openDraftOrderBuilder()">+ Draft an Order</button>
  </div>
  <div class="card-body">
    <div id="draftOrdersTable" class="table-responsive">
      <table class="table table-hover table-striped table-sm align-middle">
        <thead>
          <tr>
            <th>ID</th>
            <th>Customer</th>
            <th>Suppliers</th>
            <th>Expected Ready</th>
            <th>Status</th>
            <th>Items</th>
            <th>Totals</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <div class="fw-semibold">Legacy Procurement Drafts</div>
      <small class="text-muted">Old rows stay here until they are migrated into real draft orders.</small>
    </div>
  </div>
  <div class="card-body">
    <div id="legacyDraftsTable" class="table-responsive">
      <table class="table table-hover table-sm align-middle">
        <thead>
          <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Supplier</th>
            <th>Status</th>
            <th>Items</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="draftOrderModal" tabindex="-1">
  <div class="modal-dialog modal-fullscreen">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-1" id="draftOrderModalTitle">Draft an Order</h5>
          <small class="text-muted" id="draftOrderModalSubtitle">One customer, multiple supplier sections, live totals.</small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="draftOrderForm">
          <input type="hidden" id="draftOrderId">
          <input type="hidden" id="draftOrderEditable" value="1">
          <div class="card mb-4">
            <div class="card-body">
              <div class="row g-3">
                <div class="col-12 col-lg-4">
                  <label class="form-label">Customer *</label>
                  <input type="text" class="form-control" id="draftOrderCustomer" placeholder="Type to search customer..." autocomplete="off">
                </div>
                <div class="col-12 col-md-3 col-lg-2">
                  <label class="form-label">Expected Ready</label>
                  <input type="date" class="form-control" id="draftOrderExpectedDate">
                  <div class="form-text">Optional. You will be asked to confirm before saving without it.</div>
                </div>
                <div class="col-12 col-md-3 col-lg-2">
                  <label class="form-label">Currency *</label>
                  <select class="form-select" id="draftOrderCurrency">
                    <option value="USD">USD</option>
                    <option value="RMB">RMB</option>
                  </select>
                </div>
                <div class="col-12 col-lg-4">
                  <label class="form-label">High Alert Notes</label>
                  <textarea class="form-control" id="draftOrderHighAlertNotes" rows="2" placeholder="Special handling, urgent notes, fragile warnings..."></textarea>
                </div>
              </div>
              <div class="alert alert-light border mt-3 mb-0" id="draftOrderShippingHint">
                Select a customer to load the default shipping code.
              </div>
            </div>
          </div>

          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
              <div class="fw-semibold">Supplier Sections</div>
              <small class="text-muted">Each section is one supplier. Add as many suppliers as this customer order needs.</small>
            </div>
            <button type="button" class="btn btn-outline-primary btn-sm" id="draftOrderAddSectionBtn" onclick="addDraftOrderSection()">+ Add Supplier Section</button>
          </div>

          <div id="draftOrderSections" class="d-flex flex-column gap-4"></div>

          <div class="card mt-4">
            <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3">
              <div>
                <div class="fw-semibold">Order Totals</div>
                <small class="text-muted">Auto-calculated across all supplier sections.</small>
              </div>
              <div class="d-flex gap-4 flex-wrap">
                <div><strong id="draftOrderTotalAmount">0.00</strong> <span class="text-muted" id="draftOrderTotalCurrency">USD</span></div>
                <div><strong id="draftOrderTotalCbm">0.000000</strong> <span class="text-muted">CBM</span></div>
                <div><strong id="draftOrderTotalWeight">0.0000</strong> <span class="text-muted">kg</span></div>
              </div>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary" id="draftOrderSaveBtn" onclick="saveDraftOrder()">Save Draft Order</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="legacyMigrationModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Migrate Legacy Procurement Draft</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="legacyMigrationId">
        <div class="mb-3">
          <label class="form-label">Customer *</label>
          <input type="text" class="form-control" id="legacyMigrationCustomer" placeholder="Type to search customer..." autocomplete="off">
        </div>
        <div class="mb-3">
          <label class="form-label">Expected Ready Date</label>
          <input type="date" class="form-control" id="legacyMigrationExpectedDate">
          <div class="form-text">Optional. You will be asked to confirm before migrating without it.</div>
        </div>
        <div class="mb-3">
          <label class="form-label">Currency</label>
          <select class="form-select" id="legacyMigrationCurrency">
            <option value="USD">USD</option>
            <option value="RMB">RMB</option>
          </select>
        </div>
        <p class="text-muted small mb-0">Migration creates a real draft order, copies the legacy items, links the old row to the new order, and preserves the old draft name/status in audit metadata.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="legacyMigrationSubmitBtn" onclick="submitLegacyMigration()">Migrate</button>
      </div>
    </div>
  </div>
</div>

<?php
$pageScripts = [
  'frontend/js/autocomplete.js?v=' . @filemtime(__DIR__ . '/frontend/js/autocomplete.js'),
  'frontend/js/photo_uploader.js?v=' . @filemtime(__DIR__ . '/frontend/js/photo_uploader.js'),
];
$pageScript = 'frontend/js/procurement_drafts.js?v=' . @filemtime(__DIR__ . '/frontend/js/procurement_drafts.js');
require 'includes/footer.php';
?>
