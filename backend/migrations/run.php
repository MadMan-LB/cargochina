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

// Migrations rely on atomic writes and foreign keys; fail before any DDL when
// a legacy server defaults to MyISAM or an existing core table uses it.
$defaultEngine=(string)$pdo->query('SELECT @@default_storage_engine')->fetchColumn();
if (strcasecmp($defaultEngine,'InnoDB')!==0) throw new RuntimeException('Migration requires InnoDB default storage engine; current default: '.$defaultEngine);
$nonTransactional=$pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND ENGINE<>'InnoDB'
      AND TABLE_NAME IN ('users','orders','order_items','warehouse_receipts','warehouse_receipt_items','containers','shipment_drafts','shipment_draft_orders','audit_log')")->fetchAll(PDO::FETCH_COLUMN);
if ($nonTransactional) throw new RuntimeException('Core tables require InnoDB before migration: '.implode(', ',$nonTransactional));

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
    if ($name === '031_seed_dummy_data.sql' && !clmsEnvFlag('ALLOW_DEMO_SEED', false)) {
        echo "Skip (demo seed disabled): $name. Set ALLOW_DEMO_SEED=1 to apply it intentionally.\n";
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
            $result = $pdo->query($st . ';');
            if ($result instanceof PDOStatement) {
                do {
                    $result->fetchAll(PDO::FETCH_ASSOC);
                } while ($result->nextRowset());
                $result->closeCursor();
            }
        } catch (PDOException $e) {
            if (strpos($name, '031_seed') !== false && strpos($e->getMessage(), '1136') !== false) {
                echo "Warning: Skipping seed statement (schema mismatch): " . substr($st, 0, 80) . "...\n";
                continue;
            }
            throw $e;
        }
    }
    if (in_array($name, ['062_balance_sidebar_defaults.sql','065_balances_deployment_hardening.sql'], true)) {
        require_once __DIR__.'/SidebarConfigMigrationService.php';
        SidebarConfigMigrationService::apply($pdo,$name==='065_balances_deployment_hardening.sql');
    }
    $pdo->prepare("INSERT INTO _migrations (name) VALUES (?)")->execute([$name]);
    echo "Applied: $name\n";
}

echo "Migrations complete.\n";
