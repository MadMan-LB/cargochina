<?php
$currentPage = 'notifications';
$pageTitle = 'Notifications';
require 'includes/layout.php';
?>
<link rel="stylesheet" href="frontend/css/style.css">
<div class="col-12">
  <h1 class="mb-4">Notifications</h1>
  <div class="card">
    <div class="card-body">
      <div id="notificationsList"></div>
    </div>
  </div>
</div>
<?php $pageScript = 'frontend/js/notifications.js';
require 'includes/footer.php'; ?>