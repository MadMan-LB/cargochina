<?php

/**
 * Notification Preferences API - GET/PUT for current user
 * GET /notification-preferences, PUT /notification-preferences
 */

require_once __DIR__ . '/../helpers.php';

$EVENT_TYPES = ['order_submitted', 'order_approved', 'order_received', 'variance_confirmation', 'shipment_finalized'];
$CHANNELS = ['dashboard', 'email', 'whatsapp'];

return function (string $method, ?string $id, ?string $action, array $input) use ($EVENT_TYPES, $CHANNELS) {
    $userId = getAuthUserId();
    if (!$userId) {
        jsonError('Unauthorized', 401);
    }
    $pdo = getDb();

    if ($method === 'GET') {
        $stmt = $pdo->prepare("SELECT channel, event_type, enabled FROM user_notification_preferences WHERE user_id = ?");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            // Lazy seed: persist default preferences on first read (Option B)
            $ins = $pdo->prepare("INSERT INTO user_notification_preferences (user_id, channel, event_type, enabled) VALUES (?,?,?,?)");
            foreach ($EVENT_TYPES as $et) {
                $ins->execute([$userId, 'dashboard', $et, 1]);
                $ins->execute([$userId, 'email', $et, 1]);
                $ins->execute([$userId, 'whatsapp', $et, 1]);
            }
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        jsonResponse(['data' => $rows]);
    }

    if ($method === 'PUT') {
        $prefs = $input['preferences'] ?? [];
        if (!is_array($prefs)) {
            jsonError('preferences must be an array', 400);
        }
        $errors = [];
        foreach ($prefs as $i => $p) {
            $ch = $p['channel'] ?? '';
            $et = $p['event_type'] ?? '';
            $en = isset($p['enabled']) ? (bool) $p['enabled'] : true;
            if (!in_array($ch, $CHANNELS, true)) {
                $errors["preferences.$i.channel"] = 'Invalid channel';
            }
            if (!in_array($et, $EVENT_TYPES, true)) {
                $errors["preferences.$i.event_type"] = 'Invalid event_type';
            }
        }
        if (!empty($errors)) {
            jsonError('Validation failed', 400, $errors);
        }
        $pdo->prepare("DELETE FROM user_notification_preferences WHERE user_id = ?")->execute([$userId]);
        $ins = $pdo->prepare("INSERT INTO user_notification_preferences (user_id, channel, event_type, enabled) VALUES (?,?,?,?)");
        foreach ($prefs as $p) {
            $ch = $p['channel'] ?? 'dashboard';
            $et = $p['event_type'] ?? '';
            $en = isset($p['enabled']) ? (int) (bool) $p['enabled'] : 1;
            if (in_array($ch, $CHANNELS, true) && in_array($et, $EVENT_TYPES, true)) {
                $ins->execute([$userId, $ch, $et, $en]);
            }
        }
        $stmt = $pdo->prepare("SELECT channel, event_type, enabled FROM user_notification_preferences WHERE user_id = ?");
        $stmt->execute([$userId]);
        jsonResponse(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    jsonError('Method not allowed', 405);
};
