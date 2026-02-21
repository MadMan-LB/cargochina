<?php
$area = 'admin';
require __DIR__ . '/../includes/area_bootstrap.php';
$currentPage = 'notifications';
$pageTitle = 'Notifications';
$breadcrumbs = [['Admin', '/cargochina/admin/'], ['Notifications', '']];
require __DIR__ . '/../includes/area_layout.php';
?>
<link rel="stylesheet" href="/cargochina/frontend/css/style.css">
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Notifications</h1>
    <a href="<?= $areaBase ?>/notification_preferences.php" class="btn btn-outline-secondary btn-sm">Preferences</a>
</div>
<div class="card">
    <div class="card-body">
        <div id="notificationsList"></div>
    </div>
</div>
<?php
$pageScript = '/cargochina/frontend/js/notifications.js';
require __DIR__ . '/../includes/area_footer.php';
?>