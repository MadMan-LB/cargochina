<?php
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/session_roles.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$script = basename($_SERVER['PHP_SELF'] ?? '');
if (empty($_SESSION['user_id']) && $script !== 'login.php') {
    header('Location: login.php');
    exit;
}
if (!empty($_SESSION['user_id'])) {
    clmsRefreshSessionRolesFromDb();
}
