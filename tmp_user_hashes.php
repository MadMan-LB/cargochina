<?php
require 'backend/config/database.php';
$pdo = getDb();
$stmt = $pdo->query("SELECT id, email, LENGTH(password_hash) AS hash_len, LEFT(password_hash, 10) AS hash_prefix, is_active FROM users ORDER BY id DESC LIMIT 10");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo implode('|', [$row['id'], $row['email'], $row['hash_len'], $row['hash_prefix'], $row['is_active']]) . PHP_EOL;
}
?>
