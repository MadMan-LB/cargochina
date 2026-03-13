<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['SuperAdmin']);
$currentPage = 'admin_users';
$pageTitle = 'User Management';
require 'includes/layout.php';
?>
<h1 class="mb-4">User Management</h1>
<p class="text-muted">Manage profiles, roles, and department assignments for ~40 employees.</p>
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
<?php $pageScript = 'frontend/js/admin_users.js';
require 'includes/footer.php'; ?>