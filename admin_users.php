<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['SuperAdmin']);
$currentPage = 'admin_users';
$pageTitle = 'User Management';
require 'includes/layout.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="mb-1">User Management</h1>
    <p class="text-muted mb-0">Manage profiles, roles, and department assignments for ~40 employees.</p>
  </div>
  <button type="button" class="btn btn-primary" id="createUserBtn" title="Create user">
    <span aria-hidden="true">+</span> Create User
  </button>
</div>
<div class="card">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover table-striped table-sm align-middle">
        <thead>
          <tr>
            <th>ID</th>
            <th>Email</th>
            <th>Name</th>
            <th>Roles</th>
            <th>Departments</th>
            <th>Active</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="usersBody"></tbody>
      </table>
    </div>
  </div>
</div>

<div class="card mt-4" id="activityPanel" style="display:none">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h5 class="mb-0" id="activityPanelTitle">User Activity</h5>
    <button type="button" class="btn-close" onclick="hideActivityPanel()" aria-label="Close"></button>
  </div>
  <div class="card-body py-2">
    <div class="row g-2 align-items-end mb-3">
      <div class="col-auto">
        <label class="form-label small mb-0">Entity type</label>
        <select class="form-select form-select-sm" id="activityEntityType" style="width:160px">
          <option value="">— All —</option>
          <option value="order">Order</option>
          <option value="shipment_draft">Shipment draft</option>
          <option value="expense">Expense</option>
          <option value="procurement_draft">Procurement draft</option>
          <option value="order_template">Order template</option>
          <option value="customer_deposit">Customer deposit</option>
          <option value="supplier_interaction">Supplier interaction</option>
          <option value="customer_portal_token">Customer portal token</option>
          <option value="design_attachment">Design attachment</option>
          <option value="user">User</option>
          <option value="system_config">System config</option>
          <option value="internal_message">Internal message</option>
        </select>
      </div>
      <div class="col-auto">
        <label class="form-label small mb-0">Action</label>
        <select class="form-select form-select-sm" id="activityAction" style="width:120px">
          <option value="">— All —</option>
          <option value="create">Create</option>
          <option value="update">Update</option>
          <option value="submit">Submit</option>
          <option value="approve">Approve</option>
          <option value="receive">Receive</option>
          <option value="confirm">Confirm</option>
        </select>
      </div>
      <div class="col-auto">
        <label class="form-label small mb-0">Date from</label>
        <input type="date" class="form-control form-control-sm" id="activityDateFrom">
      </div>
      <div class="col-auto">
        <label class="form-label small mb-0">Date to</label>
        <input type="date" class="form-control form-control-sm" id="activityDateTo">
      </div>
      <div class="col-auto">
        <button type="button" class="btn btn-primary btn-sm" onclick="loadUserActivity()">Apply</button>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-hover table-sm mb-0">
        <thead>
          <tr>
            <th>Time</th>
            <th>Entity</th>
            <th>Action</th>
            <th>Details</th>
          </tr>
        </thead>
        <tbody id="activityBody"></tbody>
      </table>
    </div>
    <div id="activityEmpty" class="text-center py-4 text-muted d-none">No activity found.</div>
    <div class="p-2 text-end">
      <button type="button" class="btn btn-outline-secondary btn-sm" id="activityLoadMoreBtn" onclick="loadMoreActivity()" style="display:none">Load more</button>
    </div>
  </div>
</div>

<div class="modal fade" id="userEditModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Edit User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="editUserId">
        <div class="mb-3">
          <label class="form-label">Roles</label>
          <div id="editRoles" class="d-flex flex-wrap gap-2"></div>
        </div>
        <div class="mb-3">
          <label class="form-label">Departments</label>
          <select class="form-select" id="editDepartments" multiple size="4"></select>
          <small class="text-muted">First selected = primary department</small>
        </div>
        <hr class="my-3">
        <div class="mb-3">
          <label class="form-label">Reset Password</label>
          <form id="resetPwForm" onsubmit="return false;">
            <div class="d-flex gap-2 align-items-center flex-wrap">
              <input type="password" class="form-control" id="resetPassword" placeholder="New password (min 6 chars)" autocomplete="new-password" style="max-width:200px">
              <button type="button" class="btn btn-warning" id="resetPwBtn" onclick="resetPassword()">Reset</button>
            </div>
            <div class="form-check mt-2">
              <input class="form-check-input" type="checkbox" id="toggleResetPw" title="Show password">
              <label class="form-check-label" for="toggleResetPw">Show password</label>
            </div>
          </form>
          <small class="text-muted d-block mt-1">New password will be shown once after reset. Copy it to share with the user.</small>
        </div>
        <div id="resetPasswordResult" class="alert alert-success d-none mb-0">
          <strong>Password reset.</strong> New password: <code id="displayNewPassword"></code>
          <button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="copyNewPassword()">Copy</button>
        </div>
        <div class="mb-0">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="editActive" checked>
            <label class="form-check-label" for="editActive">Active</label>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="saveUserBtn" onclick="saveUser()">Save</button>
      </div>
    </div>
  </div>
</div>
<div class="modal fade" id="userCreateModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Create User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Email</label>
          <input type="email" class="form-control" id="createEmail" placeholder="user@example.com" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Full name</label>
          <input type="text" class="form-control" id="createFullName" placeholder="Full name" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Password</label>
          <input type="password" class="form-control" id="createPassword" placeholder="Min 6 characters" autocomplete="new-password" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Roles</label>
          <div id="createRoles" class="d-flex flex-wrap gap-2"></div>
        </div>
        <div class="mb-3">
          <label class="form-label">Departments</label>
          <select class="form-select" id="createDepartments" multiple size="4"></select>
          <small class="text-muted">First selected = primary department</small>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="saveCreateUserBtn" onclick="createUser()">Create</button>
      </div>
    </div>
  </div>
</div>
<?php $pageScript = '/cargochina/frontend/js/admin_users.js?v=' . @filemtime(__DIR__ . '/frontend/js/admin_users.js');
require 'includes/footer.php'; ?>