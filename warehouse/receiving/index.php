<?php
$area = 'warehouse';
require __DIR__ . '/../../includes/area_bootstrap.php';
$currentPage = 'receiving-queue';
$pageTitle = 'Receiving';
$breadcrumbs = [['Warehouse', '/cargochina/warehouse/'], ['Receiving', '']];
require __DIR__ . '/../../includes/area_layout.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h1 class="h3 mb-0">Receiving</h1>
</div>

<ul class="nav nav-tabs mb-3" role="tablist">
  <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#queue-tab">Pending Queue</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#history-tab">History</a></li>
</ul>

<div class="tab-content">
  <div class="tab-pane fade show active" id="queue-tab">
    <div class="card mb-3">
      <div class="card-body py-2">
        <div class="row g-2 align-items-end">
          <div class="col-auto"><label class="form-label small mb-0">Order ID</label><input type="number" class="form-control form-control-sm" id="filterOrderId" placeholder="ID" style="width:80px"></div>
          <div class="col-auto"><label class="form-label small mb-0">Customer</label><input type="text" class="form-control form-control-sm" id="filterCustomer" placeholder="Search" style="width:120px"></div>
          <div class="col-auto"><label class="form-label small mb-0">Date from</label><input type="date" class="form-control form-control-sm" id="filterDateFrom"></div>
          <div class="col-auto"><label class="form-label small mb-0">Date to</label><input type="date" class="form-control form-control-sm" id="filterDateTo"></div>
          <div class="col-auto"><button type="button" class="btn btn-primary btn-sm" onclick="loadQueue()">Apply</button></div>
        </div>
      </div>
    </div>
    <div id="queueSkeleton" class="placeholder-glow d-none">
      <div class="placeholder col-12" style="height:120px"></div>
    </div>
    <div id="queueTable" class="card">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th>Order</th>
                <th>Customer</th>
                <th>Supplier</th>
                <th>Expected</th>
                <th>Declared</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="queueBody"></tbody>
          </table>
        </div>
        <div id="queueEmpty" class="text-center py-5 text-muted d-none">No orders pending receiving.</div>
      </div>
    </div>
  </div>

  <div class="tab-pane fade" id="history-tab">
    <div class="card mb-3">
      <div class="card-body py-2">
        <div class="row g-2 align-items-end">
          <div class="col-auto"><label class="form-label small mb-0">Order ID</label><input type="number" class="form-control form-control-sm" id="histOrderId" placeholder="ID" style="width:80px"></div>
          <div class="col-auto"><label class="form-label small mb-0">Date from</label><input type="date" class="form-control form-control-sm" id="histDateFrom"></div>
          <div class="col-auto"><label class="form-label small mb-0">Date to</label><input type="date" class="form-control form-control-sm" id="histDateTo"></div>
          <div class="col-auto"><button type="button" class="btn btn-primary btn-sm" onclick="loadHistory()">Apply</button></div>
        </div>
      </div>
    </div>
    <div id="historySkeleton" class="placeholder-glow d-none">
      <div class="placeholder col-12" style="height:80px"></div>
    </div>
    <div id="historyTable" class="card">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th>Receipt</th>
                <th>Order</th>
                <th>Customer</th>
                <th>Actual</th>
                <th>Received</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="historyBody"></tbody>
          </table>
        </div>
        <div id="historyEmpty" class="text-center py-5 text-muted d-none">No receipts found.</div>
      </div>
    </div>
  </div>
</div>
<?php $pageScript = '/cargochina/frontend/js/receiving_index.js';
require __DIR__ . '/../../includes/area_footer.php'; ?>