<?php

/**
 * Diagnostics API - SuperAdmin only
 * GET /diagnostics/notification-delivery-log — list with filters
 * GET /diagnostics/config-health — config readiness booleans
 * POST /diagnostics/retry-delivery/{logId} — retry failed delivery
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 2) . '/services/NotificationService.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    requireRole(['SuperAdmin']);
    $pdo = getDb();

    if ($id === 'notification-delivery-log') {
        if ($method === 'GET') {
            $status = $_GET['status'] ?? null;
            $channel = $_GET['channel'] ?? null;
            $limit = min(100, max(1, (int) ($_GET['limit'] ?? 100)));
            $offset = max(0, (int) ($_GET['offset'] ?? 0));
            $dateFrom = $_GET['date_from'] ?? null;
            $dateTo = $_GET['date_to'] ?? null;

            $sql = "SELECT ndl.id, ndl.notification_id, ndl.channel, ndl.payload_hash, ndl.status, ndl.attempts, ndl.last_error, ndl.external_id, ndl.created_at, n.type as event_type, n.title
                FROM notification_delivery_log ndl
                JOIN notifications n ON ndl.notification_id = n.id
                WHERE 1=1";
            $params = [];
            if ($status) {
                $sql .= " AND ndl.status = ?";
                $params[] = $status;
            }
            if ($channel) {
                $sql .= " AND ndl.channel = ?";
                $params[] = $channel;
            }
            if ($dateFrom) {
                $sql .= " AND ndl.created_at >= ?";
                $params[] = $dateFrom;
            }
            if ($dateTo) {
                $sql .= " AND ndl.created_at <= ?";
                $params[] = $dateTo . ' 23:59:59';
            }
            $sql .= " ORDER BY ndl.created_at DESC LIMIT " . (int) $limit . " OFFSET " . (int) $offset;

            $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
            if ($params) $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                unset($r['payload_hash']);
            }
            jsonResponse(['data' => $rows]);
        }
    }

    if ($id === 'config-health') {
        if ($method === 'GET') {
            $config = require dirname(__DIR__, 2) . '/config/config.php';
            $emailConfigured = trim($config['email_from_address'] ?? '') !== '';
            $provider = $config['whatsapp_provider'] ?? 'generic';
            $waUrl = trim($config['whatsapp_api_url'] ?? '');
            $waToken = trim($config['whatsapp_api_token'] ?? '');
            $waSid = trim($config['whatsapp_twilio_account_sid'] ?? '');
            $waAuth = trim($config['whatsapp_twilio_auth_token'] ?? '');
            $waFrom = trim($config['whatsapp_twilio_from'] ?? '');
            $waTo = trim($config['whatsapp_twilio_to'] ?? '');
            $whatsappConfigured = ($provider === 'generic' && $waUrl !== '' && $waToken !== '')
                || ($provider === 'twilio' && $waSid !== '' && $waAuth !== '' && $waFrom !== '' && $waTo !== '');
            $itemLevelEnabled = (int) ($config['item_level_receiving_enabled'] ?? 0);
            $retryConfigured = ((int) ($config['notification_max_attempts'] ?? 3)) >= 1 && ((int) ($config['notification_retry_seconds'] ?? 60)) >= 1;
            jsonResponse(['data' => [
                'email_configured' => $emailConfigured,
                'whatsapp_configured' => $whatsappConfigured,
                'item_level_enabled' => (bool) $itemLevelEnabled,
                'retry_configured' => $retryConfigured,
            ]]);
        }
    }

    if ($id === 'retry-delivery' && $method === 'POST' && is_numeric($action)) {
        $logId = (int) $action;
        $svc = new \NotificationService($pdo);
        $result = $svc->retryDelivery($logId);
        jsonResponse(['data' => $result]);
    }

    jsonError('Not found', 404);
};
