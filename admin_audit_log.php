<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['SuperAdmin', 'ChinaAdmin']);
$currentPage = 'admin_audit';
$pageTitle = 'Audit Log';
require 'includes/layout.php';
?>
<h1 class="mb-4">Audit Log</h1>
<p class="text-muted">Who did what, when. Filter by entity, user, or date.</p>

<div class="card mb-4">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">
      <div class="col-auto">
        <label class="form-label small mb-0">Entity type</label>
        <select class="form-select form-select-sm" id="filterEntityType" style="width:160px">
          <option value="">— All —</option>
          <option value="order">Order</option>
          <option value="shipment_draft">Shipment draft</option>
          <option value="user">User</option>
          <option value="system_config">System config</option>
          <option value="internal_message">Internal message</option>
          <option value="procurement_draft">Procurement draft</option>
          <option value="customer_portal_token">Customer portal token</option>
        </select>
      </div>
      <div class="col-auto">
        <label class="form-label small mb-0">Entity ID</label>
        <input type="number" class="form-control form-control-sm" id="filterEntityId" placeholder="ID" style="width:80px">
      </div>
      <div class="col-auto">
        <label class="form-label small mb-0">User</label>
        <select class="form-select form-select-sm" id="filterUserId" style="width:180px">
          <option value="">— All —</option>
        </select>
      </div>
      <div class="col-auto">
        <label class="form-label small mb-0">Action</label>
        <select class="form-select form-select-sm" id="filterAction" style="width:120px">
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
        <input type="date" class="form-control form-control-sm" id="filterDateFrom">
      </div>
      <div class="col-auto">
        <label class="form-label small mb-0">Date to</label>
        <input type="date" class="form-control form-control-sm" id="filterDateTo">
      </div>
      <div class="col-auto">
        <button type="button" class="btn btn-primary btn-sm" onclick="loadAuditLog()">Apply</button>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Time</th>
            <th>User</th>
            <th>Entity</th>
            <th>Action</th>
            <th>Details</th>
          </tr>
        </thead>
        <tbody id="auditBody"></tbody>
      </table>
    </div>
    <div id="auditEmpty" class="text-center py-5 text-muted d-none">No audit entries found.</div>
    <div class="p-2 text-end">
      <button type="button" class="btn btn-outline-secondary btn-sm" id="loadMoreBtn" onclick="loadMore()" style="display:none">Load more</button>
    </div>
  </div>
</div>
<?php $pageScript = '/cargochina/frontend/js/admin_audit_log.js?v=' . @filemtime(__DIR__ . '/frontend/js/admin_audit_log.js');
require 'includes/footer.php'; ?>