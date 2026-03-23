<?php
$area = 'warehouse';
require __DIR__ . '/../includes/area_bootstrap.php';
$currentPage = 'expenses';
$pageTitle = 'Warehouse Expenses';
$breadcrumbs = [['Warehouse', '/cargochina/warehouse/'], ['Expenses', '']];
require __DIR__ . '/../includes/area_layout.php';
?>
<h1 class="mb-4">Warehouse Expenses</h1>
<p class="text-muted mb-4">Record pallet fees, delivery fees, seketerik fees, and other order-related expenses. Date is set automatically. Contact admin to remove an expense.</p>

<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>Add Expense</span>
  </div>
  <div class="card-body">
    <form id="warehouseExpenseForm" class="row g-3">
      <div class="col-12 col-md-4">
        <label class="form-label">Category *</label>
        <select class="form-select" id="whCategory" required>
          <option value="">— Select —</option>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Amount *</label>
        <input type="number" step="0.01" min="0" class="form-control" id="whAmount" required>
      </div>
      <div class="col-12 col-md-2">
        <label class="form-label">Currency</label>
        <select class="form-select" id="whCurrency">
          <option value="USD">USD</option>
          <option value="RMB">RMB</option>
          <option value="EUR">EUR</option>
        </select>
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label">To whom we're paying</label>
        <input type="text" class="form-control" id="whPayee" placeholder="Payee / vendor name">
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label">Order</label>
        <input type="text" class="form-control" id="whOrderId" placeholder="Type to search order…" autocomplete="off">
      </div>
      <div class="col-12">
        <label class="form-label">Notes</label>
        <textarea class="form-control" id="whNotes" rows="2" placeholder="Optional note"></textarea>
      </div>
      <div class="col-12">
        <button type="submit" class="btn btn-primary">Save Expense</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">Recent Expenses</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover table-striped table-sm align-middle mb-0">
        <thead>
          <tr>
            <th>Date</th>
            <th>Category</th>
            <th>Amount</th>
            <th>Payee</th>
            <th>Order</th>
            <th>Notes</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="whExpensesTbody"></tbody>
      </table>
    </div>
    <div id="whExpensesEmpty" class="text-center py-4 text-muted d-none">No expenses yet.</div>
  </div>
</div>

<!-- Edit modal (warehouse can fix typos, but cannot delete) -->
<div class="modal fade" id="whExpenseEditModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Edit Expense</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="whEditId">
        <div class="mb-3">
          <label class="form-label">Category *</label>
          <select class="form-select" id="whEditCategory"></select>
        </div>
        <div class="row mb-3">
          <div class="col-8"><label class="form-label">Amount *</label><input type="number" step="0.01" class="form-control" id="whEditAmount" required></div>
          <div class="col-4"><label class="form-label">Currency</label><select class="form-select" id="whEditCurrency">
            <option value="USD">USD</option>
            <option value="RMB">RMB</option>
            <option value="EUR">EUR</option>
          </select></div>
        </div>
        <div class="mb-3"><label class="form-label">Payee</label><input type="text" class="form-control" id="whEditPayee"></div>
        <div class="mb-3"><label class="form-label">Order</label><input type="text" class="form-control" id="whEditOrderId" placeholder="Type to search…" autocomplete="off"></div>
        <div class="mb-3"><label class="form-label">Notes</label><textarea class="form-control" id="whEditNotes" rows="2"></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" onclick="saveWhExpenseEdit()">Save</button>
      </div>
    </div>
  </div>
</div>

<?php
$pageScripts = ['/cargochina/frontend/js/autocomplete.js?v=' . filemtime(__DIR__ . '/../frontend/js/autocomplete.js')];
$pageScript = '/cargochina/frontend/js/warehouse_expenses.js?v=' . filemtime(__DIR__ . '/../frontend/js/warehouse_expenses.js');
require __DIR__ . '/../includes/area_footer.php';
?>
