<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin', 'WarehouseStaff']);
$userRoles = $_SESSION['user_roles'] ?? [];
$isWarehouseOnly = in_array('WarehouseStaff', $userRoles) && !array_intersect($userRoles, ['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin']);
if ($isWarehouseOnly) {
    header('Location: /cargochina/warehouse/expenses.php');
    exit;
}
$currentPage = 'expenses';
$pageTitle = 'Expenses';
require 'includes/layout.php';
?>
<h1 class="mb-4">Expenses</h1>
<p class="text-muted mb-4">Manage operational expenses, salaries, order and container costs. Warehouse staff can record pallet fees, delivery fees, and other order-related expenses.</p>

<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span>Expense List</span>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#expenseModal" onclick="openExpenseForm()">+ Add Expense</button>
  </div>
  <div class="card-body">
    <div class="row mb-3 g-2 form-row-responsive">
      <div class="col-12 col-md-3"><label class="form-label small">Search</label><input type="text" class="form-control form-control-sm" id="filterSearch" placeholder="Search payee, notes, category, supplier… (auto-applies)" autocomplete="off"></div>
      <div class="col-12 col-md-2"><label class="form-label small">Date from</label><input type="date" class="form-control form-control-sm" id="filterDateFrom"></div>
      <div class="col-12 col-md-2"><label class="form-label small">Date to</label><input type="date" class="form-control form-control-sm" id="filterDateTo"></div>
      <div class="col-12 col-md-2"><label class="form-label small">Category</label><select class="form-select form-select-sm" id="filterCategory">
          <option value="">All</option>
        </select></div>
      <div class="col-12 col-md-2"><label class="form-label small">Order</label><input type="text" class="form-control form-control-sm" id="filterOrderId" placeholder="Type to search…" autocomplete="off"></div>
      <div class="col-12 col-md-2"><label class="form-label small">Container</label><input type="text" class="form-control form-control-sm" id="filterContainerId" placeholder="Type to search…" autocomplete="off"></div>
      <div class="col-12 col-md-2"><label class="form-label small">Supplier</label><input type="text" class="form-control form-control-sm" id="filterSupplierId" placeholder="Type to search…" autocomplete="off"></div>
      <div class="col-12 col-md-2 d-flex align-items-end gap-2">
        <button class="btn btn-outline-secondary btn-sm" onclick="clearExpenseFilters()">Clear</button>
      </div>
    </div>
    <div class="mb-3" id="expenseSummary"></div>
    <div id="expensesTable" class="table-responsive">
      <table class="table table-hover table-striped table-sm align-middle mb-0">
        <thead>
          <tr>
            <th>Date</th>
            <th>Category</th>
            <th>Amount</th>
            <th>Payee</th>
            <th>Order</th>
            <th>Container</th>
            <th>Notes</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="expenseModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="expenseModalTitle">Add Expense</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="expenseForm">
          <input type="hidden" id="expenseId">
          <div class="mb-3"><label class="form-label">Category *</label><input type="text" class="form-control" id="expenseCategory" placeholder="Type to search or enter a new category…" autocomplete="off" required></div>
          <div class="row mb-3">
            <div class="col-8"><label class="form-label">Amount *</label><input type="number" step="0.01" class="form-control" id="expenseAmount" required></div>
            <div class="col-4"><label class="form-label">Currency</label><select class="form-select" id="expenseCurrency">
                <option value="USD">USD</option>
                <option value="RMB">RMB</option>
                <option value="EUR">EUR</option>
              </select></div>
          </div>
          <div class="mb-3"><label class="form-label">Date *</label><input type="date" class="form-control" id="expenseDate" required></div>
          <div class="mb-3"><label class="form-label">Payee / Vendor</label><input type="text" class="form-control" id="expensePayee" placeholder="Type to search or enter new…" autocomplete="off"></div>
          <div class="mb-3"><label class="form-label">Link to Order</label><input type="text" class="form-control" id="expenseOrderId" placeholder="Type to search order…" autocomplete="off"></div>
          <div class="mb-3"><label class="form-label">Link to Container</label><input type="text" class="form-control" id="expenseContainerId" placeholder="Type to search container…" autocomplete="off"></div>
          <div class="mb-3"><label class="form-label">Notes</label><textarea class="form-control" id="expenseNotes" rows="2"></textarea></div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" onclick="saveExpense()">Save</button>
      </div>
    </div>
  </div>
</div>
<?php $pageScripts = ['frontend/js/autocomplete.js', 'frontend/js/expenses.js'];
require 'includes/footer.php'; ?>