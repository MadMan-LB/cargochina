<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin']);
$currentPage = 'pipeline';
$pageTitle = 'Pipeline';
require 'includes/layout.php';
?>
<h1 class="mb-4">Order Pipeline</h1>
<p class="text-muted mb-4">A clearer operations board for approval, receiving, customer confirmation, consolidation, and final dispatch.</p>

<div class="card page-hero-card mb-4">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
      <div>
        <div class="text-uppercase text-muted small fw-semibold mb-2">Operations Snapshot</div>
        <h2 class="h4 mb-2">See where work is piling up before it becomes a delay.</h2>
        <p class="text-muted mb-0">The cards below focus on actionable stages, stalled confirmations, and next shipment movement.</p>
      </div>
      <a href="/cargochina/calendar.php" class="btn btn-outline-primary btn-sm">Open Calendar / Timeline</a>
    </div>
  </div>
</div>

<div class="metric-card-grid mb-4">
  <div class="metric-card">
    <div class="eyebrow">Draft</div>
    <div class="value" id="pipeDraft">0</div>
    <div class="detail">Orders not yet submitted</div>
  </div>
  <div class="metric-card">
    <div class="eyebrow">Pending Receiving</div>
    <div class="value" id="pipeToReceive">0</div>
    <div class="detail">Approved or in-transit orders waiting for warehouse action</div>
  </div>
  <div class="metric-card">
    <div class="eyebrow">Awaiting Confirmation</div>
    <div class="value" id="pipeAwaitConfirm">0</div>
    <div class="detail">Orders blocked on customer confirmation</div>
  </div>
  <div class="metric-card">
    <div class="eyebrow">Finalized</div>
    <div class="value" id="pipeFinalized">0</div>
    <div class="detail">Orders fully pushed through the workflow</div>
  </div>
</div>

<div class="balanced-panels mb-4">
  <div class="card">
    <div class="card-header">Stage Board</div>
    <div class="card-body">
      <div id="pipelineStageBoard" class="pipeline-stage-grid">
        <div class="text-muted">Loading pipeline stages…</div>
      </div>
    </div>
  </div>

  <div class="stack-card-list">
    <div class="card">
      <div class="card-header">My Focus</div>
      <div class="card-body" id="pipelineTasksList">
        <div class="text-muted">Loading tasks…</div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Exceptions & Shipping</div>
      <div class="card-body">
        <div class="mb-3">
          <div class="small text-uppercase text-muted fw-semibold mb-1">Stale Items</div>
          <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
            <span class="small">Awaiting customer confirmation</span>
            <strong id="pipelineStaleConfirm">0</strong>
          </div>
          <div class="d-flex justify-content-between align-items-center py-2">
            <span class="small">Overdue approved / in transit</span>
            <strong id="pipelineStaleOverdue">0</strong>
          </div>
        </div>
        <div>
          <div class="small text-uppercase text-muted fw-semibold mb-2">Shipment Progress</div>
          <div id="pipelineShipSummary" class="small text-muted">Loading shipping view…</div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header">Stage Summary</div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead>
          <tr>
            <th>Stage</th>
            <th>Count</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody id="pipelineTableBody"></tbody>
      </table>
    </div>
  </div>
</div>
<?php $pageScript = 'frontend/js/pipeline.js';
require 'includes/footer.php'; ?>
