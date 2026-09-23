<?php
require 'includes/auth_check.php';
require 'includes/page_guard.php';
requireRoleForPage(['ChinaAdmin','LebanonAdmin','SuperAdmin']);
if (!array_intersect($_SESSION['user_roles']??[],['ChinaAdmin','LebanonAdmin','SuperAdmin'])) {include '403.php';exit;}
$currentPage='recycle_bin';$pageTitle='Recycle Bin';require 'includes/layout.php';
?>
<h1 class="mb-3">Recycle Bin</h1>
<p class="text-muted">Recover deleted procurement and shipment drafts. Restored shipment drafts are empty and unassigned; load eligible orders again through the normal workflow. Business retention and legal holds still apply.</p>
<form id="recycleFilters" class="card card-body mb-3">
 <div class="row g-2">
  <div class="col-md-3"><label for="recycleSearch" class="form-label">Reference / reason</label><input id="recycleSearch" name="q" maxlength="150" class="form-control" type="search"></div>
  <div class="col-md-3"><label for="recycleType" class="form-label">Record type</label><select id="recycleType" name="type" class="form-select"><option value="">All permitted types</option value="procurement_draft">Procurement draft</option><option value="shipment_draft">Shipment draft</option></select></div>
  <div class="col-md-2"><label for="recycleActor" class="form-label">Deleted by</label><select id="recycleActor" name="deleted_by" class="form-select"><option value="">All users</option></select></div>
  <div class="col-md-2"><label for="recycleFrom" class="form-label">Deleted from</label><input id="recycleFrom" name="from" type="date" class="form-control"></div>
  <div class="col-md-2"><label for="recycleTo" class="form-label">Deleted through</label><input id="recycleTo" name="to" type="date" class="form-control"></div>
 </div><div class="mt-3"><button class="btn btn-primary" type="submit">Search</button> <button class="btn btn-outline-secondary" type="reset">Clear</button></div>
</form>
<div id="recycleNotice" role="status" aria-live="polite"></div>
<div class="card"><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Type / reference</th><th>Deleted by</th><th>Deleted at</th><th>Reason</th><th>Original status</th><th>Actions</th></tr></thead><tbody id="recycleRows"></tbody></table></div></div>
<div class="d-flex justify-content-between align-items-center mt-3"><span id="recycleCount"></span><div><button id="recyclePrev" class="btn btn-outline-secondary">Previous</button> <button id="recycleNext" class="btn btn-outline-secondary">Next</button></div></div>
<?php $pageScript='frontend/js/recycle-bin.js?v='.filemtime(__DIR__.'/frontend/js/recycle-bin.js');require 'includes/footer.php'; ?>
