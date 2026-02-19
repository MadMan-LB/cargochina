<?php
$currentPage = 'admin';
$pageTitle = 'Configuration';
require 'includes/layout.php';
?>
<link rel="stylesheet" href="frontend/css/style.css">
<div class="col-12">
  <h1 class="mb-4">System Configuration</h1>
  <div class="card">
    <div class="card-body">
      <form id="configForm">
        <div class="row mb-3">
          <div class="col-md-6"><label class="form-label">Variance Threshold %</label><input type="number" step="0.1" class="form-control" id="variancePct"></div>
          <div class="col-md-6"><label class="form-label">Variance Threshold Abs CBM</label><input type="number" step="0.01" class="form-control" id="varianceAbs"></div>
        </div>
        <div class="row mb-3">
          <div class="col-md-6"><label class="form-label">Confirmation Required</label><select class="form-select" id="confirmationRequired">
              <option value="variance-only">Variance only</option>
              <option value="always-on-arrival">Always on arrival</option>
            </select></div>
          <div class="col-md-6"><label class="form-label">Customer Photo Visibility</label><select class="form-select" id="photoVisibility">
              <option value="internal-only">Internal only</option>
              <option value="customer-visible">Customer visible</option>
            </select></div>
        </div>
        <button type="button" class="btn btn-primary" onclick="saveConfig()">Save</button>
      </form>
    </div>
  </div>
</div>
<?php $pageScript = 'frontend/js/admin_config.js';
require 'includes/footer.php'; ?>