<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$script = basename($_SERVER['PHP_SELF'] ?? '');
if (empty($_SESSION['user_id']) && $script !== 'login.php') {
    header('Location: login.php');
    exit;
}
