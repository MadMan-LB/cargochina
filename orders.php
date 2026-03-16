<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin']);
$currentPage = 'orders';
$pageTitle = 'Orders';
require 'includes/layout.php';
?>
<h1 class="mb-4">Orders</h1>
<p class="text-muted mb-4">Track draft, approval, confirmation, and consolidation readiness from one cleaner workspace.</p>

<div class="metric-card-grid mb-4">
  <div class="metric-card">
    <div class="eyebrow">Visible Now</div>
    <div class="value" id="orderVisibleCount">0</div>
    <div class="detail">Orders matching the current filters</div>
  </div>
  <div class="metric-card">
    <div class="eyebrow">Draft</div>
    <div class="value" id="orderDraftCount">0</div>
    <div class="detail">Still waiting to be submitted</div>
  </div>
  <div class="metric-card">
    <div class="eyebrow">Awaiting Confirmation</div>
    <div class="value" id="orderAwaitingCount">0</div>
    <div class="detail">Customer action still needed</div>
  </div>
  <div class="metric-card">
    <div class="eyebrow">Ready to Move</div>
    <div class="value" id="orderReadyCount">0</div>
    <div class="detail">Ready for consolidation or already assigned</div>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span>Order List</span>
    <div class="d-flex gap-2">
      <button class="btn btn-outline-primary btn-sm d-none" id="bulkSubmitBtn" onclick="bulkSubmitOrders()" title="Submit selected draft orders">Bulk Submit</button>
      <button class="btn btn-outline-success btn-sm d-none" id="bulkApproveBtn" onclick="bulkApproveOrders()" title="Approve selected submitted orders">Bulk Approve</button>
      <button class="btn btn-outline-secondary btn-sm" onclick="exportOrdersCsv()" title="Export current list to CSV">Export CSV</button>
      <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#orderModal" onclick="openOrderForm()">+ New Order</button>
    </div>
  </div>
  <div class="card-body">
    <div class="filter-toolbar-grid mb-3">
      <div class="filter-toolbar-card soft">
        <div class="filter-toolbar-head">
          <div>
            <div class="title">Statuses</div>
            <div class="filter-toolbar-subtext">Pick multiple stages and include or exclude them together.</div>
          </div>
          <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" onclick="clearOrderStatusFilter()">Clear</button>
        </div>
        <div class="filter-chip-grid" id="filterStatusList">
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
              <input class="form-check-input order-status-filter" type="checkbox" value="<?= htmlspecialchars($statusValue) ?>" id="orderStatus<?= htmlspecialchars($statusValue) ?>" onchange="updateOrderStatusFilterSummary();loadOrders()">
              <label class="form-check-label" for="orderStatus<?= htmlspecialchars($statusValue) ?>"><?= htmlspecialchars($statusLabel) ?></label>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="filter-summary-row">
          <div class="d-flex gap-2 align-items-center flex-wrap">
            <select class="form-select form-select-sm" id="filterStatusMode" onchange="updateOrderStatusFilterSummary();loadOrders()">
              <option value="include">Include selected</option>
              <option value="exclude">Exclude selected</option>
            </select>
            <small class="summary-text" id="filterStatusSummary">All statuses</small>
          </div>
        </div>
      </div>
      <div class="filter-toolbar-card">
        <div class="filter-toolbar-head">
          <div>
            <div class="title">Search & Actions</div>
            <div class="filter-toolbar-subtext">Find by customer, phone, shipping code, supplier, or item details. Suggestions appear as you type.</div>
          </div>
        </div>
        <div class="input-group input-group-sm">
          <input type="text" class="form-control" id="orderSearch" placeholder="Customer, phone, shipping code, items...">
          <button class="btn btn-outline-primary" type="button" onclick="loadOrders()" title="Search">Search</button>
          <button class="btn btn-outline-secondary" type="button" onclick="clearOrderSearch()" title="Clear">Clear</button>
        </div>
        <div class="filter-summary-row">
          <small class="summary-text">Tip: use the dropdown to jump to the right order faster, then combine it with the status chips above.</small>
        </div>
      </div>
    </div>
    <div id="ordersTable" class="table-responsive">
      <table class="table table-hover table-striped table-sm align-middle">
        <thead>
          <tr>
            <th class="text-center" style="width:2.5rem"><input type="checkbox" class="form-check-input" id="orderSelectAll" title="Select all submitted"></th>
            <th>ID</th>
            <th>Customer</th>
            <th>Supplier</th>
            <th>Expected Ready</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="orderModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-fullscreen-lg-down">
    <div class="modal-content order-modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="orderModalTitle">New Order</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="orderForm">
          <input type="hidden" id="orderId">
          <div class="order-form-panel order-form-basics mb-4">
            <div class="order-form-panel-header">
              <div>
                <div class="order-form-eyebrow">Basics</div>
                <h6 class="mb-1">Order details</h6>
                <p class="text-muted small mb-0">Choose the customer, default supplier, expected ready date, and currency first.</p>
              </div>
            </div>
            <div class="row form-row-responsive g-3">
              <div class="col-12 col-md-4">
                <label class="form-label">Customer *</label>
                <input type="text" class="form-control" id="orderCustomer" placeholder="Type to search..." required>
                <div class="mt-2 small order-inline-help" id="recentCustomers"></div>
              </div>
              <div class="col-12 col-md-4">
                <label class="form-label">Default supplier (optional)</label>
                <input type="text" class="form-control" id="orderSupplier" placeholder="Type to search... (used for new items)" autocomplete="off">
                <small class="text-muted d-block mt-2">Each item can have its own supplier; this sets the default for new rows. Leave blank for multi-supplier orders.</small>
                <div class="mt-2 small order-inline-help" id="recentSuppliers"></div>
              </div>
              <div class="col-12 col-md-2">
                <label class="form-label">Expected Ready *</label>
                <input type="date" class="form-control" id="orderExpectedDate" required>
              </div>
              <div class="col-12 col-md-2">
                <label class="form-label">Currency *</label>
                <select class="form-select" id="orderCurrency">
                  <option value="USD">USD</option>
                  <option value="RMB">RMB</option>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label">High Alert Notes</label>
                <textarea class="form-control" id="orderHighAlertNotes" rows="2" placeholder="Fragile, special handling, etc."></textarea>
              </div>
            </div>
          </div>
          <div class="order-form-panel">
            <div class="order-form-panel-header order-form-panel-header-stack mb-2">
              <div>
                <div class="order-form-eyebrow">Items</div>
                <h6 class="mb-1">Goods details</h6>
                <p class="text-muted small mb-0">Add item lines with supplier, packaging, pricing, CBM, and weight.</p>
              </div>
              <div class="order-template-actions">
                <select class="form-select form-select-sm" id="orderTemplateSelect" onchange="loadOrderTemplate(this.value)">
                  <option value="">Load template...</option>
                </select>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="saveOrderAsTemplate()" title="Save current items as template">Save as template</button>
              </div>
            </div>
            <p class="text-muted small order-form-tip mb-3">Min 1 photo per item is required before submit. Totals update as you type.</p>
            <div class="d-flex gap-2 mb-2">
              <button type="button" class="btn btn-outline-secondary btn-sm" id="togglePasteCsv" onclick="togglePasteCsv()">Paste CSV</button>
            </div>
            <div id="pasteCsvArea" class="mb-3 d-none">
              <textarea class="form-control font-monospace" id="pasteCsvData" rows="6" placeholder="description,item_no,cartons,qty_per_carton,qty,unit_price,weight,cbm&#10;Widget A,A-001,10,100,1000,1.50,0.5,0.25"></textarea>
              <small class="text-muted d-block mt-1">Paste rows from Excel/Sheets. Optional header row. Columns: description, item_no, cartons, qty_per_carton, qty, unit_price, weight, cbm</small>
              <div class="d-flex gap-2 mt-2">
                <button type="button" class="btn btn-primary btn-sm" onclick="importOrderItemsFromCsv()">Import rows</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="saveCsvAsTemplate()">Save as template</button>
              </div>
            </div>
            <div id="orderItemsBody" class="order-items-cards d-flex flex-column gap-3"></div>
            <div class="order-totals-bar d-flex justify-content-between align-items-center gap-3 mt-3 py-3 px-3 rounded bg-light border">
              <div>
                <span class="fw-medium d-block">Order totals</span>
                <small class="text-muted">Quick summary for amount, volume, and gross weight.</small>
              </div>
              <div class="d-flex align-items-center gap-4">
                <span><strong id="orderTotalAmount">$0.00</strong></span>
                <span class="text-muted"><span id="orderTotalCbm">0</span> CBM</span>
                <span class="text-muted"><span id="orderTotalWeight">0</span> kg</span>
              </div>
            </div>
            <button type="button" class="btn btn-outline-primary mt-3 order-add-item-btn" onclick="addOrderItem()">+ Add Item</button>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" onclick="saveOrder()">Save</button>
      </div>
    </div>
  </div>
</div>
<!-- Order Info Modal -->
<div class="modal fade" id="orderInfoModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-fullscreen-lg-down">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="orderInfoTitle">Order Info</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="orderInfoBody">
        <div class="text-center py-5">
          <div class="spinner-border text-primary"></div>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- Assign to Draft Modal -->
<div class="modal fade" id="assignDraftModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Assign Order to Shipment Draft</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-3">Order <strong id="assignDraftOrderLabel"></strong></p>
        <div id="assignDraftWarning" class="alert alert-warning d-none mb-3"></div>
        <div id="assignDraftList">
          <div class="text-center py-3">
            <div class="spinner-border spinner-border-sm text-primary"></div>
          </div>
        </div>
        <hr class="my-3">
        <p class="small text-muted mb-1">Or create a new draft for this order:</p>
        <button class="btn btn-outline-primary btn-sm" onclick="assignOrderToNewDraft()">+ New Shipment Draft</button>
      </div>
    </div>
  </div>
</div>
<!-- Order Item Design Attachments Modal -->
<div class="modal fade" id="orderItemDesignModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Design Attachments — Item <span id="orderItemDesignLabel"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="orderItemDesignList" class="mb-2"></div>
        <input type="file" class="d-none" id="orderItemDesignInput" accept="image/*,application/pdf,.pdf">
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('orderItemDesignInput').click()">+ Add design file</button>
      </div>
    </div>
  </div>
</div>
<!-- Finance / P&L Modal -->
<div class="modal fade" id="financeModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">💰 Order Finance — <span id="financeOrderId"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="financeModalBody">
        <div class="text-center py-4">
          <div class="spinner-border spinner-border-sm text-primary"></div> Loading…
        </div>
      </div>
    </div>
  </div>
</div>
<?php $pageScripts = ['frontend/js/autocomplete.js?v=' . filemtime(__DIR__ . '/frontend/js/autocomplete.js')];
$pageScript = 'frontend/js/orders.js?v=' . filemtime(__DIR__ . '/frontend/js/orders.js');
require 'includes/footer.php'; ?>
