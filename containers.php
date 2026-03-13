<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin']);
$currentPage = 'containers';
$pageTitle = 'Containers';
require 'includes/layout.php';
?>
<h1 class="mb-4">Containers</h1>
<p class="text-muted mb-4">View and download all orders in a container. Each download is a single Excel file with orders separated by <code>##</code>, customer name, and phone.</p>
<div class="card">
  <div class="card-header">Container List</div>
  <div class="card-body">
    <div id="containersTable" class="table-responsive">
      <table class="table table-hover table-striped table-sm align-middle">
        <thead>
          <tr>
            <th>ID</th>
            <th>Code</th>
            <th>Max CBM</th>
            <th>Max Weight (kg)</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>

<!-- Container View Modal -->
<div class="modal fade" id="containerViewModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-fullscreen-lg-down">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="containerViewTitle">Container Orders</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="containerViewBody">
        <div class="text-center py-5">
          <div class="spinner-border text-primary"></div>
        </div>
      </div>
      <div class="modal-footer">
        <a id="containerViewDownload" class="btn btn-outline-success" href="#" download>Download Excel</a>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<?php $pageScript = '/cargochina/frontend/js/containers.js?v=' . filemtime(__DIR__ . '/frontend/js/containers.js'); ?>
<?php require 'includes/footer.php'; ?>