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
    // Remove comment lines before splitting (prevents semicolons in comments from creating fake statements)
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    // Run each statement separately (handles idempotent migrations with conditional DDL)
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $st) {
        if ($st === '') continue;
        try {
            $pdo->exec($st . ';');
        } catch (PDOException $e) {
            if (strpos($name, '031_seed') !== false && strpos($e->getMessage(), '1136') !== false) {
                echo "Warning: Skipping seed statement (schema mismatch): " . substr($st, 0, 80) . "...\n";
                continue;
            }
            throw $e;
        }
    }
    $pdo->prepare("INSERT INTO _migrations (name) VALUES (?)")->execute([$name]);
    echo "Applied: $name\n";
}

echo "Migrations complete.\n";
