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
    <table class="table table-hover">
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