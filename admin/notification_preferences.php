<?php
$area = 'admin';
require __DIR__ . '/../includes/area_bootstrap.php';
$currentPage = 'notification-preferences';
$pageTitle = 'Notification Preferences';
$breadcrumbs = [['Admin', '/cargochina/admin/'], ['Notifications', '/cargochina/admin/notifications.php'], ['Preferences', '']];
require __DIR__ . '/../includes/area_layout.php';
?>
<link rel="stylesheet" href="/cargochina/frontend/css/style.css">
<div class="d-flex justify-content-between align-items-center mb-4">
  <h1 class="h3 mb-0">Notification Preferences</h1>
</div>
<p class="text-muted mb-3">Choose which channels to receive notifications for each event. Dashboard is always on.</p>
<div class="card">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-sm table-hover">
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
<?php
$pageScript = '/cargochina/frontend/js/notification_preferences.js';
require __DIR__ . '/../includes/area_footer.php';
?>