<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin']);
$currentPage = 'pipeline';
$pageTitle = 'Pipeline';
require 'includes/layout.php';
?>
<h1 class="mb-4">Order Pipeline</h1>
<p class="text-muted">End-to-end view: order → receive → confirm → consolidate → ship → track</p>

<div class="row g-3 mb-4" id="pipelineStages">
  <div class="col-6 col-md-4 col-lg-2">
    <a href="orders.php?status=Draft" class="card text-decoration-none border-0 bg-light text-dark">
      <div class="card-body py-3 text-center">
        <div class="fs-4 fw-bold" id="pipeDraft">—</div>
        <small class="text-muted">Draft</small>
      </div>
    </a>
  </div>
  <div class="col-6 col-md-4 col-lg-2">
    <a href="orders.php?status=Submitted" class="card text-decoration-none border-0 bg-light text-dark">
      <div class="card-body py-3 text-center">
        <div class="fs-4 fw-bold" id="pipeSubmitted">—</div>
        <small class="text-muted">Submitted</small>
      </div>
    </a>
  </div>
  <div class="col-6 col-md-4 col-lg-2">
    <a href="receiving.php" class="card text-decoration-none border-0 bg-primary bg-opacity-10 text-primary">
      <div class="card-body py-3 text-center">
        <div class="fs-4 fw-bold" id="pipeToReceive">—</div>
        <small class="text-muted">To receive</small>
      </div>
    </a>
  </div>
  <div class="col-6 col-md-4 col-lg-2">
    <a href="orders.php?status=AwaitingCustomerConfirmation" class="card text-decoration-none border-0 bg-warning bg-opacity-10 text-dark">
      <div class="card-body py-3 text-center">
        <div class="fs-4 fw-bold" id="pipeAwaitConfirm">—</div>
        <small class="text-muted">Await confirm</small>
      </div>
    </a>
  </div>
  <div class="col-6 col-md-4 col-lg-2">
    <a href="consolidation.php" class="card text-decoration-none border-0 bg-success bg-opacity-10 text-success">
      <div class="card-body py-3 text-center">
        <div class="fs-4 fw-bold" id="pipeReady">—</div>
        <small class="text-muted">Ready</small>
      </div>
    </a>
  </div>
  <div class="col-6 col-md-4 col-lg-2">
    <a href="consolidation.php" class="card text-decoration-none border-0 bg-secondary bg-opacity-10 text-secondary">
      <div class="card-body py-3 text-center">
        <div class="fs-4 fw-bold" id="pipeFinalized">—</div>
        <small class="text-muted">Finalized</small>
      </div>
    </a>
  </div>
</div>

<div class="card">
  <div class="card-header">Stage summary</div>
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