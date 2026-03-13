<?php
require __DIR__ . '/../config/database.php';
$pdo = getDb();
$name = '029_supplier_address_fax.sql';
$stmt = $pdo->query("SELECT 1 FROM _migrations WHERE name = " . $pdo->quote($name));
if ($stmt->fetch()) {
    echo "Skip (already applied): $name\n";
    exit(0);
}
$sql = file_get_contents(__DIR__ . '/' . $name);
$pdo->exec($sql);
$pdo->prepare("INSERT INTO _migrations (name) VALUES (?)")->execute([$name]);
echo "Applied: $name\n";
