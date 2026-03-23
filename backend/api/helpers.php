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

/** Set Cache-Control for GET responses (departments, roles, config). Use for read-heavy, rarely-changing data. */
function setCacheHeaders(int $maxAgeSeconds = 60): void
{
    header('Cache-Control: private, max-age=' . $maxAgeSeconds);
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

function getBusinessSetting(PDO $pdo, string $key, ?string $default = null): ?string
{
    static $cache = [];
    $cacheKey = spl_object_id($pdo) . ':' . $key;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        $chk = @$pdo->query("SHOW TABLES LIKE 'business_settings'");
        if (!$chk || $chk->rowCount() === 0) {
            return $cache[$cacheKey] = $default;
        }
        $stmt = $pdo->prepare("SELECT key_value FROM business_settings WHERE key_name = ? LIMIT 1");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $cache[$cacheKey] = ($value !== false ? (string) $value : $default);
    } catch (Throwable $e) {
        return $cache[$cacheKey] = $default;
    }
}

function normalizeStoredUploadPath(string $filePath, bool $mustExist = true): string
{
    $normalized = str_replace('\\', '/', trim($filePath));
    $normalized = preg_replace('#^\./+#', '', $normalized ?? '');
    $normalized = ltrim((string) $normalized, '/');

    if ($normalized === '') {
        jsonError('file_path required', 400);
    }
    if (preg_match('#^[A-Za-z]:/#', $normalized) || str_contains($normalized, '..')) {
        jsonError('Invalid file_path', 400);
    }
    if (!str_starts_with($normalized, 'uploads/')) {
        jsonError('Invalid file_path; only uploaded files are allowed', 400);
    }

    // Must match upload handler: backend/uploads (dirname(__DIR__,1) = backend from backend/api)
    $backendDir = dirname(__DIR__, 1);
    $uploadDir = $backendDir . '/uploads';
    if (!is_dir($uploadDir)) {
        if (!@mkdir($uploadDir, 0755, true)) {
            jsonError('Upload directory is not available', 500);
        }
    }
    $uploadRoot = realpath($uploadDir);
    if ($uploadRoot === false) {
        jsonError('Upload directory is not available', 500);
    }

    $fullPath = $backendDir . '/' . $normalized;
    if ($mustExist && !is_file($fullPath)) {
        jsonError('Uploaded file not found', 400);
    }

    $resolved = realpath($fullPath);
    if ($resolved === false && !$mustExist) {
        $resolvedDir = realpath(dirname($fullPath));
        if ($resolvedDir === false) {
            jsonError('Invalid file_path', 400);
        }
        $resolvedDir = str_replace('\\', '/', $resolvedDir);
        if ($resolvedDir !== str_replace('\\', '/', $uploadRoot) && !str_starts_with($resolvedDir, str_replace('\\', '/', $uploadRoot) . '/')) {
            jsonError('Invalid file_path', 400);
        }
        return $normalized;
    }

    if ($resolved === false || !str_starts_with(str_replace('\\', '/', $resolved), str_replace('\\', '/', $uploadRoot) . '/')) {
        jsonError('Invalid file_path', 400);
    }

    return $normalized;
}

function normalizeStoredUploadPathList(array $paths): array
{
    $normalized = [];
    foreach ($paths as $path) {
        if (!is_string($path) || trim($path) === '') {
            continue;
        }
        $normalized[] = normalizeStoredUploadPath($path);
    }
    return array_values(array_unique($normalized));
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
