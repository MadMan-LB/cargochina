<?php
require_once __DIR__ . '/helpers.php';

function clmsAuthorizeApiRequest(string $resource, string $method, ?string $id, ?string $action): void
{
    if (!in_array($method,['GET','POST','PUT','DELETE'],true)) jsonError('Method not allowed',405);
// RBAC: public resources skip auth
$rbac = require dirname(__DIR__, 2) . '/backend/config/rbac.php';
$operationalRoles = ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'ContainersStaff', 'FieldStaff', 'SuperAdmin'];
$publicResources = $rbac['public'] ?? [];
if (!in_array($resource, $publicResources)) {
    $userId = getAuthUserId();
    if (!$userId) {
        jsonError('Unauthorized', 401);
    }
    if ($id !== null && preg_match('/^[0-9+-]/',$id) && (!ctype_digit($id) || (float)$id<1 || (float)$id>4294967295)) jsonError('Invalid resource identity',422);
    if (in_array($resource,['translate','translations'],true)) requirePermission('translations.write',$operationalRoles);
    if ($resource==='order-templates') requirePermission($method==='GET'?'orders.read':'orders.write');
    $timingRequested = (string) ($_SERVER['HTTP_X_CLMS_DEBUG_TIMING'] ?? '') === '1'
        || (string) ($_GET['debug_timing'] ?? '') === '1';
    $GLOBALS['__clms_api_timing_debug'] = $timingRequested && hasAnyRole(['SuperAdmin']);
    if ($resource === 'orders' && $action === 'approve' && !hasPermission('orders.approve', $rbac['orders']['approve'] ?? [])) {
        jsonError('Forbidden', 403);
    }
    if ($resource === 'orders' && $action === 'receive' && !hasPermission('orders.receive', $rbac['orders']['receive'] ?? [])) {
        jsonError('Forbidden', 403);
    }
    if ($resource === 'orders' && $action === 'confirm' && !hasPermission('orders.confirm', $rbac['orders']['confirm'] ?? [])) {
        jsonError('Forbidden', 403);
    }
    if ($resource === 'orders' && $method === 'POST' && $id === 'bulk-export'
        && !hasPermission('orders.read', $rbac['orders']['read'] ?? $operationalRoles)) {
        jsonError('Forbidden', 403);
    }
    if ($resource === 'balances') {
        $userRoles = getUserRoles();
        if (!in_array('SuperAdmin', $userRoles, true) && !clmsCanRolesAccessPage($userRoles, 'balances', null, $userId)) {
            http_response_code(403);
            echo json_encode(['error' => true, 'message' => clmsT('You do not have permission')]);
            exit;
        }
    }

    $resourcePermissions = $rbac[$resource] ?? null;
    $isDraftOrderDownload = $resource === 'draft-orders' && $method === 'GET' && $id !== null && ctype_digit($id) && $action === 'export';
    if ($isDraftOrderDownload) requirePermission('orders.read');
    // Shared capability policy includes the data dependencies of granted pages.
    $permissionKey = $method === 'GET' ? 'read' : 'write';
    if ($resource === 'products' && $method === 'POST' && $id === null) {
        requirePermission('products.create');
    }
    if ($resource === 'containers' && $action === 'assign-orders') {
        requirePermission('containers.assign');
    }
    if ($resource === 'customers' && $method === 'POST' && $action === 'deposits') {
        requirePermission('customers.finance');
    }
    $skipGenericPermission = ($resource === 'orders' && (in_array($action, ['approve', 'receive', 'confirm'], true) || ($method === 'POST' && $id === 'bulk-export')))
        || ($resource === 'customers' && ($method === 'GET' || ($method === 'POST' && ($id === null || $id === 'import'))))
        || ($resource === 'products' && $method === 'POST' && ($id === null || $id === 'import'))
        || ($resource === 'containers' && $action === 'assign-orders')
        || ($resource === 'customers' && $method === 'POST' && $action === 'deposits')
        || $resource === 'balances' || $isDraftOrderDownload;
    if (
        !$skipGenericPermission &&
        is_array($resourcePermissions) &&
        array_key_exists($permissionKey, $resourcePermissions) &&
        !hasPermission($resource . '.' . $permissionKey, $resourcePermissions[$permissionKey] ?? [])
    ) {
        jsonError('Forbidden', 403);
    }
    if ($resource === 'users' && !hasAnyRole($rbac['users'] ?? [])) {
        jsonError('Forbidden', 403);
    }
    if ($resource === 'roles' && !hasAnyRole($rbac['users'] ?? [])) {
        jsonError('Forbidden', 403);
    }
    if ($resource === 'config' && $id !== 'receiving' && $id !== 'upload' && $id !== 'container-presets' && $id !== 'eta-offsets' && !hasAnyRole($rbac['config'] ?? [])) {
        jsonError('Forbidden', 403);
    }
    if (($resource === 'config' && ($id === 'container-presets' || $id === 'eta-offsets')) && !hasPermission('containers.read')) {
        jsonError('Forbidden', 403);
    }
    if ($resource === 'config' && $id === 'receiving' && !hasPermission('page:receiving', $operationalRoles)) {
        jsonError('Forbidden', 403);
    }
    if ($resource === 'customers' && $method === 'GET') {
        $userRoles = getUserRoles();
        $isLookup = $id === 'lookup' || $action === 'lookup';
        $allowed = hasPermission($isLookup ? 'customers.lookup' : 'customers.read');
        if (!$allowed) {
            jsonError('Forbidden', 403);
        }
    }
    if ($resource === 'customers' && $method === 'POST' && $id === null && !hasPermission('customers.create', $rbac['customers']['create'] ?? $operationalRoles)) {
        jsonError('Forbidden', 403);
    }
    if ($resource === 'customers' && $id === 'import' && !hasPermission('customers.import', $rbac['customers']['import'] ?? ['ChinaAdmin', 'SuperAdmin'])) {
        jsonError('Forbidden', 403);
    }
    if ($resource === 'suppliers' && $id === 'import' && !hasPermission('suppliers.import')) {
        jsonError('Forbidden', 403);
    }
    if ($resource === 'products' && $id === 'import' && !hasPermission('products.import')) {
        jsonError('Forbidden', 403);
    }
    if ($resource === 'shipment-drafts' && $action === 'push' && !hasPermission('shipment-drafts.push')) {
        jsonError('Forbidden', 403);
    }
    if ($resource === 'shipment-drafts' && $action === 'finalize' && !hasPermission('shipment-drafts.finalize')) {
        jsonError('Forbidden', 403);
    }
    if ($resource === 'tracking-push-log' && !hasAnyRole(['LebanonAdmin', 'SuperAdmin'])) {
        jsonError('Forbidden', 403);
    }
    if ($resource === 'diagnostics' && !hasAnyRole(['SuperAdmin'])) {
        jsonError('Forbidden', 403);
    }
    if ($resource === 'receiving' && $id === 'import' && !hasPermission('receiving.import', $operationalRoles)) {
        jsonError('Forbidden', 403);
    }
    if ($resource === 'receiving' && !hasPermission('page:receiving', $operationalRoles)) {
        jsonError('Forbidden', 403);
    }
    if ($resource === 'expenses' && !hasPageAccess('expenses')) {
        jsonError('Forbidden', 403);
    }
    if ($resource === 'financials' && (!hasPageAccess('financials') || ($method !== 'GET' && !hasAnyRole($rbac['financials'] ?? [])))) {
        jsonError('Forbidden', 403);
    }
    if ($resource === 'internal-messages' && !hasPermission('internal-messages')) {
        jsonError('Forbidden', 403);
    }
    if ($resource === 'warehouse-stock' && (!hasPageAccess('warehouse_stock') || ($method !== 'GET' && !hasAnyRole($rbac['warehouse-stock'] ?? [])))) {
        jsonError('Forbidden', 403);
    }
    if ($resource === 'procurement-drafts' && !hasPermission('page:procurement_drafts', $rbac['procurement-drafts'] ?? [])) {
        jsonError('Forbidden', 403);
    }
    if ($resource === 'business-settings' && !hasAnyRole($rbac['business-settings'] ?? [])) {
        jsonError('Forbidden', 403);
    }
    if ($resource === 'customer-portal-tokens' && !hasPermission('customer-portal-tokens')) {
        jsonError('Forbidden', 403);
    }
    if ($resource === 'design-attachments' && !hasPermission('design-attachments')) {
        jsonError('Forbidden', 403);
    }
    if ($resource === 'draft-orders' && !$isDraftOrderDownload && !hasPermission('page:procurement_drafts', $rbac['draft-orders'] ?? [])) {
        jsonError('Forbidden', 403);
    }
}

}
