<?php
$currentPage = 'admin';
$pageTitle = 'Tracking Push Log';
require 'includes/layout.php';
?>
<link rel="stylesheet" href="frontend/css/style.css">
<div class="col-12">
  <h1 class="mb-4">Tracking Push Log</h1>
  <div class="card mb-3">
    <div class="card-body py-2">
      <label class="form-check-label me-2"><input type="checkbox" id="filterFailed" onchange="loadPushLog()"> Failed only</label>
    </div>
  </div>
  <div class="card">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm">
          <thead>
            <tr>
              <th>ID</th>
              <th>Draft</th>
              <th>Status</th>
              <th>Response</th>
              <th>Attempts</th>
              <th>Error</th>
              <th>Updated</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="pushLogBody"></tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<div class="modal fade" id="errorModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Error Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <pre id="errorModalBody" class="small"></pre>
      </div>
    </div>
  </div>
</div>
<?php $pageScript = 'frontend/js/admin_tracking_push.js';
require 'includes/footer.php'; ?>