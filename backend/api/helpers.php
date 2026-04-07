<?php

/**
 * API response helpers
 */

function clmsFinalizeApiTiming(int $status): void
{
    if (!empty($GLOBALS['__clms_api_timing_finalized'])) {
        return;
    }
    $GLOBALS['__clms_api_timing_finalized'] = true;

    $start = $GLOBALS['__clms_api_start'] ?? null;
    if (!$start) {
        return;
    }

    $elapsedMs = max(0, (microtime(true) - (float) $start) * 1000);
    $formattedMs = number_format($elapsedMs, 1, '.', '');
    $requestId = $GLOBALS['__clms_api_request_id'] ?? null;

    if (!headers_sent()) {
        if ($requestId) {
            header('X-Request-Id: ' . $requestId);
        }
        if (!empty($GLOBALS['__clms_api_timing_debug'])) {
            header('X-CLMS-Response-Time-Ms: ' . $formattedMs);
            header('Server-Timing: app;dur=' . $formattedMs);
        }
    }

    $slowThresholdMs = (float) ($GLOBALS['__clms_api_slow_threshold_ms'] ?? 800);
    if ($elapsedMs < $slowThresholdMs) {
        return;
    }

    $logDir = dirname(__DIR__, 2) . '/logs';
    if (!is_dir($logDir) && !@mkdir($logDir, 0755, true)) {
        return;
    }

    $method = (string) ($GLOBALS['__clms_api_method'] ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $path = (string) ($GLOBALS['__clms_api_path'] ?? ($_GET['path'] ?? ''));
    $userId = getAuthUserId();
    $line = sprintf(
        "%s %s %s %s %.1fms user=%s request=%s\n",
        date('Y-m-d H:i:s'),
        $method,
        $path ?: '/',
        $status,
        $elapsedMs,
        $userId !== null ? (string) $userId : '-',
        $requestId ?: '-'
    );
    @error_log($line, 3, $logDir . '/performance.log');
}

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    clmsFinalizeApiTiming($status);
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
    $GLOBALS['__clms_api_request_id'] = $requestId;
    $body = ['error' => true, 'message' => $message, 'request_id' => $requestId];
    if (!empty($errors)) {
        $body['errors'] = $errors;
    }
    jsonResponse($body, $status);
}

function format_display_number($value, int $maxDecimals, int $minDecimals = 0): string
{
    if ($value === null || $value === '') {
        return '';
    }
    if (!is_numeric($value)) {
        return trim((string) $value);
    }

    $maxDecimals = max(0, $maxDecimals);
    $minDecimals = max(0, min($maxDecimals, $minDecimals));
    $number = (float) $value;
    $epsilon = $maxDecimals > 0 ? pow(10, -$maxDecimals) / 2 : 0.5;
    if (abs($number) < $epsilon) {
        $number = 0.0;
    }

    $formatted = number_format(round($number, $maxDecimals), $maxDecimals, '.', '');
    if ($maxDecimals > $minDecimals && str_contains($formatted, '.')) {
        [$whole, $fraction] = explode('.', $formatted, 2);
        $fraction = rtrim($fraction, '0');
        if ($minDecimals > 0 && strlen($fraction) < $minDecimals) {
            $fraction = str_pad($fraction, $minDecimals, '0');
        }
        $formatted = $fraction === '' ? $whole : ($whole . '.' . $fraction);
    }

    return $formatted;
}

function format_display_amount($value, int $minDecimals = 0): string
{
    return format_display_number($value, 2, $minDecimals);
}

function format_display_cbm($value, int $maxDecimals = 6, int $minDecimals = 0): string
{
    return format_display_number($value, $maxDecimals, $minDecimals);
}

function format_display_weight($value, int $maxDecimals = 2, int $minDecimals = 0): string
{
    return format_display_number($value, $maxDecimals, $minDecimals);
}

function format_display_percent($value, int $maxDecimals = 1, int $minDecimals = 0): string
{
    return format_display_number($value, $maxDecimals, $minDecimals);
}

function clmsUploadTypeDefinitions(): array
{
    static $definitions = null;
    if ($definitions !== null) {
        return $definitions;
    }

    $definitions = [
        'jpg' => ['kind' => 'image', 'mimes' => ['image/jpeg']],
        'jpeg' => ['kind' => 'image', 'mimes' => ['image/jpeg']],
        'png' => ['kind' => 'image', 'mimes' => ['image/png']],
        'webp' => ['kind' => 'image', 'mimes' => ['image/webp']],
        'jfif' => ['kind' => 'image', 'mimes' => ['image/jpeg']],
        'gif' => ['kind' => 'image', 'mimes' => ['image/gif']],
        'bmp' => ['kind' => 'image', 'mimes' => ['image/bmp', 'image/x-ms-bmp', 'image/x-bmp']],
        'avif' => ['kind' => 'image', 'mimes' => ['image/avif']],
        'pdf' => ['kind' => 'document', 'mimes' => ['application/pdf']],
    ];

    return $definitions;
}

function clmsUploadAllowedExtensions(): array
{
    return array_keys(clmsUploadTypeDefinitions());
}

function clmsUploadAllowedImageExtensions(): array
{
    return array_keys(array_filter(
        clmsUploadTypeDefinitions(),
        static fn(array $def): bool => ($def['kind'] ?? '') === 'image'
    ));
}

function clmsUploadAllowedMimeMap(): array
{
    $map = [];
    foreach (clmsUploadTypeDefinitions() as $ext => $definition) {
        $map[$ext] = $definition['mimes'] ?? [];
    }
    return $map;
}

function clmsUploadUnsupportedCommonImageTypes(): array
{
    return [
        'heic' => 'HEIC / HEIF images are not supported on this server yet. Please convert them to JPG, PNG, WebP, BMP, or AVIF before uploading.',
        'heif' => 'HEIC / HEIF images are not supported on this server yet. Please convert them to JPG, PNG, WebP, BMP, or AVIF before uploading.',
    ];
}

function clmsIsUploadImageExtension(string $extension): bool
{
    $definition = clmsUploadTypeDefinitions()[strtolower($extension)] ?? null;
    return ($definition['kind'] ?? null) === 'image';
}

function clmsCreateImageResourceFromPath(string $sourcePath, ?array $imageInfo = null)
{
    if (!is_file($sourcePath)) {
        return false;
    }

    $imageType = $imageInfo[2] ?? @exif_imagetype($sourcePath);

    if ($imageType === IMAGETYPE_JPEG && function_exists('imagecreatefromjpeg')) {
        return @imagecreatefromjpeg($sourcePath);
    }
    if ($imageType === IMAGETYPE_PNG && function_exists('imagecreatefrompng')) {
        return @imagecreatefrompng($sourcePath);
    }
    if ($imageType === IMAGETYPE_GIF && function_exists('imagecreatefromgif')) {
        return @imagecreatefromgif($sourcePath);
    }
    if (defined('IMAGETYPE_WEBP') && $imageType === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
        return @imagecreatefromwebp($sourcePath);
    }
    if (defined('IMAGETYPE_BMP') && $imageType === IMAGETYPE_BMP && function_exists('imagecreatefrombmp')) {
        return @imagecreatefrombmp($sourcePath);
    }
    if (defined('IMAGETYPE_AVIF') && $imageType === IMAGETYPE_AVIF && function_exists('imagecreatefromavif')) {
        return @imagecreatefromavif($sourcePath);
    }

    $binary = @file_get_contents($sourcePath);
    if ($binary === false || !function_exists('imagecreatefromstring')) {
        return false;
    }
    return @imagecreatefromstring($binary);
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

function clmsResolveStoredUploadPathMeta(string $filePath, bool $mustExist = true): array
{
    $normalized = str_replace('\\', '/', trim($filePath));
    $normalized = preg_replace('#^\./+#', '', $normalized ?? '');
    $normalized = ltrim((string) $normalized, '/');

    if ($normalized === '') {
        throw new InvalidArgumentException('file_path required');
    }
    if (preg_match('#^[A-Za-z]:/#', $normalized) || str_contains($normalized, '..')) {
        throw new InvalidArgumentException('Invalid file_path');
    }
    if (!str_starts_with($normalized, 'uploads/')) {
        throw new InvalidArgumentException('Invalid file_path; only uploaded files are allowed');
    }

    // Must match upload handler: backend/uploads (dirname(__DIR__,1) = backend from backend/api)
    $backendDir = dirname(__DIR__, 1);
    $uploadDir = $backendDir . '/uploads';
    if (!is_dir($uploadDir)) {
        if (!@mkdir($uploadDir, 0755, true)) {
            throw new RuntimeException('Upload directory is not available');
        }
    }
    $uploadRoot = realpath($uploadDir);
    if ($uploadRoot === false) {
        throw new RuntimeException('Upload directory is not available');
    }

    $fullPath = $backendDir . '/' . $normalized;
    if ($mustExist && !is_file($fullPath)) {
        throw new InvalidArgumentException('Uploaded file not found');
    }

    $resolved = realpath($fullPath);
    if ($resolved === false && !$mustExist) {
        $resolvedDir = realpath(dirname($fullPath));
        if ($resolvedDir === false) {
            throw new InvalidArgumentException('Invalid file_path');
        }
        $resolvedDir = str_replace('\\', '/', $resolvedDir);
        if ($resolvedDir !== str_replace('\\', '/', $uploadRoot) && !str_starts_with($resolvedDir, str_replace('\\', '/', $uploadRoot) . '/')) {
            throw new InvalidArgumentException('Invalid file_path');
        }
        return [
            'normalized' => $normalized,
            'backend_dir' => $backendDir,
            'upload_root' => $uploadRoot,
            'full_path' => $fullPath,
            'resolved_path' => null,
        ];
    }

    if ($resolved === false || !str_starts_with(str_replace('\\', '/', $resolved), str_replace('\\', '/', $uploadRoot) . '/')) {
        throw new InvalidArgumentException('Invalid file_path');
    }

    return [
        'normalized' => $normalized,
        'backend_dir' => $backendDir,
        'upload_root' => $uploadRoot,
        'full_path' => $fullPath,
        'resolved_path' => $resolved,
    ];
}

function normalizeStoredUploadPath(string $filePath, bool $mustExist = true): string
{
    try {
        $meta = clmsResolveStoredUploadPathMeta($filePath, $mustExist);
        return $meta['normalized'];
    } catch (InvalidArgumentException $e) {
        jsonError($e->getMessage(), 400);
    } catch (RuntimeException $e) {
        jsonError($e->getMessage(), 500);
    }
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
