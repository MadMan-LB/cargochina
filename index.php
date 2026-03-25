<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'FieldStaff', 'SuperAdmin']);
$currentPage = 'dashboard';
$pageTitle = 'Dashboard';
require 'includes/layout.php';
?>
<h1 class="mb-1">Dashboard</h1>
<p class="text-muted mb-4 small">China Logistics Management System — Salameh Cargo</p>

<!-- Stale-order alert banner -->
<div id="staleAlert" class="alert alert-warning alert-dismissible d-none mb-3 py-2" role="alert">
  <strong>⚠️ Attention needed:</strong> <span id="staleAlertText"></span>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>

<!-- Key stats -->
<div class="row g-3 mb-4" id="dashboardStats">
  <div class="col-6 col-md-3">
    <div class="card border-0 h-100" style="background:linear-gradient(135deg,#eff6ff,#dbeafe);">
      <div class="card-body py-3">
        <div class="d-flex justify-content-between align-items-center">
          <span class="text-muted small">To receive</span>
          <span class="fs-3 fw-bold text-primary" id="statPendingReceiving">—</span>
        </div>
        <a href="receiving.php" class="stretched-link small text-primary fw-semibold">View queue →</a>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 h-100" style="background:linear-gradient(135deg,#fffbeb,#fef3c7);">
      <div class="card-body py-3">
        <div class="d-flex justify-content-between align-items-center">
          <span class="text-muted small">Customer follow-up</span>
          <span class="fs-3 fw-bold text-warning" id="statAwaitingConfirm">—</span>
        </div>
        <a href="orders.php?customer_feedback=pending" class="stretched-link small text-warning fw-semibold">View →</a>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 h-100" style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);">
      <div class="card-body py-3">
        <div class="d-flex justify-content-between align-items-center">
          <span class="text-muted small">Ready to consolidate</span>
          <span class="fs-3 fw-bold text-success" id="statReadyConsolidate">—</span>
        </div>
        <a href="consolidation.php" class="stretched-link small text-success fw-semibold">View →</a>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 h-100" style="background:linear-gradient(135deg,#f8fafc,#f1f5f9);">
      <div class="card-body py-3">
        <div class="d-flex justify-content-between align-items-center">
          <span class="text-muted small">Notifications</span>
          <span class="fs-3 fw-bold text-secondary" id="statUnreadNotif">—</span>
        </div>
        <a href="notifications.php" class="stretched-link small text-secondary fw-semibold">View →</a>
      </div>
    </div>
  </div>
</div>

<!-- My tasks -->
<div class="card mb-4 d-none border-0 shadow-sm" id="myTasksCard">
  <div class="card-header fw-semibold bg-transparent border-bottom">⚡ My tasks</div>
  <div class="card-body">
    <div class="row g-2" id="myTasksContainer"></div>
  </div>
</div>

<!-- Quick navigation -->
<div class="row g-3 mb-4">
  <?php if ($isBuyer): ?>
    <div class="col-6 col-md-3">
      <a href="orders.php" class="card text-decoration-none h-100 border-0 shadow-sm" style="background:#fff;">
        <div class="card-body py-3 d-flex align-items-center gap-3">
          <span style="font-size:1.8rem;">📋</span>
          <div>
            <div class="fw-semibold">Orders</div>
            <div class="small text-muted">Create &amp; manage</div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-6 col-md-3">
      <a href="suppliers.php" class="card text-decoration-none h-100 border-0 shadow-sm" style="background:#fff;">
        <div class="card-body py-3 d-flex align-items-center gap-3">
          <span style="font-size:1.8rem;">🏭</span>
          <div>
            <div class="fw-semibold">Suppliers</div>
            <div class="small text-muted">Master data</div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-6 col-md-3">
      <a href="customers.php" class="card text-decoration-none h-100 border-0 shadow-sm" style="background:#fff;">
        <div class="card-body py-3 d-flex align-items-center gap-3">
          <span style="font-size:1.8rem;">👥</span>
          <div>
            <div class="fw-semibold">Customers</div>
            <div class="small text-muted">Ledger &amp; deposits</div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-6 col-md-3">
      <a href="products.php" class="card text-decoration-none h-100 border-0 shadow-sm" style="background:#fff;">
        <div class="card-body py-3 d-flex align-items-center gap-3">
          <span style="font-size:1.8rem;">📦</span>
          <div>
            <div class="fw-semibold">Products</div>
            <div class="small text-muted">Catalog</div>
          </div>
        </div>
      </a>
    </div>
  <?php endif; ?>
  <?php if ($isWarehouse): ?>
    <div class="col-6 col-md-3">
      <a href="receiving.php" class="card text-decoration-none h-100 border-0 shadow-sm" style="background:#fff;">
        <div class="card-body py-3 d-flex align-items-center gap-3">
          <span style="font-size:1.8rem;">🏪</span>
          <div>
            <div class="fw-semibold">Receiving</div>
            <div class="small text-muted">Warehouse queue</div>
          </div>
        </div>
      </a>
    </div>
  <?php endif; ?>
  <?php if ($isAdmin): ?>
    <div class="col-6 col-md-3">
      <a href="consolidation.php" class="card text-decoration-none h-100 border-0 shadow-sm" style="background:#fff;">
        <div class="card-body py-3 d-flex align-items-center gap-3">
          <span style="font-size:1.8rem;">🚢</span>
          <div>
            <div class="fw-semibold">Consolidation</div>
            <div class="small text-muted">Shipment drafts</div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-6 col-md-3">
      <a href="pipeline.php" class="card text-decoration-none h-100 border-0 shadow-sm" style="background:#fff;">
        <div class="card-body py-3 d-flex align-items-center gap-3">
          <span style="font-size:1.8rem;">📊</span>
          <div>
            <div class="fw-semibold">Pipeline</div>
            <div class="small text-muted">Full status view</div>
          </div>
        </div>
      </a>
    </div>
  <?php endif; ?>
</div>
<?php $pageScript = 'frontend/js/dashboard.js';
require 'includes/footer.php'; ?>
