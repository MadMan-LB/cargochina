<?php
require __DIR__ . '/../backend/config/database.php';
$pdo = getDb();
echo "customers columns:\n";
$r = $pdo->query('SHOW COLUMNS FROM customers');
foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo '  ' . $c['Field'] . ' (' . $c['Type'] . ")\n";
}
