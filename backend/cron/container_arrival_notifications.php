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
$daysList = array_map('intval', array_filter(array_map('trim', explode(',', $daysStr))));
if (empty($daysList)) {
    if (php_sapi_name() === 'cli') echo "No notify days configured\n";
    exit(0);
}

$stmt = $pdo->query("SELECT id, code, eta_date FROM containers WHERE eta_date IS NOT NULL AND eta_date > CURDATE() AND status NOT IN ('arrived','available')");
$containers = $stmt->fetchAll(PDO::FETCH_ASSOC);
$notified = 0;

foreach ($containers as $c) {
    $containerId = (int) $c['id'];
    $code = $c['code'] ?? '#' . $containerId;
    $etaDate = $c['eta_date'];

    foreach ($daysList as $daysBefore) {
        if ($daysBefore < 1) continue;
        $targetDate = date('Y-m-d', strtotime($etaDate . " -{$daysBefore} days"));
        if ($targetDate !== date('Y-m-d')) continue;

        $exists = $pdo->prepare("SELECT 1 FROM container_arrival_notifications WHERE container_id = ? AND days_before = ? LIMIT 1");
        $exists->execute([$containerId, $daysBefore]);
        if ($exists->fetch()) continue;

        (new NotificationService($pdo))->notifyContainerArrival($containerId, $code, $etaDate, $daysBefore);
        $pdo->prepare("INSERT INTO container_arrival_notifications (container_id, days_before) VALUES (?, ?)")
            ->execute([$containerId, $daysBefore]);
        $notified++;
    }
}

if (php_sapi_name() === 'cli' && $notified > 0) {
    echo "Container arrival notifications sent: $notified\n";
}
