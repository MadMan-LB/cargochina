<?php
$currentPage = 'dashboard';
$pageTitle = 'Dashboard';
require 'includes/layout.php';
?>
<h1 class="mb-4">CLMS Dashboard</h1>
<p class="lead">China Logistics Management System — Salameh Cargo</p>

<div class="row g-3 mb-4" id="dashboardStats">
  <div class="col-6 col-md-3">
    <div class="card border-0 bg-primary bg-opacity-10">
      <div class="card-body py-3">
        <div class="d-flex justify-content-between align-items-center"><span class="text-muted small">To receive</span><span class="fs-4 fw-bold" id="statPendingReceiving">—</span></div><a href="receiving.php" class="stretched-link small text-primary">View queue →</a>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 bg-warning bg-opacity-10">
      <div class="card-body py-3">
        <div class="d-flex justify-content-between align-items-center"><span class="text-muted small">Awaiting confirm</span><span class="fs-4 fw-bold" id="statAwaitingConfirm">—</span></div><a href="orders.php?status=AwaitingCustomerConfirmation" class="stretched-link small text-warning">View →</a>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 bg-success bg-opacity-10">
      <div class="card-body py-3">
        <div class="d-flex justify-content-between align-items-center"><span class="text-muted small">Ready to consolidate</span><span class="fs-4 fw-bold" id="statReadyConsolidate">—</span></div><a href="consolidation.php" class="stretched-link small text-success">View →</a>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 bg-secondary bg-opacity-10">
      <div class="card-body py-3">
        <div class="d-flex justify-content-between align-items-center"><span class="text-muted small">Unread notifications</span><span class="fs-4 fw-bold" id="statUnreadNotif">—</span></div><a href="notifications.php" class="stretched-link small text-secondary">View →</a>
      </div>
    </div>
  </div>
</div>

<div class="card mb-4 d-none" id="myTasksCard">
  <div class="card-header">My tasks</div>
  <div class="card-body">
    <div class="row g-2" id="myTasksContainer"></div>
  </div>
</div>

<div class="row g-4 mt-2">
  <?php if ($isBuyer): ?>
    <div class="col-md-4">
      <div class="card">
        <div class="card-body">
          <h5 class="card-title">Master Data</h5>
          <p class="card-text">Manage customers, suppliers, and products.</p>
          <a href="customers.php" class="btn btn-primary">Go to Master Data</a>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card">
        <div class="card-body">
          <h5 class="card-title">Orders</h5>
          <p class="card-text">Create and manage orders with items.</p>
          <a href="orders.php" class="btn btn-primary">Go to Orders</a>
        </div>
      </div>
    </div>
  <?php endif; ?>
  <?php if ($isWarehouse): ?>
    <div class="col-md-4">
      <div class="card">
        <div class="card-body">
          <h5 class="card-title">Warehouse Receiving</h5>
          <p class="card-text">Record actual CBM, weight, and evidence.</p>
          <a href="receiving.php" class="btn btn-primary">Go to Receiving</a>
        </div>
      </div>
    </div>
  <?php endif; ?>
  <?php if ($isAdmin): ?>
    <div class="col-md-4">
      <div class="card">
        <div class="card-body">
          <h5 class="card-title">Consolidation</h5>
          <p class="card-text">Shipment drafts and container assignment.</p>
          <a href="consolidation.php" class="btn btn-primary">Go to Consolidation</a>
        </div>
      </div>
    </div>
  <?php endif; ?>
  <div class="col-md-4">
    <div class="card">
      <div class="card-body">
        <h5 class="card-title">Notifications</h5>
        <p class="card-text">View and manage your notifications.</p>
        <a href="notifications.php" class="btn btn-outline-primary">View Notifications</a>
      </div>
    </div>
  </div>
</div>
<?php $pageScript = 'frontend/js/dashboard.js';
require 'includes/footer.php'; ?>