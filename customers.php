<?php
$currentPage = 'customers';
$pageTitle = 'Customers';
require 'includes/layout.php';
?>
<link rel="stylesheet" href="frontend/css/style.css">
<div class="col-12">
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
<?php $pageScript = 'frontend/js/customers.js';
require 'includes/footer.php'; ?>