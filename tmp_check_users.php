<?php
require 'backend/config/database.php';
$pdo = getDb();
$cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) { echo $c['Field'] . '|' . $c['Type'] . PHP_EOL; }
?>
