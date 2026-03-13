<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin']);
$currentPage = 'assign_container';
$pageTitle = 'Assign to Container';
require 'includes/layout.php';
?>
<h1 class="mb-3">Assign to Container</h1>
<p class="text-muted mb-4">Assign ready orders to containers directly. Orders must be <strong>Approved</strong>, <strong>Confirmed</strong>, or <strong>Ready for Consolidation</strong>.</p>

<div class="row g-4">
  <!-- Left: eligible orders -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Eligible Orders <span class="text-muted fw-normal" id="eligibleCountLabel"></span></span>
        <div class="d-flex gap-2 align-items-center">
          <input type="text" id="orderSearch" class="form-control form-control-sm" style="width:180px" placeholder="Search customer…" oninput="debounceOrderSearch()">
          <button class="btn btn-outline-secondary btn-sm" onclick="selectAllOrders()" id="selectAllBtn">Select All</button>
        </div>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th class="text-center" style="width:2rem"><input type="checkbox" class="form-check-input" id="masterCheck" onchange="toggleAll(this)"></th>
                <th>ID</th>
                <th>Customer</th>
                <th>Supplier</th>
                <th>Status</th>
                <th class="text-end">CBM</th>
                <th class="text-end">Weight</th>
                <th class="text-end">Ready</th>
              </tr>
            </thead>
            <tbody id="eligibleOrdersTbody">
              <tr>
                <td colspan="8" class="text-center text-muted py-4">Loading…</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="card-footer py-2 bg-white border-top">
        <small class="text-muted">Selected: <strong id="selectedCountLabel">0</strong> orders —
          <strong id="selectedCbmLabel">0</strong> CBM,
          <strong id="selectedWeightLabel">0</strong> kg</small>
      </div>
    </div>
  </div>

  <!-- Right: container picker -->
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header fw-semibold">Select Container</div>
      <div class="card-body">
        <select id="targetContainerSelect" class="form-select mb-3" onchange="onContainerChange()">
          <option value="">— Choose a container —</option>
        </select>
        <div id="containerCapacityPanel" class="d-none">
          <div class="mb-2">
            <div class="d-flex justify-content-between small mb-1">
              <span class="text-muted fw-semibold">CBM Fill (current)</span>
              <span id="cbmCurrentLabel" class="text-muted"></span>
            </div>
            <div style="height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;">
              <div id="cbmCurrentBar" style="height:100%;border-radius:4px;transition:width .4s;"></div>
            </div>
          </div>
          <div class="mb-3">
            <div class="d-flex justify-content-between small mb-1">
              <span class="text-muted fw-semibold">Weight Fill (current)</span>
              <span id="wCurrentLabel" class="text-muted"></span>
            </div>
            <div style="height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;">
              <div id="wCurrentBar" style="height:100%;border-radius:4px;transition:width .4s;"></div>
            </div>
          </div>
          <div id="afterSelectionPanel" class="d-none">
            <hr class="my-2">
            <p class="small text-muted mb-1 fw-semibold">After adding selected orders:</p>
            <div class="mb-2">
              <div class="d-flex justify-content-between small mb-1">
                <span>CBM after</span><span id="cbmAfterLabel"></span>
              </div>
              <div style="height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;">
                <div id="cbmAfterBar" style="height:100%;border-radius:4px;transition:width .4s;"></div>
              </div>
            </div>
            <div>
              <div class="d-flex justify-content-between small mb-1">
                <span>Weight after</span><span id="wAfterLabel"></span>
              </div>
              <div style="height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;">
                <div id="wAfterBar" style="height:100%;border-radius:4px;transition:width .4s;"></div>
              </div>
            </div>
          </div>
          <div id="capacityWarning" class="alert alert-danger d-none mt-3 py-2 small"></div>
        </div>

        <button class="btn btn-primary w-100 mt-3" id="assignBtn" onclick="doAssign()" disabled>
          Assign Selected Orders to Container
        </button>
        <div id="assignResult" class="alert mt-2 d-none"></div>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-header fw-semibold">Containers Summary</div>
      <div class="card-body p-0">
        <div id="containerSummaryList" class="small">
          <div class="text-center text-muted py-3">Loading…</div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php $pageScript = '/cargochina/frontend/js/assign_container.js?v=' . filemtime(__DIR__ . '/frontend/js/assign_container.js'); ?>
<?php require 'includes/footer.php'; ?>