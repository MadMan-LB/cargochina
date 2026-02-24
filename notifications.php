<?php
$currentPage = 'notifications';
$pageTitle = 'Notifications';
require 'includes/layout.php';
?>
  <h1 class="mb-4">Notifications</h1>
  <div class="card">
    <div class="card-body">
      <div id="notificationsList"></div>
    </div>
  </div>
<?php $pageScript = 'frontend/js/notifications.js';
require 'includes/footer.php'; ?>