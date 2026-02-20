<?php
$currentPage = 'notification_preferences';
$pageTitle = 'Notification Preferences';
require 'includes/layout.php';
?>
<link rel="stylesheet" href="frontend/css/style.css">
<div class="col-12">
  <h1 class="mb-4">Notification Preferences</h1>
  <p class="text-muted">Choose which channels to receive notifications for each event. Dashboard is always on.</p>
  <div class="card">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm">
          <thead>
            <tr>
              <th>Event</th>
              <th>Dashboard</th>
              <th>Email</th>
              <th>WhatsApp</th>
            </tr>
          </thead>
          <tbody id="prefsBody"></tbody>
        </table>
      </div>
      <button type="button" class="btn btn-primary" id="savePrefsBtn" onclick="saveNotificationPreferences()">Save</button>
    </div>
  </div>
</div>
<?php $pageScript = 'frontend/js/notification_preferences.js';
require 'includes/footer.php'; ?>