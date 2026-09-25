<?php
require_once dirname(__DIR__).'/services/LogRetentionService.php';
require_once dirname(__DIR__) . '/services/PackingListItemNumber.php';

require_once dirname(__DIR__) . '/services/DecimalMath.php';

/**
 * API response helpers
 */

if (!function_exists('clmsT')) {
    if (!defined('CLMS_I18N_DISABLE_AUTO_SWITCH')) {
        define('CLMS_I18N_DISABLE_AUTO_SWITCH', true);
    }
    require_once dirname(__DIR__, 2) . '/includes/i18n.php';
}
require_once dirname(__DIR__, 2) . '/includes/session_roles.php';
require_once dirname(__DIR__, 2) . '/includes/permission_overrides.php';
require_once dirname(__DIR__, 2) . '/includes/customer_visibility.php';

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
    @error_log($line, 3, LogRetentionService::path('performance'));
}

function jsonResponse(array $data, int $status = 200): void
{
    $retry=$_SERVER['HTTP_X_CLMS_RETRY_OF']??'';
    if($status<400&&is_string($retry)&&preg_match('/^[a-f0-9]{16,32}$/',$retry)&&!empty($_SESSION['user_id'])){
        $uid=(int)$_SESSION['user_id'];$parts=explode('/',trim($_GET['path']??'','/'));$workflow=$parts[0]??'';$action=($_SERVER['REQUEST_METHOD']??'GET').'/'.($parts[2]??'');$entity=ctype_digit((string)($parts[1]??''))?(int)$parts[1]:null;$entities=$GLOBALS['clms_incident_entities']??[];if($entity===null&&$entities)$entity=reset($entities);
        register_shutdown_function(static function()use($retry,$uid,$workflow,$action,$entity){try{$p=clmsNewDbConnection();$p->prepare("UPDATE owner_incident_events e JOIN owner_incidents i ON i.id=e.incident_id SET e.retry_result='same_action_succeeded' WHERE e.request_id=? AND e.user_id=? AND i.workflow=? AND i.action_name=? AND e.entity_id <=> ?")->execute([$retry,$uid,$workflow,$action,$entity]);}catch(Throwable $e){error_log('CLMS retry observation unavailable');}});
    }
    http_response_code($status);
    clmsFinalizeApiTiming($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Authenticated responses must reflect current permissions and business state. */
function setCacheHeaders(int $maxAgeSeconds = 60): void
{
    // Keep the argument for existing callers; no authenticated response TTL is safe here.
    header('Cache-Control: private, no-store, max-age=0');
}

function jsonError(string $message, int $status = 400, array $errors = [], ?string $requestId = null): void
{
    $requestId = $requestId ?? bin2hex(random_bytes(8));
    $GLOBALS['__clms_api_request_id'] = $requestId;
    if ($status >= 500) {
        error_log('API error ['.$requestId.'] status='.$status);
        $message = 'An error occurred. Please try again or contact support. (ref: '.$requestId.')';
        $errors = [];
    }
    $localizedMessage = function_exists('clmsT') ? clmsT($message) : $message;
    require_once dirname(__DIR__).'/services/OwnerIncidentService.php';
    OwnerIncidentService::capture($status,$requestId,$message);
    $body = ['error' => true, 'message' => $localizedMessage, 'request_id' => $requestId];
    if (!empty($errors)) {
        $body['errors'] = array_map(
            static fn($error) => is_string($error) && function_exists('clmsT') ? clmsT($error) : $error,
            $errors
        );
    }
    jsonResponse($body, $status);
}

/** Normalize human-entered search text while preserving English/Chinese content. */
function clmsNormalizeSearchQuery($value, int $maxLength = 200): string
{
    $query = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';
    return mb_substr($query, 0, max(1, $maxLength));
}

/** Build a contains pattern where spaces may match intervening words. */
function clmsSpreadsheetCell($value)
{
    return is_string($value)&&preg_match('/^[\s\x00-\x1f]*[=+@-]/u',$value)?"'".$value:$value;
}

/** Export every untrusted CSV cell as data, including headers and metadata. */
function clmsWriteCsv($stream,array $row): int|false
{
    return fputcsv($stream,array_map('clmsSpreadsheetCell',$row),',','"','');
}

function clmsExportFormat(string $default='xlsx'): string
{
    $format=$_GET['format']??$default;
    if(!is_string($format)||!in_array(strtolower(trim($format)),['csv','xlsx'],true))jsonError('Unsupported export format',422);
    return strtolower(trim($format));
}

/** One consistent database view for a multi-query report, with automatic cleanup on errors. */
function clmsBeginExportSnapshot(PDO $pdo): void
{
    if($pdo->inTransaction())return;
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->beginTransaction();
    register_shutdown_function(static function()use($pdo){if($pdo->inTransaction())$pdo->rollBack();});
}

function clmsSearchLike(string $query): string
{
    $query = clmsNormalizeSearchQuery($query);
    $query = strtr($query, ['\\'=>'\\\\', '%'=>'\\%', '_'=>'\\_']);
    return '%' . preg_replace('/\s+/u', '%', $query) . '%';
}

/**
 * Force legacy utf8 and utf8mb4 columns to one safe comparison character set.
 * Applying an utf8mb4 collation directly to an utf8 column raises MySQL 1253.
 */
function clmsUtf8SearchExpr(string $sqlExpression): string
{
    return "CONVERT($sqlExpression USING utf8mb4) COLLATE utf8mb4_unicode_ci";
}

function clmsQueryLimit($value, int $default = 50, int $maximum = 200): int
{
    if ($value === null || $value === '') {
        return max(1, min($maximum, $default));
    }
    if (filter_var($value,FILTER_VALIDATE_INT) === false || (int)$value < 1) jsonError('Invalid limit',422);
    return max(1, min($maximum, (int) $value));
}

/** Two parameters: numeric supplier ID as JSON, then its legacy string representation. */
function clmsSharedCartonSupplierPredicate(string $column, ?PDO $pdo = null, ?int $supplierId = null, ?array &$params = null): string
{
    if (!preg_match('/^[a-z_][a-z0-9_]*\.shared_carton_contents$/i', $column)) throw new InvalidArgumentException('Invalid shared-carton column.');
    if ($pdo !== null) {
        if ($supplierId === null || $params === null) throw new InvalidArgumentException('Supplier and parameters required.');
        if (!clmsSupportsJsonSearch($pdo)) {
            $matches = clmsSharedCartonMatchingIds($pdo, static fn(array $content): bool =>
                (is_int($content['supplier_id'] ?? null) || is_string($content['supplier_id'] ?? null))
                && (string) $content['supplier_id'] === (string) $supplierId);
            $params[] = implode(',', $matches);
            $alias = explode('.', $column)[0];
            return "FIND_IN_SET($alias.id, ?) > 0";
        }
        array_push($params, (string) $supplierId, (string) $supplierId);
    }
    $ids = "COALESCE(JSON_EXTRACT(CASE WHEN JSON_VALID($column) THEN $column ELSE '[]' END, '$[*].supplier_id'), '[]')";
    return "(JSON_CONTAINS($ids, ?) OR JSON_CONTAINS($ids, JSON_QUOTE(?)))";
}

function clmsQueryOffset($value, int $maximum = 10000000): int
{
    if ($value !== null && $value !== '' && (filter_var($value,FILTER_VALIDATE_INT) === false || (int)$value < 0)) jsonError('Invalid offset',422);
    return max(0, min($maximum, (int) ($value ?? 0)));
}

function clmsQuerySort($value, array $allowed, string $default): string
{
    $candidate = (string) ($value ?? '');
    if ($candidate !== '' && !in_array($candidate,$allowed,true)) jsonError('Invalid sort field',422);
    return $candidate !== '' ? $candidate : $default;
}

function clmsQueryDirection($value, string $default = 'ASC'): string
{
    $direction = strtoupper((string) ($value ?? $default));
    return $direction === 'DESC' ? 'DESC' : 'ASC';
}

function clmsNormalizeItemTypeFilter($value): ?string
{
    $code = strtolower(trim((string) $value));
    $allowed = ['normal', 'replica', 'cosmetics', 'branded', 'food', 'dangerous', 'other', 'unclassified'];
    if ($code !== '' && !in_array($code,$allowed,true)) jsonError('Invalid item type filter',422);
    return $code !== '' ? $code : null;
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
    clmsRefreshSessionRolesFromDb();
    if (!empty($GLOBALS['clms_auth_verification_failed'])) return null;
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

function getUserRoles(): array
{
    ensureSession();
    clmsRefreshSessionRolesFromDb();
    return $_SESSION['user_roles'] ?? [];
}

function requireRecentAuthentication(): void
{
    getAuthUserId();
    require_once dirname(__DIR__).'/services/SessionPolicyService.php';
    if(!SessionPolicyService::recent($_SESSION))jsonError('Recent sign-in required for this administrative change. Sign in again in another tab, then retry; your changes have not been applied.',403);
}

function hasRole(string $role): bool
{
    return in_array($role, getUserRoles(), true);
}

function hasAnyRole(array $roles): bool
{
    return !empty(array_intersect($roles, getUserRoles()));
}

function hasPermission(string $permissionKey, array $defaultRoles = []): bool
{
    return clmsUserCan($permissionKey, $defaultRoles, null, getAuthUserId(), getUserRoles());
}

/** Use the same live, request-scoped page policy as the sidebar and page guard. */
function hasPageAccess(string ...$pageIds): bool
{
    require_once dirname(__DIR__, 2) . '/includes/sidebar_permissions.php';
    $userId = getAuthUserId();
    if (!$userId) return false;
    $roles = getUserRoles();
    foreach ($pageIds as $pageId) {
        if (clmsCanRolesAccessPage($roles, $pageId, null, $userId)) return true;
    }
    return false;
}

function requirePageAccess(string ...$pageIds): void
{
    if (!hasPageAccess(...$pageIds)) jsonError('Forbidden', 403);
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

function requirePermission(string $permissionKey, array $defaultRoles = []): void
{
    if (!hasPermission($permissionKey, $defaultRoles)) {
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

        if (!isset($meta['normalized']) || !is_string($meta['normalized'])) {
            jsonError('Invalid stored upload path metadata.', 500);
            exit;
        }

        clmsAuthorizeUploadForCurrentActor($meta['normalized']);
        return $meta['normalized'];
    } catch (InvalidArgumentException $e) {
        jsonError($e->getMessage(), 400);
        exit;
    } catch (RuntimeException $e) {
        jsonError($e->getMessage(), 500);
        exit;
    }
}

function clmsAuthorizeUploadForCurrentActor(string $path): void
{
    if (session_status() === PHP_SESSION_ACTIVE && getAuthUserId()) {
        require_once dirname(__DIR__) . '/services/UploadAccessService.php';
        UploadAccessService::authorize(getDb(),$path);
    }
}

function clmsNormalizeImagePathList($value): array
{
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }
        $decoded = json_decode($trimmed, true);
        $value = is_array($decoded) ? $decoded : [$trimmed];
    }
    if (!is_array($value)) {
        return [];
    }

    $paths = [];
    foreach ($value as $path) {
        if (is_array($path)) {
            $path = $path['file_path'] ?? $path['path'] ?? $path['url'] ?? '';
        }
        $path = trim((string) $path);
        if ($path !== '') {
            $paths[$path] = true;
        }
    }
    return array_keys($paths);
}

function clmsMergeImagePathLists(...$sources): array
{
    $merged = [];
    foreach ($sources as $source) {
        foreach (clmsNormalizeImagePathList($source) as $path) {
            $merged[$path] = true;
        }
    }
    return array_keys($merged);
}

function clmsProductImagePaths(PDO $pdo, array $productIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn(int $id): bool => $id > 0)));
    if (!$ids) {
        return [];
    }

    try {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, image_paths FROM products WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $paths = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $productId = (int) ($row['id'] ?? 0);
            if ($productId > 0) {
                $paths[$productId] = clmsNormalizeImagePathList($row['image_paths'] ?? []);
            }
        }
        return $paths;
    } catch (Throwable $e) {
        return [];
    }
}

function clmsHydrateSharedCartonImagePaths(PDO $pdo, array $contents): array
{
    $productImages = clmsProductImagePaths($pdo, array_map(
        static fn($content): int => is_array($content) ? (int) ($content['product_id'] ?? 0) : 0,
        $contents
    ));

    foreach ($contents as &$content) {
        if (!is_array($content)) {
            $content = [];
            continue;
        }
        $productId = (int) ($content['product_id'] ?? 0);
        $content['product_image_paths'] = $productImages[$productId] ?? [];
        $content['image_paths'] = clmsMergeImagePathLists(
            $content['image_paths'] ?? [],
            $content['photo_paths'] ?? [],
            $content['product_image_paths']
        );
    }
    unset($content);

    return $contents;
}

function clmsCollectSharedCartonImagePaths(array $contents): array
{
    $paths = [];
    foreach ($contents as $content) {
        if (!is_array($content)) {
            continue;
        }
        $paths = clmsMergeImagePathLists(
            $paths,
            $content['image_paths'] ?? [],
            $content['photo_paths'] ?? []
        );
    }
    return $paths;
}

function clmsSharedCartonImagePaths(PDO $pdo, $contents): array
{
    if (is_string($contents)) {
        $contents = json_decode($contents, true) ?: [];
    }
    if (!is_array($contents) || !$contents) {
        return [];
    }
    return clmsCollectSharedCartonImagePaths(clmsHydrateSharedCartonImagePaths($pdo, $contents));
}

function clmsReceiptItemImagePaths(PDO $pdo, array $orderItemIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $orderItemIds), static fn(int $id): bool => $id > 0)));
    if (!$ids) {
        return [];
    }

    try {
        $hasVoidedAt = false;
        $column = $pdo->query("SHOW COLUMNS FROM warehouse_receipts LIKE 'voided_at'");
        if ($column) {
            $hasVoidedAt = $column->rowCount() > 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT wri.order_item_id, wrip.file_path
                FROM warehouse_receipt_item_photos wrip
                JOIN warehouse_receipt_items wri ON wri.id = wrip.receipt_item_id
                JOIN warehouse_receipts wr ON wr.id = wri.receipt_id
                WHERE wri.order_item_id IN ($placeholders)";
        if ($hasVoidedAt) {
            $sql .= ' AND wr.voided_at IS NULL';
        }
        $sql .= ' ORDER BY wrip.id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $paths = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $itemId = (int) ($row['order_item_id'] ?? 0);
            $path = trim((string) ($row['file_path'] ?? ''));
            if ($itemId > 0 && $path !== '') {
                $paths[$itemId][$path] = true;
            }
        }
        return array_map('array_keys', $paths);
    } catch (Throwable $e) {
        return [];
    }
}

function clmsOrderReceiptImagePaths(PDO $pdo, array $orderIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds), static fn(int $id): bool => $id > 0)));
    if (!$ids) {
        return [];
    }

    try {
        $hasVoidedAt = false;
        $column = $pdo->query("SHOW COLUMNS FROM warehouse_receipts LIKE 'voided_at'");
        if ($column) {
            $hasVoidedAt = $column->rowCount() > 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT wr.order_id, wrp.file_path
                FROM warehouse_receipt_photos wrp
                JOIN warehouse_receipts wr ON wr.id = wrp.receipt_id
                WHERE wr.order_id IN ($placeholders)";
        if ($hasVoidedAt) {
            $sql .= ' AND wr.voided_at IS NULL';
        }
        $sql .= ' ORDER BY wr.received_at DESC, wrp.id ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $paths = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $orderId = (int) ($row['order_id'] ?? 0);
            $path = trim((string) ($row['file_path'] ?? ''));
            if ($orderId > 0 && $path !== '') {
                $paths[$orderId][$path] = true;
            }
        }
        return array_map('array_keys', $paths);
    } catch (Throwable $e) {
        return [];
    }
}

function normalizeStoredUploadPathList(array $paths, bool $mustExist = true): array
{
    $normalized = [];
    foreach ($paths as $path) {
        if (!is_string($path) || trim($path) === '') {
            continue;
        }
        $normalized[] = normalizeStoredUploadPath($path, $mustExist);
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
    require_once dirname(__DIR__).'/services/AuditService.php';
    $line = date('Y-m-d H:i:s') . ' ' . json_encode(AuditService::redact(array_merge(['event' => $event], $context)), JSON_UNESCAPED_UNICODE) . "\n";
    @error_log($line, 3, LogRetentionService::path('clms'));
}
function clmsFinancialDecimal($value, string $label = 'Amount', bool $allowZero = false, int $scale = 4): string
{
    try {
        $normalized = DecimalMath::round($value, $scale);
    } catch (Throwable $e) {
        jsonError($label . ' must be a valid decimal value', 400);
    }
    $comparison = DecimalMath::compare($normalized, '0', $scale);
    if ($comparison < 0 || (!$allowZero && $comparison === 0)) {
        jsonError($label . ($allowZero ? ' cannot be negative' : ' must be positive'), 400);
    }
    return $normalized;
}
