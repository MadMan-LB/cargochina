<?php

/**
 * Notification Service - dashboard + email + WhatsApp
 * Phase 2: Configurable delivery channels, preferences, delivery logging
 */

require_once dirname(__DIR__, 2) . '/backend/api/helpers.php';

class NotificationService
{
    private PDO $pdo;
    private array $config;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->config = require dirname(__DIR__, 2) . '/backend/config/config.php';
    }

    public function notify(int $userId, string $type, string $title, ?string $body = null, string $channel = 'dashboard'): int
    {
        $this->pdo->prepare("INSERT INTO notifications (user_id, type, channel, title, body) VALUES (?,?,?,?,?)")
            ->execute([$userId, $type, $channel, $title, $body]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Check if user has channel+event enabled (default true when no pref) */
    private function isChannelEnabled(int $userId, string $channel, string $eventType): bool
    {
        $stmt = $this->pdo->prepare("SELECT enabled FROM user_notification_preferences WHERE user_id=? AND channel=? AND event_type=?");
        $stmt->execute([$userId, $channel, $eventType]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? true : (bool) $row['enabled'];
    }

    /** Log delivery attempt (idempotency via payload_hash) */
    private function logDelivery(int $notificationId, string $channel, string $payloadHash, string $status, int $attempts = 1, ?string $lastError = null, ?string $externalId = null): void
    {
        $this->pdo->prepare("INSERT INTO notification_delivery_log (notification_id, channel, payload_hash, status, attempts, last_error, external_id) VALUES (?,?,?,?,?,?,?)")
            ->execute([$notificationId, $channel, $payloadHash, $status, $attempts, $lastError, $externalId]);
        logClms('notification_delivery', [
            'notification_id' => $notificationId,
            'channel' => $channel,
            'status' => $status,
            'order_id' => $GLOBALS['_log_order_id'] ?? null,
            'receipt_id' => $GLOBALS['_log_receipt_id'] ?? null,
        ]);
    }

    /** Check if already delivered (idempotency) */
    private function alreadyDelivered(int $notificationId, string $channel, string $payloadHash): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM notification_delivery_log WHERE notification_id=? AND channel=? AND payload_hash=? AND status='sent' LIMIT 1");
        $stmt->execute([$notificationId, $channel, $payloadHash]);
        return (bool) $stmt->fetch();
    }

    /** Build WhatsApp request (provider-specific payload) */
    private function buildWhatsAppRequest(string $provider, string $to, string $message): array
    {
        if ($provider === 'twilio') {
            $from = trim($this->config['whatsapp_twilio_from'] ?? '');
            $toFormatted = (strpos($to, 'whatsapp:') === 0) ? $to : 'whatsapp:' . $to;
            return [
                'url' => 'https://api.twilio.com/2010-04-01/Accounts/' . urlencode(trim($this->config['whatsapp_twilio_account_sid'] ?? '')) . '/Messages.json',
                'method' => 'POST',
                'headers' => [],
                'auth' => 'basic',
                'body' => http_build_query(['To' => $toFormatted, 'From' => $from, 'Body' => $message]),
                'content_type' => 'application/x-www-form-urlencoded',
            ];
        }
        return [
            'url' => trim($this->config['whatsapp_api_url'] ?? ''),
            'method' => 'POST',
            'headers' => ['Content-Type: application/json', 'Authorization: Bearer ' . trim($this->config['whatsapp_api_token'] ?? '')],
            'auth' => null,
            'body' => json_encode(['to' => $to, 'message' => $message]),
            'content_type' => 'application/json',
        ];
    }

    /** Send WhatsApp via configured provider */
    private function sendWhatsApp(string $to, string $message): array
    {
        $provider = $this->config['whatsapp_provider'] ?? 'generic';
        $req = $this->buildWhatsAppRequest($provider, $to, $message);
        if (empty($req['url'])) {
            return ['success' => false, 'error' => 'WhatsApp not configured', 'external_id' => null];
        }
        $ch = curl_init($req['url']);
        $opts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $req['body'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ];
        if ($req['content_type'] === 'application/json') {
            $opts[CURLOPT_HTTPHEADER] = $req['headers'];
        } else {
            $opts[CURLOPT_HTTPHEADER] = ['Content-Type: ' . $req['content_type']];
            if ($req['auth'] === 'basic') {
                $sid = trim($this->config['whatsapp_twilio_account_sid'] ?? '');
                $token = trim($this->config['whatsapp_twilio_auth_token'] ?? '');
                $opts[CURLOPT_HTTPHEADER][] = 'Authorization: Basic ' . base64_encode($sid . ':' . $token);
            }
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300) {
            $dec = json_decode($resp, true);
            $extId = $dec['sid'] ?? $dec['id'] ?? $dec['message_id'] ?? null;
            return ['success' => true, 'error' => null, 'external_id' => $extId];
        }
        return ['success' => false, 'error' => 'HTTP ' . $code . ': ' . substr($resp ?? '', 0, 200), 'external_id' => null];
    }

    /** Notify all admins with channel dispatch (dashboard, email, whatsapp) */
    public function notifyAdmins(string $eventType, string $title, ?string $body = null, array $context = []): void
    {
        $channels = $this->config['notification_channels'] ?? ['dashboard'];
        $stmt = $this->pdo->query("SELECT DISTINCT u.id, u.email FROM users u
            JOIN user_roles ur ON u.id = ur.user_id
            JOIN roles r ON ur.role_id = r.id
            WHERE r.code IN ('ChinaAdmin','LebanonAdmin','SuperAdmin') AND u.is_active = 1");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $uid = (int) $row['id'];
            $email = $row['email'] ?? '';
            $nid = $this->notify($uid, $eventType, $title, $body, 'dashboard');
            $payload = json_encode(['title' => $title, 'body' => $body, 'event' => $eventType]);
            $payloadHash = hash('sha256', $payload . $uid);

            if (in_array('email', $channels, true) && $this->isChannelEnabled($uid, 'email', $eventType) && $email) {
                if (!$this->alreadyDelivered($nid, 'email', $payloadHash)) {
                    $from = $this->config['email_from_address'] ?? 'noreply@example.com';
                    $fromName = $this->config['email_from_name'] ?? 'CLMS';
                    $headers = "From: $fromName <$from>\r\nContent-Type: text/plain; charset=UTF-8";
                    $sent = @mail($email, $title, $body ?? '', $headers);
                    $this->logDelivery($nid, 'email', $payloadHash, $sent ? 'sent' : 'failed', 1, $sent ? null : 'mail() failed');
                }
            }
            if (in_array('whatsapp', $channels, true) && $this->isChannelEnabled($uid, 'whatsapp', $eventType)) {
                if ($this->alreadyDelivered($nid, 'whatsapp', $payloadHash)) {
                    continue;
                }
                $provider = $this->config['whatsapp_provider'] ?? 'generic';
                $to = $provider === 'twilio'
                    ? trim($this->config['whatsapp_twilio_to'] ?? '')
                    : $email;
                $msg = $title . "\n\n" . ($body ?? '');
                $canSend = false;
                if ($provider === 'generic') {
                    $canSend = !empty(trim($this->config['whatsapp_api_url'] ?? '')) && !empty(trim($this->config['whatsapp_api_token'] ?? ''));
                } elseif ($provider === 'twilio' && !empty($to)) {
                    $canSend = !empty(trim($this->config['whatsapp_twilio_account_sid'] ?? ''))
                        && !empty(trim($this->config['whatsapp_twilio_auth_token'] ?? ''))
                        && !empty(trim($this->config['whatsapp_twilio_from'] ?? ''));
                }
                if ($canSend) {
                    $maxAttempts = (int) ($this->config['notification_max_attempts'] ?? 3);
                    $retrySec = (int) ($this->config['notification_retry_seconds'] ?? 60);
                    $err = null;
                    $extId = null;
                    $attempts = 0;
                    for ($a = 1; $a <= $maxAttempts; $a++) {
                        $attempts = $a;
                        try {
                            $res = $this->sendWhatsApp($to, $msg);
                            if ($res['success']) {
                                $extId = $res['external_id'];
                                break;
                            }
                            $err = $res['error'];
                            if ($a < $maxAttempts) {
                                sleep(min($retrySec, 10));
                            }
                        } catch (Throwable $e) {
                            $err = $e->getMessage();
                            if ($a < $maxAttempts) {
                                sleep(min($retrySec, 10));
                            }
                        }
                    }
                    $this->logDelivery($nid, 'whatsapp', $payloadHash, $err ? 'failed' : 'sent', $attempts, $err, $extId);
                }
            }
        }
    }

    public function notifyOrderCreated(int $orderId, int $userId): void
    {
        $this->notify($userId, 'order_created', 'Order #' . $orderId . ' created', 'New order has been created.');
    }

    public function notifyOrderSubmitted(int $orderId): void
    {
        $GLOBALS['_log_order_id'] = $orderId;
        $this->notifyAdmins('order_submitted', 'Order #' . $orderId . ' submitted', 'Order ready for approval.', ['order_id' => $orderId]);
        unset($GLOBALS['_log_order_id']);
    }

    public function notifyOrderApproved(int $orderId): void
    {
        $GLOBALS['_log_order_id'] = $orderId;
        $this->notifyAdmins('order_approved', 'Order #' . $orderId . ' approved', 'Order approved for warehouse receiving.', ['order_id' => $orderId]);
        unset($GLOBALS['_log_order_id']);
    }

    public function notifyOrderReceived(int $orderId, int $userId, bool $varianceDetected): void
    {
        $eventType = $varianceDetected ? 'variance_confirmation' : 'order_received';
        $title = $varianceDetected ? 'Order #' . $orderId . ' — confirmation required' : 'Order #' . $orderId . ' received';
        $body = $varianceDetected ? 'CBM/weight variance detected. Customer confirmation required.' : 'Order received at warehouse.';
        $GLOBALS['_log_order_id'] = $orderId;
        $this->notifyAdmins($eventType, $title, $body, ['order_id' => $orderId, 'variance' => $varianceDetected]);
        unset($GLOBALS['_log_order_id']);
    }

    public function notifyShipmentFinalized(int $shipmentDraftId, int $orderCount): void
    {
        $this->notifyAdmins(
            'shipment_finalized',
            'Shipment draft #' . $shipmentDraftId . ' finalized',
            $orderCount . ' order(s) pushed to tracking.',
            ['shipment_draft_id' => $shipmentDraftId]
        );
    }
}
