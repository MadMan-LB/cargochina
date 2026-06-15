<?php

require_once __DIR__ . '/../backend/config/database.php';

function clmsPermissionOverridesTableExists(?PDO $pdo = null): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }

    try {
        $pdo = $pdo ?: getDb();
        $stmt = $pdo->query("SHOW TABLES LIKE 'user_permission_overrides'");
        $exists = (bool) ($stmt && $stmt->fetchColumn());
    } catch (Throwable $e) {
        $exists = false;
    }

    return $exists;
}

function clmsNormalizePermissionKey(string $permissionKey): string
{
    return strtolower(trim($permissionKey));
}

function clmsGetCurrentUserIdFromSession(): ?int
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    return $userId > 0 ? $userId : null;
}

function clmsGetUserAllowedPermissionKeys(int $userId, ?PDO $pdo = null): array
{
    static $cache = [];
    if ($userId <= 0) {
        return [];
    }
    $cacheKey = $userId;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $cache[$cacheKey] = [];
    try {
        $pdo = $pdo ?: getDb();
        if (!clmsPermissionOverridesTableExists($pdo)) {
            return $cache[$cacheKey];
        }
        $stmt = $pdo->prepare("SELECT permission_key FROM user_permission_overrides WHERE user_id = ? AND is_allowed = 1 ORDER BY permission_key");
        $stmt->execute([$userId]);
        $cache[$cacheKey] = array_values(array_unique(array_map(
            'clmsNormalizePermissionKey',
            array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'permission_key')
        )));
    } catch (Throwable $e) {
        $cache[$cacheKey] = [];
    }

    return $cache[$cacheKey];
}

function clmsUserHasPermissionOverride(string $permissionKey, ?int $userId = null, ?PDO $pdo = null): bool
{
    $userId = $userId ?: clmsGetCurrentUserIdFromSession();
    if (!$userId) {
        return false;
    }
    return in_array(clmsNormalizePermissionKey($permissionKey), clmsGetUserAllowedPermissionKeys($userId, $pdo), true);
}

function clmsRolesGrantPermission(array $roleCodes, array $defaultRoles): bool
{
    return !empty(array_intersect(array_map('strval', $roleCodes), array_map('strval', $defaultRoles)));
}

function clmsUserCan(string $permissionKey, array $defaultRoles = [], ?PDO $pdo = null, ?int $userId = null, ?array $roleCodes = null): bool
{
    if ($roleCodes === null) {
        if (function_exists('getUserRoles')) {
            $roleCodes = getUserRoles();
        } else {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $roleCodes = $_SESSION['user_roles'] ?? [];
        }
    }

    if (in_array('SuperAdmin', $roleCodes, true)) {
        return true;
    }
    if ($defaultRoles && clmsRolesGrantPermission($roleCodes, $defaultRoles)) {
        return true;
    }

    return clmsUserHasPermissionOverride($permissionKey, $userId, $pdo);
}

function clmsRequirePermission(string $permissionKey, array $defaultRoles = [], ?PDO $pdo = null, ?int $userId = null, ?array $roleCodes = null): void
{
    if (!clmsUserCan($permissionKey, $defaultRoles, $pdo, $userId, $roleCodes)) {
        if (function_exists('jsonError')) {
            jsonError('Forbidden', 403);
        }
        http_response_code(403);
        exit;
    }
}
