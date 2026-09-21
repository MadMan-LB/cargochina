<?php

/**
 * Container Arrival Notifications - cron job
 * Run daily: php backend/cron/container_arrival_notifications.php
 * Or via cron: 0 8 * * * cd /path/to/cargochina && php backend/cron/container_arrival_notifications.php
 *
 * For containers with eta_date set, notifies admins when ETA is within configured days (e.g. 7, 3, 1).
 * Uses ARRIVAL_NOTIFY_DAYS from business_settings (comma-separated, e.g. "7,3,1").
 * Tracks sent notifications in container_arrival_notifications to avoid duplicates.
 */

$rootDir = dirname(__DIR__, 2);
chdir($rootDir);
require_once $rootDir . '/backend/config/database.php';
require_once $rootDir . '/backend/services/NotificationService.php';

$pdo = getDb();
if (!$pdo) {
    fwrite(STDERR, "Database connection failed\n");
    exit(1);
}

$chkEta = @$pdo->query("SHOW COLUMNS FROM containers LIKE 'eta_date'");
if (!$chkEta || $chkEta->rowCount() === 0) {
    if (php_sapi_name() === 'cli') echo "eta_date column not found, skipping\n";
    exit(0);
}

$chkTable = @$pdo->query("SHOW TABLES LIKE 'container_arrival_notifications'");
if (!$chkTable || $chkTable->rowCount() === 0) {
    if (php_sapi_name() === 'cli') echo "container_arrival_notifications table not found, skipping\n";
    exit(0);
}

$daysStr = '7,3,1';
$chkBs = @$pdo->query("SHOW TABLES LIKE 'business_settings'");
if ($chkBs && $chkBs->rowCount() > 0) {
    $stmt = $pdo->query("SELECT key_value FROM business_settings WHERE key_name = 'ARRIVAL_NOTIFY_DAYS' LIMIT 1");
    if ($stmt && $r = $stmt->fetch(PDO::FETCH_ASSOC) && trim($r['key_value'] ?? '') !== '') {
        $daysStr = trim($r['key_value']);
    }
}
require_once dirname(__DIR__).'/services/SettingsWriteService.php';
try {$daysList=SettingsWriteService::arrivalDays($daysStr);}
catch(InvalidArgumentException $e){error_log('Invalid ARRIVAL_NOTIFY_DAYS configuration; arrival notifications skipped');exit(1);}
if (empty($daysList)) {
    if (php_sapi_name() === 'cli') echo "No notify days configured\n";
    exit(0);
}

$today=date('Y-m-d');
$stmt = $pdo->prepare("SELECT id FROM containers WHERE eta_date IS NOT NULL AND eta_date >= ? AND status NOT IN ('arrived','available') ORDER BY id");
$stmt->execute([$today]);
$containers = $stmt->fetchAll(PDO::FETCH_ASSOC);
$notified = 0;

require_once dirname(__DIR__).'/services/ContainerArrivalNotificationService.php';
$notifications=new NotificationService($pdo);
foreach ($containers as $c) {
    if(ContainerArrivalNotificationService::notifyDue($pdo,$notifications,(int)$c['id'],$daysList,$today))$notified++;
}

if (php_sapi_name() === 'cli' && $notified > 0) {
    echo "Container arrival notifications sent: $notified\n";
}
