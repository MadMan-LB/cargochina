<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin', 'WarehouseStaff']);
$currentPage = 'calendar';
$pageTitle = 'Calendar / Timeline';
require 'includes/layout.php';
?>
<h1 class="mb-4">Calendar / Timeline</h1>
<p class="text-muted mb-4">Switch between a monthly calendar and a detailed timeline for expected-ready orders and container ETA activity.</p>

<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span>Planner</span>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <button class="btn btn-sm btn-outline-secondary" type="button" id="calendarPrevBtn">←</button>
      <input type="month" class="form-control form-control-sm" id="calendarMonth" style="width:160px">
      <button class="btn btn-sm btn-outline-secondary" type="button" id="calendarNextBtn">→</button>
      <button class="btn btn-sm btn-outline-primary" type="button" id="calendarRefreshBtn">Refresh</button>
    </div>
  </div>
  <div class="card-body">
    <ul class="nav nav-tabs mb-3" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="calendar-grid-tab" data-bs-toggle="tab" data-bs-target="#calendar-grid-pane" type="button">Calendar View</button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="calendar-timeline-tab" data-bs-toggle="tab" data-bs-target="#calendar-timeline-pane" type="button">Timeline View</button>
      </li>
    </ul>

    <div class="tab-content">
      <div class="tab-pane fade show active" id="calendar-grid-pane">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <h6 class="mb-1">Monthly Calendar</h6>
            <small class="text-muted">Orders show expected ready dates. Containers show ETA dates.</small>
          </div>
          <div class="small text-muted" id="calendarMonthLabel">—</div>
        </div>
        <div id="calendarGrid" class="calendar-board"></div>
      </div>

      <div class="tab-pane fade" id="calendar-timeline-pane">
        <div class="row g-4">
          <div class="col-lg-7">
            <h6>Orders (Expected Ready)</h6>
            <div id="ordersTimeline" class="table-responsive"></div>
          </div>
          <div class="col-lg-5">
            <h6>Containers (ETA)</h6>
            <div id="containersTimeline" class="table-responsive"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php $pageScript = 'frontend/js/calendar.js';
require 'includes/footer.php'; ?>
