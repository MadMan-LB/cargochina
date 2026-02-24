<?php
$currentPage = 'admin';
$pageTitle = 'User Management';
require 'includes/layout.php';
?>
  <h1 class="mb-4">User Management</h1>
  <div class="card">
    <div class="card-body">
      <table class="table table-hover">
        <thead>
          <tr>
            <th>ID</th>
            <th>Email</th>
            <th>Name</th>
            <th>Roles</th>
            <th>Active</th>
          </tr>
        </thead>
        <tbody id="usersBody"></tbody>
      </table>
    </div>
  </div>
<?php $pageScript = 'frontend/js/admin_users.js';
require 'includes/footer.php'; ?>