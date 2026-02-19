<?php
$currentPage = 'dashboard';
$pageTitle = 'Dashboard';
require 'includes/layout.php';
?>
<link rel="stylesheet" href="frontend/css/style.css">
<div class="col-12">
  <h1 class="mb-4">CLMS Dashboard</h1>
  <p class="lead">China Logistics Management System — Salameh Cargo</p>
  <div class="row g-4 mt-2">
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
    <div class="col-md-4">
      <div class="card">
        <div class="card-body">
          <h5 class="card-title">Warehouse Receiving</h5>
          <p class="card-text">Record actual CBM, weight, and evidence.</p>
          <a href="receiving.php" class="btn btn-primary">Go to Receiving</a>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require 'includes/footer.php'; ?>