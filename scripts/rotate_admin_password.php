<?php

// Offline-only credential rotation. The scripts directory is denied by .htaccess.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/backend/config/database.php';

$email = trim((string) (getenv('CLMS_ADMIN_EMAIL') ?: 'admin@salameh.com'));
$password = (string) (getenv('CLMS_NEW_ADMIN_PASSWORD') ?: '');
if (strlen($password) < 14
    || !preg_match('/[a-z]/', $password)
    || !preg_match('/[A-Z]/', $password)
    || !preg_match('/\d/', $password)
    || !preg_match('/[^A-Za-z0-9]/', $password)) {
    fwrite(STDERR, "CLMS_NEW_ADMIN_PASSWORD must be at least 14 characters and include upper, lower, number, and symbol.\n");
    exit(2);
}

$pdo=getDb();
$stmt=$pdo->prepare('UPDATE users SET password_hash=? WHERE email=? AND is_active=1');
$stmt->execute([password_hash($password,PASSWORD_DEFAULT),$email]);
if ($stmt->rowCount() !== 1) {
    fwrite(STDERR,"Active administrator account not found or password unchanged.\n");
    exit(3);
}
fwrite(STDOUT,"Administrator password rotated for $email.\n");
