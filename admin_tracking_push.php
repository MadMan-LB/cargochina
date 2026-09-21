<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['LebanonAdmin', 'SuperAdmin']);
$currentPage = 'admin_tracking';
$pageTitle = 'Tracking Push Log';
require 'includes/layout.php';
?>
<h1 class="mb-4">Tracking Push Log</h1>
<div class="card mb-3">
  <div class="card-body py-2">
    <label class="form-check-label me-2"><input type="checkbox" id="filterFailed" onchange="loadPushLog()"> Failed only</label>
    <label class="form-label mb-0">Draft ID <input type="number" min="1" id="filterDraft" class="form-control form-control-sm" onchange="loadPushLog()"></label>
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
    <div class="d-flex gap-2 align-items-center"><button class="btn btn-sm btn-outline-secondary" id="pushLogPrevious" onclick="changePushLogPage(-1)">Previous</button><span id="pushLogPage"></span><button class="btn btn-sm btn-outline-secondary" id="pushLogNext" onclick="changePushLogPage(1)">Next</button></div>
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
<?php $pageScript = 'frontend/js/admin_tracking_push.js?v=' . filemtime(__DIR__ . '/frontend/js/admin_tracking_push.js');
require 'includes/footer.php'; ?>
