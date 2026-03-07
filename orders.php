<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin']);
$currentPage = 'orders';
$pageTitle = 'Orders';
require 'includes/layout.php';
?>
<h1 class="mb-4">Orders</h1>
<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span>Order List</span>
    <div class="d-flex gap-2">
      <button class="btn btn-outline-success btn-sm d-none" id="bulkApproveBtn" onclick="bulkApproveOrders()" title="Approve selected submitted orders">Bulk Approve</button>
      <button class="btn btn-outline-secondary btn-sm" onclick="exportOrdersCsv()" title="Export current list to CSV">Export CSV</button>
      <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#orderModal" onclick="openOrderForm()">+ New Order</button>
    </div>
  </div>
  <div class="card-body">
    <div class="row mb-3 form-row-responsive">
      <div class="col-12 col-md-4">
        <select class="form-select form-select-sm" id="filterStatus" onchange="loadOrders()">
          <option value="">All statuses</option>
          <option value="Draft">Draft</option>
          <option value="Submitted">Submitted</option>
          <option value="Approved">Approved</option>
          <option value="InTransitToWarehouse">In Transit</option>
          <option value="ReceivedAtWarehouse">Received</option>
          <option value="AwaitingCustomerConfirmation">Awaiting Confirmation</option>
          <option value="Confirmed">Confirmed</option>
          <option value="ReadyForConsolidation">Ready for Consolidation</option>
          <option value="ConsolidatedIntoShipmentDraft">In Shipment Draft</option>
          <option value="AssignedToContainer">Assigned to Container</option>
          <option value="FinalizedAndPushedToTracking">Finalized</option>
        </select>
      </div>
      <div class="col-12 col-md-4">
        <input type="text" class="form-control form-control-sm" id="filterCustomer" placeholder="Filter by customer (type to search)">
      </div>
    </div>
    <div id="ordersTable" class="table-responsive">
      <table class="table table-hover">
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
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="orderModalTitle">New Order</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="orderForm">
          <input type="hidden" id="orderId">
          <div class="row mb-3 form-row-responsive">
            <div class="col-12 col-md-4 mb-2">
              <label class="form-label">Customer *</label>
              <input type="text" class="form-control" id="orderCustomer" placeholder="Type to search..." required>
              <div class="mt-1 small" id="recentCustomers"></div>
            </div>
            <div class="col-12 col-md-4 mb-2">
              <label class="form-label">Supplier *</label>
              <input type="text" class="form-control" id="orderSupplier" placeholder="Type to search..." required>
              <div class="mt-1 small" id="recentSuppliers"></div>
            </div>
            <div class="col-12 col-md-2 mb-2"><label class="form-label">Expected Ready *</label><input type="date" class="form-control" id="orderExpectedDate" required></div>
            <div class="col-12 col-md-2 mb-2"><label class="form-label">Currency *</label><select class="form-select" id="orderCurrency">
                <option value="USD">USD</option>
                <option value="RMB">RMB</option>
              </select></div>
          </div>
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0">Items</h6>
            <div class="d-flex gap-2">
              <select class="form-select form-select-sm" id="orderTemplateSelect" style="width:auto" onchange="loadOrderTemplate(this.value)">
                <option value="">Load template...</option>
              </select>
              <button type="button" class="btn btn-outline-secondary btn-sm" onclick="saveOrderAsTemplate()" title="Save current items as template">Save as template</button>
            </div>
          </div>
          <p class="text-muted small mb-3">Min 1 photo per item required to submit. Totals computed live.</p>
          <div class="table-responsive">
            <table class="table table-sm table-hover">
              <thead>
                <tr>
                  <th>Photo</th>
                  <th>Item No</th>
                  <th>Ship Code</th>
                  <th>Description</th>
                  <th>CTNS</th>
                  <th>Qty/Ctn</th>
                  <th>Total Qty</th>
                  <th>Unit $</th>
                  <th>Total $</th>
                  <th>CBM</th>
                  <th>Total CBM</th>
                  <th>Wt(kg)</th>
                  <th>Total GW</th>
                  <th></th>
                </tr>
              </thead>
              <tbody id="orderItemsBody"></tbody>
              <tfoot>
                <tr class="order-totals-row" id="orderTotalsRow">
                  <td colspan="8" class="text-end">Totals:</td>
                  <td id="orderTotalAmount">0</td>
                  <td></td>
                  <td id="orderTotalCbm">0</td>
                  <td></td>
                  <td id="orderTotalWeight">0</td>
                  <td></td>
                </tr>
              </tfoot>
            </table>
          </div>
          <button type="button" class="btn btn-outline-secondary btn-sm mt-2" onclick="addOrderItem()">+ Add Item</button>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" onclick="saveOrder()">Save</button>
      </div>
    </div>
  </div>
</div>
<?php $pageScripts = ['frontend/js/autocomplete.js'];
$pageScript = 'frontend/js/orders.js';
require 'includes/footer.php'; ?>