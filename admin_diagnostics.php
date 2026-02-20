<?php
$currentPage = 'admin';
$pageTitle = 'Diagnostics';
require 'includes/layout.php';
?>
<link rel="stylesheet" href="frontend/css/style.css">
<div class="col-12">
  <h1 class="mb-4">Diagnostics</h1>

  <div class="card mb-4">
    <div class="card-header">Config Health</div>
    <div class="card-body">
      <div id="configHealth" class="d-flex flex-wrap gap-3">
        <span class="badge bg-secondary">Loading...</span>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header">Notification Delivery Log</div>
    <div class="card-body">
      <div class="row mb-3 g-2">
        <div class="col-auto">
          <select class="form-select form-select-sm" id="filterStatus">
            <option value="">All statuses</option>
            <option value="sent">Sent</option>
            <option value="failed">Failed</option>
          </select>
        </div>
        <div class="col-auto">
          <select class="form-select form-select-sm" id="filterChannel">
            <option value="">All channels</option>
            <option value="email">Email</option>
            <option value="whatsapp">WhatsApp</option>
          </select>
        </div>
        <div class="col-auto">
          <input type="date" class="form-control form-control-sm" id="filterDateFrom" title="From date">
        </div>
        <div class="col-auto">
          <input type="date" class="form-control form-control-sm" id="filterDateTo" title="To date">
        </div>
        <div class="col-auto">
          <button type="button" class="btn btn-sm btn-primary" onclick="loadDeliveryLog()">Apply</button>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-sm">
          <thead>
            <tr>
              <th>ID</th>
              <th>Notification</th>
              <th>Channel</th>
              <th>Event</th>
              <th>Status</th>
              <th>Attempts</th>
              <th>Error</th>
              <th>Created</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="deliveryLogBody"></tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php $pageScript = 'frontend/js/admin_diagnostics.js';
require 'includes/footer.php'; ?>