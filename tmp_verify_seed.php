<?php
require 'backend/config/database.php';
$pdo = getDb();
$stmt = $pdo->query("SELECT email, password_hash FROM users WHERE email IN ('admin@salameh.com','qa.employee@salameh.local','test1@salameh.com','imad')");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
  echo $r['email'] . '|' . (password_verify('password', $r['password_hash']) ? 'OK' : 'NO') . PHP_EOL;
}
?>
