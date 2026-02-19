<?php

/**
 * Run migrations from backend/migrations/
 * Usage: php backend/migrations/run.php
 */

$rootDir = dirname(__DIR__, 2);
require_once $rootDir . '/backend/config/database.php';

$migrationsDir = __DIR__;
$files = glob($migrationsDir . '/*.sql');
sort($files);

$pdo = getDb();

// Create migrations tracking table if not exists
$pdo->exec("
    CREATE TABLE IF NOT EXISTS _migrations (
        name VARCHAR(255) PRIMARY KEY,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

foreach ($files as $file) {
    $name = basename($file);
    $stmt = $pdo->query("SELECT 1 FROM _migrations WHERE name = " . $pdo->quote($name));
    if ($stmt->fetch()) {
        echo "Skip (already applied): $name\n";
        continue;
    }
    $sql = file_get_contents($file);
    $pdo->exec($sql);
    $pdo->prepare("INSERT INTO _migrations (name) VALUES (?)")->execute([$name]);
    echo "Applied: $name\n";
}

echo "Migrations complete.\n";
