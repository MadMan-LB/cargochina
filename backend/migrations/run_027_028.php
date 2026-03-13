<?php
require __DIR__ . '/../config/database.php';
$pdo = getDb();
$files = ['027_order_item_supplier.sql', '028_order_template_item_supplier.sql'];
foreach ($files as $name) {
    $stmt = $pdo->query("SELECT 1 FROM _migrations WHERE name = " . $pdo->quote($name));
    if ($stmt->fetch()) {
        echo "Skip (already applied): $name\n";
        continue;
    }
    $path = __DIR__ . '/' . $name;
    if (!file_exists($path)) {
        echo "File not found: $name\n";
        continue;
    }
    $sql = file_get_contents($path);
    try {
        $pdo->exec($sql);
        $pdo->prepare("INSERT INTO _migrations (name) VALUES (?)")->execute([$name]);
        echo "Applied: $name\n";
    } catch (Exception $e) {
        echo "Error $name: " . $e->getMessage() . "\n";
    }
}
echo "Done.\n";
