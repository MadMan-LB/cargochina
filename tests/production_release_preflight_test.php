<?php

$root = dirname(__DIR__);
require_once $root . '/backend/config/database.php';
require_once $root . '/backend/services/ProductionReleasePreflightService.php';

$pdo = getDb();
$before = [];
foreach (['orders', 'order_items', 'draft_order_costs', 'warehouse_receipts'] as $table) {
    $before[$table] = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
}

$pdo->exec('START TRANSACTION READ ONLY');
$backupFixture = tempnam(sys_get_temp_dir(), 'clms_preflight_backup_');
file_put_contents($backupFixture, 'isolated backup verification fixture');
try {
    $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $result = (new ProductionReleasePreflightService($pdo))->run([
        'expected_database' => $database,
        'translation_provider' => 'disabled',
        'backup_verified_at' => '2026-07-12T00:00:00Z',
        'restore_verified_at' => '2026-07-12T00:10:00Z',
        'backup_file' => $backupFixture,
        'backup_sha256' => hash_file('sha256', $backupFixture),
    ]);
    if (($result['read_only'] ?? false) !== true) throw new Exception('Preflight did not declare read-only behavior');
    if (($result['status'] ?? '') === ProductionReleasePreflightService::VERIFIED) throw new Exception('Environment-dependent production gates unexpectedly verified');
    $codes = array_column($result['checks'] ?? [], 'status', 'code');
    foreach (['database_identity', 'php_extensions', 'release_migrations', 'release_schema', 'receiving_idempotency', 'seed_password_rotation', 'legacy_item_numbers', 'existing_item_classification', 'translation_provider', 'accounting_policy', 'backup_and_restore_rehearsal'] as $code) {
        if (!isset($codes[$code])) throw new Exception("Missing preflight check: $code");
    }
    if (($codes['database_identity'] ?? '') !== ProductionReleasePreflightService::VERIFIED) throw new Exception('Expected database identity did not verify');
    if (($codes['accounting_policy'] ?? '') !== ProductionReleasePreflightService::VERIFIED) throw new Exception('Approved accounting implementation did not verify');
    if (($codes['backup_and_restore_rehearsal'] ?? '') !== ProductionReleasePreflightService::VERIFIED) throw new Exception('Valid backup evidence did not verify');
    echo "PASS: read-only production release preflight verifies approved accounting controls and retains environment gates\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    @unlink($backupFixture);
}

foreach ($before as $table => $count) {
    $after = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    if ($after !== $count) throw new Exception("Preflight changed row count for $table");
}
echo "PASS: production release preflight leaves audited tables unchanged\n";
