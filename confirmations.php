<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['ChinaAdmin', 'LebanonAdmin', 'WarehouseStaff', 'SuperAdmin']);
$currentPage = 'confirmations';
$pageTitle = 'Order Confirmations';
require 'includes/layout.php';
?>
<div class="card page-hero-card mb-4">
  <div class="card-body">
    <h1 class="mb-2">Order Confirmations</h1>
    <p class="text-muted mb-0">This page is now retired from the live workflow. Receiving auto-confirms warehouse receipts into stock, then customers can still review the actuals through the portal link afterward.</p>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header fw-semibold">What Changed</div>
      <div class="card-body">
        <div class="stack-card-list">
          <div class="border rounded-3 px-3 py-3 bg-white">
            <div class="fw-semibold small mb-1">Receiving now auto-confirms into stock</div>
            <div class="text-muted small">Damaged or variance receipts are still stored, but they no longer wait in a separate internal confirmation queue.</div>
          </div>
          <div class="border rounded-3 px-3 py-3 bg-white">
            <div class="fw-semibold small mb-1">Customer follow-up happens from Orders and the portal link</div>
            <div class="text-muted small">Use the Orders page to monitor pending customer feedback, declined-after-auto-confirm cases, and reset actions back to Submitted when needed.</div>
          </div>
          <div class="border rounded-3 px-3 py-3 bg-white">
            <div class="fw-semibold small mb-1">Warehouse evidence is preserved</div>
            <div class="text-muted small">Receipt history and photos remain available for audit. Resetting after a decline only voids the operational stock effect; it does not silently erase the record.</div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header fw-semibold">Use These Queues Instead</div>
      <div class="card-body d-grid gap-3">
        <a class="btn btn-outline-primary text-start" href="/cargochina/orders.php?customer_feedback=pending">
          <strong class="d-block">Orders → Customer Feedback Pending</strong>
          <span class="small text-muted">See auto-confirmed receipts that still need customer review.</span>
        </a>
        <a class="btn btn-outline-danger text-start" href="/cargochina/orders.php?customer_feedback=declined_after_auto_confirm">
          <strong class="d-block">Orders → Declined After Auto-Confirm</strong>
          <span class="small text-muted">Handle customer refusals, keep them visible in red, and reset them back to Submitted when approved internally.</span>
        </a>
        <a class="btn btn-outline-secondary text-start" href="/cargochina/receiving.php">
          <strong class="d-block">Receiving</strong>
          <span class="small text-muted">Record actual warehouse measurements, photos, and damage evidence.</span>
        </a>
        <a class="btn btn-outline-secondary text-start" href="/cargochina/warehouse_stock.php">
          <strong class="d-block">Warehouse Stock</strong>
          <span class="small text-muted">Review what is still operationally in stock after receiving.</span>
        </a>
      </div>
    </div>
  </div>
</div>
<?php require 'includes/footer.php'; ?>
