<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['expect-db:', 'allow-production-read']);
$expectedDatabase = trim((string) ($options['expect-db'] ?? ''));
if ($expectedDatabase === '') {
    fwrite(STDERR, "Usage: php scripts/production_release_preflight.php --expect-db=DATABASE [--allow-production-read]\n");
    exit(64);
}
if (strtolower($expectedDatabase) === 'clms' && !array_key_exists('allow-production-read', $options)) {
    fwrite(STDERR, "Refusing a production-named database without --allow-production-read. This command never writes data.\n");
    exit(65);
}

// Verify the configured database name before loading the connection layer so a
// mistyped --expect-db cannot accidentally establish even a read-only session
// against another database.
$configuredDatabase = getenv('DB_NAME');
if ($configuredDatabase === false || trim((string) $configuredDatabase) === '') {
    $envPath = dirname(__DIR__) . '/.env';
    if (is_file($envPath)) {
        foreach (file($envPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*DB_NAME\s*=\s*(.*)\s*$/', $line, $matches)) {
                $configuredDatabase = trim((string) $matches[1], " \t\n\r\0\x0B\"'");
                break;
            }
        }
    }
}
if (!is_string($configuredDatabase) || !hash_equals($expectedDatabase, trim($configuredDatabase))) {
    fwrite(STDERR, "Configured DB_NAME does not match --expect-db; no database connection was opened.\n");
    exit(66);
}

require_once dirname(__DIR__) . '/backend/config/database.php';
require_once dirname(__DIR__) . '/backend/services/ProductionReleasePreflightService.php';

$pdo = getDb();
$startedReadOnly = false;
try {
    if (!$pdo->inTransaction()) {
        $pdo->exec('START TRANSACTION READ ONLY');
        $startedReadOnly = true;
    }
    $result = (new ProductionReleasePreflightService($pdo))->run([
        'expected_database' => $expectedDatabase,
        'translation_provider' => getenv('TRANSLATION_PROVIDER') ?: ($_ENV['TRANSLATION_PROVIDER'] ?? 'disabled'),
        'backup_verified_at' => getenv('CLMS_BACKUP_VERIFIED_AT') ?: '',
        'restore_verified_at' => getenv('CLMS_RESTORE_VERIFIED_AT') ?: '',
        'backup_file' => getenv('CLMS_BACKUP_FILE') ?: '',
        'backup_sha256' => getenv('CLMS_BACKUP_SHA256') ?: '',
    ]);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    if ($startedReadOnly && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    exit($result['status'] === ProductionReleasePreflightService::VERIFIED ? 0 : 2);
} catch (Throwable $e) {
    if ($startedReadOnly && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
    exit(70);
}
