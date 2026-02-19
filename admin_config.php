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
        <div class="row mb-3">
          <div class="col-md-6"><label class="form-label">Min Photos per Item (for submit)</label><input type="number" min="0" class="form-control" id="minPhotosPerItem" placeholder="Set 0 to disable"></div>
          <div class="col-md-6"><label class="form-label">Notification Channels</label><input type="text" class="form-control" id="notificationChannels" placeholder="dashboard,email,whatsapp"></div>
        </div>
        <hr>
        <h5 class="mb-3">Tracking Integration (Phase 3)</h5>
        <div class="row mb-3">
          <div class="col-12"><label class="form-label">API Base URL</label><input type="url" class="form-control" id="trackingApiBaseUrl" placeholder="https://tracking.example.com"></div>
        </div>
        <div class="row mb-3">
          <div class="col-md-6"><label class="form-label">API Token</label><input type="password" class="form-control" id="trackingApiToken" placeholder="Leave blank to keep current" autocomplete="new-password"></div>
          <div class="col-md-6"><label class="form-label">API Path</label><input type="text" class="form-control" id="trackingApiPath" placeholder="/api/import/clms"></div>
        </div>
        <div class="row mb-3">
          <div class="col-md-4"><label class="form-label">Timeout (sec)</label><input type="number" min="1" class="form-control" id="trackingApiTimeout"></div>
          <div class="col-md-4"><label class="form-label">Retry Count</label><input type="number" min="0" class="form-control" id="trackingApiRetryCount"></div>
          <div class="col-md-4"><label class="form-label">Retry Backoff (ms)</label><input type="number" min="0" class="form-control" id="trackingApiRetryBackoff"></div>
        </div>
        <div class="row mb-3">
          <div class="col-md-6"><label class="form-label">Push Enabled</label><select class="form-select" id="trackingPushEnabled">
              <option value="0">No</option>
              <option value="1">Yes</option>
            </select></div>
          <div class="col-md-6"><label class="form-label">Dry Run</label><select class="form-select" id="trackingPushDryRun">
              <option value="1">Yes (log only)</option>
              <option value="0">No (real push)</option>
            </select></div>
        </div>
        <button type="button" class="btn btn-primary" onclick="saveConfig()">Save</button>
      </form>
    </div>
  </div>
</div>
<?php $pageScript = 'frontend/js/admin_config.js';
require 'includes/footer.php'; ?>