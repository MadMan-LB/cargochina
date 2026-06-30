<?php

require_once __DIR__ . '/session_roles.php';
require_once __DIR__ . '/../backend/config/database.php';

function clmsCustomerFullVisibilityRoles(): array
{
    return ['SuperAdmin', 'ChinaAdmin', 'LebanonAdmin'];
}

function clmsCustomerIdentifier(string $identifier): string
{
    $identifier = trim($identifier);
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/', $identifier)) {
        throw new InvalidArgumentException('Invalid SQL identifier for customer visibility scope');
    }
    return $identifier;
}

function clmsCustomerVisibilityTableExists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        $cache[$table] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$table] = false;
    }
    return $cache[$table];
}

function clmsCustomerCreatedByColumnExists(PDO $pdo): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM customers LIKE 'created_by'");
        $exists = (bool) ($stmt && $stmt->fetchColumn());
    } catch (Throwable $e) {
        $exists = false;
    }
    return $exists;
}

function clmsCurrentCustomerUserId(): ?int
{
    if (function_exists('getAuthUserId')) {
        $userId = getAuthUserId();
        if ($userId) {
            return (int) $userId;
        }
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    return $userId > 0 ? $userId : null;
}

function clmsCustomerRoleCodes(?array $roleCodes = null): array
{
    if ($roleCodes !== null) {
        return $roleCodes;
    }
    if (function_exists('getUserRoles')) {
        return getUserRoles();
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return is_array($_SESSION['user_roles'] ?? null) ? $_SESSION['user_roles'] : [];
}

function clmsCustomerRolesSeeAll(?array $roleCodes = null): bool
{
    return !empty(array_intersect(clmsCustomerRoleCodes($roleCodes), clmsCustomerFullVisibilityRoles()));
}

function clmsGetCustomerVisibilityException(PDO $pdo, int $userId): array
{
    static $cache = [];
    if ($userId <= 0) {
        return ['can_see_all_customers' => false, 'allowed_creator_user_ids' => []];
    }
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    $scope = ['can_see_all_customers' => false, 'allowed_creator_user_ids' => []];
    try {
        if (clmsCustomerVisibilityTableExists($pdo, 'customer_visibility_exceptions')) {
            $stmt = $pdo->prepare("SELECT can_see_all_customers FROM customer_visibility_exceptions WHERE user_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $scope['can_see_all_customers'] = (bool) $stmt->fetchColumn();
        }
        if (clmsCustomerVisibilityTableExists($pdo, 'customer_visibility_allowed_creators')) {
            $stmt = $pdo->prepare("SELECT allowed_creator_user_id FROM customer_visibility_allowed_creators WHERE user_id = ? ORDER BY allowed_creator_user_id");
            $stmt->execute([$userId]);
            $scope['allowed_creator_user_ids'] = array_values(array_unique(array_filter(array_map(
                static fn($row) => (int) ($row['allowed_creator_user_id'] ?? 0),
                $stmt->fetchAll(PDO::FETCH_ASSOC)
            ))));
        }
    } catch (Throwable $e) {
        $scope = ['can_see_all_customers' => false, 'allowed_creator_user_ids' => []];
    }

    $cache[$userId] = $scope;
    return $scope;
}

function clmsUserCanSeeAllCustomers(PDO $pdo, ?int $userId = null, ?array $roleCodes = null): bool
{
    $roleCodes = clmsCustomerRoleCodes($roleCodes);
    if (clmsCustomerRolesSeeAll($roleCodes)) {
        return true;
    }
    $userId = $userId ?: clmsCurrentCustomerUserId();
    if (!$userId) {
        return false;
    }
    $scope = clmsGetCustomerVisibilityException($pdo, $userId);
    return !empty($scope['can_see_all_customers']);
}

function clmsVisibleCustomerCreatorIds(PDO $pdo, ?int $userId = null, ?array $roleCodes = null): array
{
    if (clmsUserCanSeeAllCustomers($pdo, $userId, $roleCodes)) {
        return [];
    }
    $userId = $userId ?: clmsCurrentCustomerUserId();
    if (!$userId) {
        return [];
    }
    $scope = clmsGetCustomerVisibilityException($pdo, $userId);
    return array_values(array_unique(array_merge([$userId], $scope['allowed_creator_user_ids'] ?? [])));
}

function clmsCustomerVisibilityClause(PDO $pdo, string $customerAlias = 'c', ?int $userId = null, ?array $roleCodes = null): array
{
    $customerAlias = clmsCustomerIdentifier($customerAlias);
    if (clmsUserCanSeeAllCustomers($pdo, $userId, $roleCodes)) {
        return ['sql' => '1=1', 'params' => [], 'is_all' => true];
    }
    if (!clmsCustomerCreatedByColumnExists($pdo)) {
        return ['sql' => '1=0', 'params' => [], 'is_all' => false];
    }
    $creatorIds = clmsVisibleCustomerCreatorIds($pdo, $userId, $roleCodes);
    if (!$creatorIds) {
        return ['sql' => '1=0', 'params' => [], 'is_all' => false];
    }
    $placeholders = implode(',', array_fill(0, count($creatorIds), '?'));
    return [
        'sql' => "($customerAlias.created_by IN ($placeholders) OR $customerAlias.created_by IS NULL OR $customerAlias.created_by = 0)",
        'params' => $creatorIds,
        'is_all' => false,
    ];
}

function clmsCustomerIdVisibilityClause(PDO $pdo, string $customerIdExpression, string $scopeAlias = 'cv', ?int $userId = null, ?array $roleCodes = null): array
{
    $customerIdExpression = clmsCustomerIdentifier($customerIdExpression);
    $scopeAlias = clmsCustomerIdentifier($scopeAlias);
    $scope = clmsCustomerVisibilityClause($pdo, $scopeAlias, $userId, $roleCodes);
    if (!empty($scope['is_all'])) {
        return ['sql' => '1=1', 'params' => [], 'is_all' => true];
    }
    return [
        'sql' => "EXISTS (SELECT 1 FROM customers $scopeAlias WHERE $scopeAlias.id = $customerIdExpression AND {$scope['sql']})",
        'params' => $scope['params'],
        'is_all' => false,
    ];
}

function clmsCanAccessCustomer(PDO $pdo, int $customerId, ?int $userId = null, ?array $roleCodes = null): bool
{
    if ($customerId <= 0) {
        return false;
    }
    $scope = clmsCustomerVisibilityClause($pdo, 'c', $userId, $roleCodes);
    $stmt = $pdo->prepare("SELECT 1 FROM customers c WHERE c.id = ? AND {$scope['sql']} LIMIT 1");
    $stmt->execute(array_merge([$customerId], $scope['params']));
    return (bool) $stmt->fetchColumn();
}

function clmsRequireCustomerAccess(PDO $pdo, int $customerId, ?int $userId = null, ?array $roleCodes = null): void
{
    if (!clmsCanAccessCustomer($pdo, $customerId, $userId, $roleCodes)) {
        if (function_exists('jsonError')) {
            jsonError('Customer not found', 404);
        }
        http_response_code(404);
        exit;
    }
}
