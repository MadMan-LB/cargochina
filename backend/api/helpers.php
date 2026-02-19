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

function getAuthUserId(): ?int
{
    session_start();
    return $_SESSION['user_id'] ?? null;
}

function requireAuth(): int
{
    $userId = getAuthUserId();
    if (!$userId) {
        jsonError('Unauthorized', 401);
    }
    return $userId;
}
