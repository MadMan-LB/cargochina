<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin']);
$currentPage = 'containers';
$pageTitle = 'Containers';
$isSuperAdmin = in_array('SuperAdmin', $_SESSION['user_roles'] ?? []);
require 'includes/layout.php';
?>
<h1 class="mb-3">Containers</h1>
<p class="text-muted mb-4">Monitor fill levels, container movement, and assignment pressure with cleaner multi-status filtering.</p>

<div class="metric-card-grid mb-4">
  <div class="metric-card">
    <div class="eyebrow">Visible Containers</div>
    <div class="value" id="containersTotalCount">0</div>
    <div class="detail">Containers matching the current filters</div>
  </div>
  <div class="metric-card">
    <div class="eyebrow">Planning</div>
    <div class="value" id="containersPlanningCount">0</div>
    <div class="detail">Still being prepared</div>
  </div>
  <div class="metric-card">
    <div class="eyebrow">High Utilization</div>
    <div class="value" id="containersHighLoadCount">0</div>
    <div class="detail">At or above 85% CBM fill</div>
  </div>
  <div class="metric-card">
    <div class="eyebrow">Assigned Orders</div>
    <div class="value" id="containersAssignedOrders">0</div>
    <div class="detail">Orders currently packed into visible containers</div>
  </div>
  <div class="metric-card">
    <div class="eyebrow">Launching Soon</div>
    <div class="value" id="containersLaunchingSoon">0</div>
    <div class="detail">Ship date within next 7 days</div>
  </div>
</div>

<!-- Search & Filter bar -->
<div class="card mb-3">
  <div class="card-body py-3">
    <div class="filter-toolbar-grid compact">
      <div class="filter-toolbar-card soft">
        <div class="filter-toolbar-head">
          <div>
            <div class="title">Search</div>
            <div class="filter-toolbar-subtext">Find by code, customer, phone, shipping code, or item description.</div>
          </div>
        </div>
        <input type="text" id="containerSearch" class="form-control form-control-sm" placeholder="Code, customer, phone, shipping code, item description…" autocomplete="off" oninput="debounceSearch()">
      </div>

      <div class="filter-toolbar-card">
        <div class="filter-toolbar-head">
          <div>
            <div class="title">Statuses</div>
            <div class="filter-toolbar-subtext">Use checkbox chips to include or exclude multiple container states.</div>
          </div>
          <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" onclick="clearContainerStatusFilter()">Clear</button>
        </div>
        <div class="filter-chip-grid" id="containerStatusFilterList">
          <?php foreach (
            [
              'planning' => 'Planning',
              'to_go' => 'To Go',
              'on_route' => 'On Route',
              'arrived' => 'Arrived',
              'available' => 'Available',
            ] as $statusValue => $statusLabel
          ): ?>
            <div class="form-check filter-chip">
              <input class="form-check-input container-status-filter" type="checkbox" value="<?= htmlspecialchars($statusValue) ?>" id="containerStatus<?= htmlspecialchars($statusValue) ?>" onchange="updateContainerStatusFilterSummary();loadContainers()">
              <label class="form-check-label" for="containerStatus<?= htmlspecialchars($statusValue) ?>"><?= htmlspecialchars($statusLabel) ?></label>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="filter-summary-row">
          <div class="d-flex gap-2 align-items-center flex-wrap">
            <select id="containerStatusMode" class="form-select form-select-sm" onchange="updateContainerStatusFilterSummary();loadContainers()">
              <option value="include">Include selected</option>
              <option value="exclude">Exclude selected</option>
            </select>
            <small class="summary-text" id="containerStatusSummary">All statuses</small>
          </div>
        </div>
      </div>

      <div class="filter-toolbar-card">
        <div class="filter-toolbar-head">
          <div>
            <div class="title">Capacity View</div>
            <div class="filter-toolbar-subtext">Quickly focus on empty, partial, or nearly full containers.</div>
          </div>
        </div>
        <select id="containerFillFilter" class="form-select form-select-sm" onchange="applyClientFilters()">
          <option value="">Any</option>
          <option value="empty">Empty (0%)</option>
          <option value="partial">Partial (1–84%)</option>
          <option value="almost">Almost Full (≥85%)</option>
          <option value="full">Full (100%)</option>
          <option value="launching_soon">Launching in 7 days</option>
        </select>
        <div class="filter-summary-row">
          <button class="btn btn-outline-secondary btn-sm" onclick="resetFilters()">Clear All Filters</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Container list -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span class="fw-semibold">Container List <span class="text-muted fw-normal" id="containerCountLabel"></span></span>
  </div>
  <div class="card-body p-0">
    <div id="containersTable" class="table-responsive">
      <table class="table table-hover table-striped table-sm align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>ID</th>
            <th>Code</th>
            <th>Status</th>
            <th>Ship Date</th>
            <th>Orders</th>
            <th>CBM Fill</th>
            <th>Weight Fill</th>
            <th>Max CBM</th>
            <th>Max Weight</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="containersTbody"></tbody>
      </table>
    </div>
  </div>
</div>

<!-- Container View Modal -->
<div class="modal fade" id="containerViewModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-fullscreen-lg-down">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title" id="containerViewTitle">Container</h5>
          <div id="containerViewSubtitle" class="text-muted small"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="containerViewBody">
        <div class="text-center py-5">
          <div class="spinner-border text-primary"></div>
        </div>
      </div>
      <div class="modal-footer">
        <a id="containerViewDownload" class="btn btn-outline-success" href="#" download>Download Excel</a>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Assign Orders to Container Modal -->
<div class="modal fade" id="assignOrdersModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title" id="assignOrdersTitle">Assign Orders</h5>
          <div id="assignOrdersSubtitle" class="text-muted small"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <!-- Capacity bars -->
        <div id="assignCapacityPanel" class="mb-3">
          <div class="d-flex justify-content-between small mb-1"><span class="text-muted fw-semibold">CBM Fill</span><span id="assignCbmLabel"></span></div>
          <div style="height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;" class="mb-2">
            <div id="assignCbmBar" style="height:100%;border-radius:4px;transition:width .3s;"></div>
          </div>
          <div class="d-flex justify-content-between small mb-1"><span class="text-muted fw-semibold">Weight Fill</span><span id="assignWLabel"></span></div>
          <div style="height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;">
            <div id="assignWBar" style="height:100%;border-radius:4px;transition:width .3s;"></div>
          </div>
        </div>
        <div id="assignCapacityWarning" class="alert alert-danger py-2 small d-none"></div>
        <!-- Order list -->
        <div class="table-responsive" style="max-height:340px;overflow-y:auto;">
          <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light sticky-top">
              <tr>
                <th><input type="checkbox" class="form-check-input" id="assignMasterCb" onchange="toggleAssignAll(this)"></th>
                <th>ID</th>
                <th>Customer</th>
                <th>Supplier</th>
                <th class="text-end">CBM</th>
                <th class="text-end">Weight</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody id="assignOrdersTbody">
              <tr>
                <td colspan="7" class="text-center text-muted py-3">Loading…</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer justify-content-between">
        <small class="text-muted">Selected: <strong id="assignSelCount">0</strong> orders</small>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" id="assignConfirmBtn" onclick="confirmAssignOrders()" disabled>Assign to Container</button>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- Container Edit Modal (code, capacity, dates, vessel, destination) -->
<div class="modal fade" id="containerEditModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title">Edit Container</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="containerEditId">
        <div class="row g-3">
          <div class="col-12 col-md-4">
            <label class="form-label small">Code</label>
            <input type="text" class="form-control form-control-sm" id="containerEditCode" placeholder="e.g. FULLQA-CTR-001" maxlength="50">
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label small">Max CBM</label>
            <input type="number" step="0.01" min="0" class="form-control form-control-sm" id="containerEditMaxCbm" placeholder="67.7">
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label small">Max Weight (kg)</label>
            <input type="number" step="0.01" min="0" class="form-control form-control-sm" id="containerEditMaxWeight" placeholder="26800">
          </div>
          <div class="col-12"><hr class="my-2"></div>
          <div class="col-12 col-md-6">
            <label class="form-label small">Expected Ship Date</label>
            <input type="date" class="form-control form-control-sm" id="containerEditShipDate" placeholder="When to load onto cargo ship">
            <small class="text-muted">Planned launch / upload to ship</small>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label small">ETA (Arrival)</label>
            <input type="date" class="form-control form-control-sm" id="containerEditEta" placeholder="Expected arrival">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label small">Actual Departure</label>
            <input type="date" class="form-control form-control-sm" id="containerEditActualDep" placeholder="When it left">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label small">Actual Arrival</label>
            <input type="date" class="form-control form-control-sm" id="containerEditActualArr" placeholder="When it arrived">
          </div>
          <div class="col-12">
            <label class="form-label small">Vessel / Ship Name</label>
            <input type="text" class="form-control form-control-sm" id="containerEditVessel" placeholder="e.g. COSCO SHIPPING">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label small">Destination Country</label>
            <input type="text" class="form-control form-control-sm" id="containerEditDestCountry" placeholder="e.g. Lebanon">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label small">Destination (Port/City)</label>
            <input type="text" class="form-control form-control-sm" id="containerEditDest" placeholder="e.g. Beirut Port">
          </div>
          <div class="col-12">
            <label class="form-label small">Notes</label>
            <textarea class="form-control form-control-sm" id="containerEditNotes" rows="2" placeholder="Internal notes"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="containerEditSaveBtn" onclick="saveContainerEdit()">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- Status Change Modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title">Change Status</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="statusModalContainerId">
        <div class="d-grid gap-2">
          <button class="btn btn-outline-secondary btn-sm" onclick="setContainerStatus('planning')">Planning</button>
          <button class="btn btn-outline-warning btn-sm" onclick="setContainerStatus('to_go')">To Go</button>
          <button class="btn btn-outline-primary btn-sm" onclick="setContainerStatus('on_route')">On Route</button>
          <button class="btn btn-outline-success btn-sm" onclick="setContainerStatus('arrived')">Arrived</button>
          <button class="btn btn-outline-secondary btn-sm" onclick="setContainerStatus('available')">Available</button>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
$pageScripts = ['/cargochina/frontend/js/autocomplete.js?v=' . filemtime(__DIR__ . '/frontend/js/autocomplete.js')];
$pageScript = '/cargochina/frontend/js/containers.js?v=' . filemtime(__DIR__ . '/frontend/js/containers.js');
?>
<?php require 'includes/footer.php'; ?>