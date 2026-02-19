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

function jsonError(string $message, int $status = 400, array $errors = []): void
{
    $body = ['error' => true, 'message' => $message];
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
