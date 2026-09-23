<?php
require 'includes/auth_check.php';require 'includes/page_guard.php';
requireRoleForPage(['ChinaAdmin','LebanonAdmin','SuperAdmin','WarehouseStaff']);
$currentPage='calendar';$pageTitle='Operational Calendar';require 'includes/layout.php';
?>
<h1 class="mb-2">Operational Calendar</h1>
<p class="text-muted">Expected dates and recorded cargo events. Receiving puts cargo into warehouse stock; container assignment is a reservation, not physical loading.</p>
<form id="calendarFilters" class="card card-body mb-3"><div class="row g-2">
<div class="col-md-2"><label class="form-label" for="calendarDate">Date</label><input type="date" id="calendarDate" class="form-control" required></div>
<div class="col-md-2"><label class="form-label" for="calendarView">View</label><select id="calendarView" class="form-select"><option value="month">Month</option><option value="week">Week</option><option value="day">Day</option><option value="timeline">Timeline (month)</option></select></div>
<div class="col-md-3"><label class="form-label" for="calendarEvent">Event type</label><select id="calendarEvent" name="event_type" class="form-select"><option value="">All events</option></select></div>
<div class="col-md-3"><label class="form-label" for="calendarCustomer">Customer name / ID</label><input id="calendarCustomer" name="customer" type="search" maxlength="120" class="form-control"></div>
<div class="col-md-2"><label class="form-label" for="calendarOrder">Order number</label><input id="calendarOrder" name="order" type="search" maxlength="120" class="form-control"></div>
<div class="col-md-3"><label class="form-label" for="calendarContainer">Container code / ID</label><input id="calendarContainer" name="container" type="search" maxlength="120" class="form-control"></div>
<div class="col-md-3"><label class="form-label" for="calendarStatus">Current status</label><input id="calendarStatus" name="status" maxlength="120" class="form-control" placeholder="e.g. Confirmed or planning"></div>
<div class="col-md-6 d-flex align-items-end gap-2"><button type="submit" class="btn btn-primary">Apply filters</button><button type="reset" class="btn btn-outline-secondary">Clear filters</button></div>
</div></form>
<div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><div><button class="btn btn-outline-secondary" id="calendarPrevBtn">Previous</button> <button class="btn btn-outline-secondary" id="calendarTodayBtn">Today</button> <button class="btn btn-outline-secondary" id="calendarNextBtn">Next</button></div><strong id="calendarRange"></strong><span id="calendarCount" role="status" aria-live="polite"></span></div>
<div id="calendarLegend" class="d-flex flex-wrap gap-2 small mb-3" aria-label="Event legend"></div><div id="calendarError" role="alert"></div>
<div id="calendarGrid" class="calendar-board"></div>
<div class="modal fade" id="calendarEventModal" tabindex="-1" aria-labelledby="calendarEventTitle" aria-hidden="true"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 id="calendarEventTitle" class="modal-title">Cargo event</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div id="calendarEventDetail" class="modal-body"></div><div class="modal-footer"><a id="calendarEventLink" class="btn btn-primary">Open record</a><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div></div></div></div>
<?php $pageScript='frontend/js/calendar.js?v='.filemtime(__DIR__.'/frontend/js/calendar.js');require 'includes/footer.php'; ?>
