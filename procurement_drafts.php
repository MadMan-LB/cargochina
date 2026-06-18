<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin']);
$currentPage = 'procurement_drafts';
$pageTitle = 'Draft an Order';
require 'includes/layout.php';
?>
<h1 class="mb-3">Draft an Order</h1>
<p class="text-muted mb-4">Build one customer order across multiple suppliers with a compact, order-style layout, photo-first item cards, and live totals.</p>

<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <div class="fw-semibold">Draft Orders</div>
      <small class="text-muted">These are real orders saved with the draft-order workflow.</small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <button class="btn btn-outline-primary btn-sm" type="button" id="draftOrderImportBtn">Import</button>
      <button class="btn btn-primary btn-sm" type="button" onclick="openDraftOrderBuilder()">+ Draft an Order</button>
    </div>
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

<input type="file" class="d-none" id="draftOrderImportFile" accept=".xlsx,.xls,.csv,.cv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel">

<div class="modal fade" id="draftOrderImportGuideModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-1">Import Draft Order</h5>
          <small class="text-muted">Excel and CSV files with English or Chinese item data are supported.</small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="draft-import-dropzone" id="draftOrderImportDropZone" role="button" tabindex="0" aria-describedby="draftOrderImportHelp">
          <button type="button" class="draft-import-plus" id="draftOrderImportPlusBtn" aria-label="Choose file">+</button>
          <button type="button" class="btn btn-primary btn-lg draft-import-main-btn" id="draftOrderImportDropBtn">
            Drop Excel or CSV file here
          </button>
          <div class="draft-import-help" id="draftOrderImportHelp">
            Drag a file onto this area, or click the blue button / plus sign to choose one manually.
          </div>
          <div class="draft-import-file-name text-muted small mt-2" id="draftOrderImportFileName">No file selected</div>
        </div>

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
          <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars($basePath) ?>/download_template.php?slug=procurement-import-template-xlsx" download>
            Download import template
          </a>
          <span class="text-muted small">Accepted: .xlsx, .xls, .csv</span>
        </div>

        <div class="draft-import-status mt-3 d-none" id="draftOrderImportStatus"></div>

        <div class="draft-import-guide small text-muted mt-3">
          <div><strong>Required header:</strong> English Item Name, Chinese Item Name, Product / Names, or Description.</div>
          <div><strong>After downloading:</strong> fill at least one product row below the blue header before importing.</div>
          <div><strong>Useful columns:</strong> SKU / item code, Quantity, Unit, Pieces/Carton, Cartons, Factory Price, Customer Price, CBM/Unit, Weight/Unit, Supplier, HS Code, Notes / Description.</div>
          <div><strong>Optional metadata:</strong> Customer, Destination Country, Expected Ready, Currency. Supplier sections can also start with <code>Supplier:</code>.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary" id="draftOrderImportChooseFileBtn">Choose Excel/CSV file</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="draftOrderModal" tabindex="-1">
  <div class="modal-dialog modal-fullscreen">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-1" id="draftOrderModalTitle">Draft an Order</h5>
          <small class="text-muted" id="draftOrderModalSubtitle">One customer, multiple supplier sections, compact item cards, live totals.</small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body draft-order-builder-body">
        <form id="draftOrderForm">
          <input type="hidden" id="draftOrderId">
          <input type="hidden" id="draftOrderEditable" value="1">
          <div class="card mb-4 draft-order-meta-card">
            <div class="card-body">
              <div class="row g-3">
                <div class="col-12 col-lg-4">
                  <label class="form-label">Customer *</label>
                  <input type="text" class="form-control form-control-sm" id="draftOrderCustomer" placeholder="Type to search customer..." autocomplete="off">
                </div>
                <div class="col-12 col-md-4 col-lg-3">
                  <label class="form-label">Country / Destination</label>
                  <input type="hidden" id="draftOrderDestinationCountryId">
                  <div id="draftOrderDestinationCountryInputWrap">
                    <input type="text" class="form-control form-control-sm" id="draftOrderDestinationCountry" placeholder="Search country..." autocomplete="off">
                  </div>
                  <div id="draftOrderDestinationCountrySelectWrap" class="d-none">
                    <select class="form-select form-select-sm" id="draftOrderDestinationCountrySelect">
                      <option value="">Select country...</option>
                    </select>
                  </div>
                </div>
                <div class="col-12 col-md-4 col-lg-2">
                  <label class="form-label">Expected Ready</label>
                  <input type="date" class="form-control form-control-sm" id="draftOrderExpectedDate">
                </div>
                <div class="col-12 col-md-4 col-lg-3">
                  <label class="form-label">Currency *</label>
                  <select class="form-select form-select-sm" id="draftOrderCurrency">
                     <option value="RMB">RMB</option>
                     <option value="USD">USD</option>
                   
                  </select>
                </div>
                <div class="col-12">
                  <label class="form-label">High Alert Notes</label>
                  <textarea class="form-control form-control-sm" id="draftOrderHighAlertNotes" rows="2" placeholder="Special handling, urgent notes, fragile warnings..."></textarea>
                </div>
              </div>
              <div class="alert alert-info border mt-3 mb-0 py-2" id="draftOrderShippingHint">
                Select a customer to load the customer shipping code and item-number prefix automatically.
              </div>
            </div>
          </div>

          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
              <div class="fw-semibold text-dark">Supplier Sections</div>
              <small class="text-muted">Photo-first item cards, auto item numbers, compact fields, and supplier-level totals stay live while you build.</small>
            </div>
            <div class="d-flex gap-2 flex-wrap">
              <button type="button" class="btn btn-outline-primary btn-sm" id="draftOrderModalImportBtn">Import</button>
              <button type="button" class="btn btn-outline-primary btn-sm" id="draftOrderAddSectionBtn" onclick="addDraftOrderSection()">+ Add Supplier Section</button>
            </div>
          </div>

          <div id="draftOrderSections" class="d-flex flex-column gap-4"></div>

          <div class="card mt-4">
            <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3">
              <div>
                <div class="fw-semibold">Order Totals</div>
                <small class="text-muted">Auto-calculated across all supplier sections.</small>
              </div>
              <div class="d-flex gap-4 flex-wrap">
                <div><strong id="draftOrderTotalAmount">0</strong> <span class="text-muted" id="draftOrderTotalCurrency">USD</span></div>
                <div><strong id="draftOrderTotalQty">0</strong> <span class="text-muted">qty</span></div>
                <div><strong id="draftOrderTotalCbm">0</strong> <span class="text-muted">CBM</span></div>
                <div><strong id="draftOrderTotalWeight">0</strong> <span class="text-muted">kg</span></div>
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

<div class="modal fade" id="draftSupplierQuickAddModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-1"><?= clmsT('Quick Add Supplier') ?></h5>
          <small class="text-muted"><?= clmsT('Create a supplier without leaving the draft. The saved supplier becomes selectable immediately in this supplier section.') ?></small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="draftSupplierQuickForm">
          <input type="hidden" id="draftQuickSupplierTargetSection">
          <div class="row g-3">
            <div class="col-12 col-md-4">
              <label class="form-label"><?= clmsT('Store ID') ?></label>
              <input type="text" class="form-control" id="draftQuickSupplierStoreId">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label"><?= clmsT('Name *') ?></label>
              <input type="text" class="form-control" id="draftQuickSupplierName" required>
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label"><?= clmsT('Address') ?></label>
              <input type="text" class="form-control" id="draftQuickSupplierAddress" placeholder="<?= clmsT('Factory or office address') ?>">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label"><?= clmsT('Phone') ?></label>
              <input type="text" class="form-control" id="draftQuickSupplierPhone">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label"><?= clmsT('Commission') ?></label>
              <input type="number" min="0" step="0.0001" class="form-control" id="draftQuickSupplierCommission" placeholder="<?= clmsT('e.g. 5') ?>">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label"><?= clmsT('Payment Facility (days)') ?></label>
              <input type="number" min="0" step="1" class="form-control" id="draftQuickSupplierFacility" placeholder="30">
            </div>
            <div class="col-12">
              <label class="form-label"><?= clmsT('Payment Accounts') ?></label>
              <div id="draftQuickSupplierPaymentLinks" class="d-flex flex-column gap-2"></div>
              <button type="button" class="btn btn-outline-secondary btn-sm mt-2" onclick="addDraftQuickSupplierPaymentLink()">+ Add Account</button>
            </div>
            <div class="col-12">
              <label class="form-label">Supplier Files / QR Images</label>
              <input type="file" class="form-control" id="draftQuickSupplierFiles" accept="image/*,.pdf" multiple>
              <small class="text-muted d-block mt-1">Optional. QR or account images upload right after the supplier record is created.</small>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="draftQuickSupplierSaveBtn" onclick="saveDraftQuickSupplier()">Save Supplier</button>
      </div>
    </div>
  </div>
</div>

<?php
$pageScripts = [
  'frontend/js/autocomplete.js?v=' . @filemtime(__DIR__ . '/frontend/js/autocomplete.js'),
  'frontend/js/photo_uploader.js?v=' . @filemtime(__DIR__ . '/frontend/js/photo_uploader.js'),
  'frontend/js/lib/jsQR.js?v=' . @filemtime(__DIR__ . '/frontend/js/lib/jsQR.js'),
  'frontend/js/wechat_qr_scanner.js?v=' . @filemtime(__DIR__ . '/frontend/js/wechat_qr_scanner.js'),
];
$pageScript = 'frontend/js/procurement_drafts.js?v=' . @filemtime(__DIR__ . '/frontend/js/procurement_drafts.js');
require 'includes/footer.php';
?>
