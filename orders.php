<?php
$currentPage = 'orders';
$pageTitle = 'Orders';
require 'includes/layout.php';
?>
<link rel="stylesheet" href="frontend/css/style.css">
<div class="col-12">
  <h1 class="mb-4">Orders</h1>
  <div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span>Order List</span>
      <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#orderModal" onclick="openOrderForm()">+ New Order</button>
    </div>
    <div class="card-body">
      <div class="row mb-3">
        <div class="col-md-4">
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
        <div class="col-md-4">
          <select class="form-select form-select-sm" id="filterCustomer" onchange="loadOrders()">
            <option value="">All customers</option>
          </select>
        </div>
      </div>
      <div id="ordersTable" class="table-responsive">
        <table class="table table-hover">
          <thead>
            <tr>
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
          <div class="row mb-3">
            <div class="col-md-4"><label class="form-label">Customer *</label><select class="form-select" id="orderCustomer" required></select></div>
            <div class="col-md-4"><label class="form-label">Supplier *</label><select class="form-select" id="orderSupplier" required></select></div>
            <div class="col-md-4"><label class="form-label">Expected Ready Date *</label><input type="date" class="form-control" id="orderExpectedDate" required></div>
          </div>
          <h6>Items</h6>
          <div class="table-responsive">
            <table class="table table-sm">
              <thead>
                <tr>
                  <th>Product / Description</th>
                  <th>Qty</th>
                  <th>Unit</th>
                  <th>Declared CBM</th>
                  <th>Declared Weight</th>
                  <th></th>
                </tr>
              </thead>
              <tbody id="orderItemsBody"></tbody>
            </table>
          </div>
          <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addOrderItem()">+ Add Item</button>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" onclick="saveOrder()">Save</button>
      </div>
    </div>
  </div>
</div>
<?php $pageScript = 'frontend/js/orders.js';
require 'includes/footer.php'; ?>