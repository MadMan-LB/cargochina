<?php

/**
 * API response helpers
 */

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $message, int $status = 400, array $errors = [], ?string $requestId = null): void
{
    $requestId = $requestId ?? bin2hex(random_bytes(8));
    $body = ['error' => true, 'message' => $message, 'request_id' => $requestId];
    if (!empty($errors)) {
        $body['errors'] = $errors;
    }
    jsonResponse($body, $status);
}

function ensureSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function getAuthUserId(): ?int
{
    ensureSession();
    return $_SESSION['user_id'] ?? null;
}

function getUserRoles(): array
{
    ensureSession();
    return $_SESSION['user_roles'] ?? [];
}

function hasRole(string $role): bool
{
    return in_array($role, getUserRoles(), true);
}

function hasAnyRole(array $roles): bool
{
    return !empty(array_intersect($roles, getUserRoles()));
}

function requireAuth(): int
{
    $userId = getAuthUserId();
    if (!$userId) {
        jsonError('Unauthorized', 401);
    }
    return $userId;
}

function requireRole(array $roles): void
{
    if (!hasAnyRole($roles)) {
        jsonError('Forbidden', 403);
    }
}

/** Structured log for CLMS (order_id, receipt_id, notification_id, etc.) */
function logClms(string $event, array $context = []): void
{
    $logDir = dirname(__DIR__, 2) . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $line = date('Y-m-d H:i:s') . ' ' . json_encode(array_merge(['event' => $event], $context), JSON_UNESCAPED_UNICODE) . "\n";
    @error_log($line, 3, $logDir . '/clms.log');
}
