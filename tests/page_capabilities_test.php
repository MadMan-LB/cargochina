<?php
/** No database writes/connections: exercise the real policy with a fake PDO. */
require_once dirname(__DIR__) . '/includes/sidebar_permissions.php';
require_once dirname(__DIR__) . '/includes/customer_visibility.php';
class PagePolicyStatement extends PDOStatement {
    public function __construct(private string $sql) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetchColumn(int $column = 0): mixed {
        if (str_contains($this->sql, 'system_config')) return json_encode($GLOBALS['pagePolicySettings'] ?? [
            'ContainersStaff'=>['suppliers','expenses'],
            'FieldStaff'=>[], 'ChinaEmployee'=>['procurement_drafts'],
            'WarehouseStaff'=>['hs_code_tax'], 'LebanonAdmin'=>['admin_config'],
        ]);
        return false;
    }
}
class PagePolicyPDO extends PDO {
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new PagePolicyStatement($query); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { return new PagePolicyStatement($query); }
}
$pdo = new PagePolicyPDO();
$count = 0;
function checkPolicy(bool $ok, string $name): void {
    global $count;
    if (!$ok) throw new RuntimeException('FAIL: '.$name);
    ++$count;
}
$can = fn($key,$role) => clmsUserCan($key, ['ChinaEmployee','FieldStaff'], $pdo, 42, [$role]);
foreach (['suppliers.read','suppliers.manage.read','suppliers.create','suppliers.write','suppliers.finance','expenses.write','customers.lookup','orders.read','containers.read'] as $key) {
    checkPolicy($can($key,'ContainersStaff'), 'granted dependency '.$key);
    checkPolicy(!$can($key,'FieldStaff'), 'revocation beats legacy roles '.$key);
}
foreach (['suppliers.create','suppliers.read','suppliers.details.read','customers.create','customers.lookup','products.create','products.read','countries.read'] as $key) {
    checkPolicy($can($key,'ChinaEmployee'), 'draft dependency '.$key);
}
foreach (['suppliers.write','suppliers.manage.read','expenses.write','products.write','customers.write','page:suppliers','page:customers','page:receiving'] as $key) {
    checkPolicy(!$can($key,'ChinaEmployee'), 'draft does not grant unrelated action '.$key);
}
checkPolicy(!clmsCanRolesAccessPage(['FieldStaff'],'orders',$pdo,42),'no forced operational pages');
checkPolicy(clmsCanRolesAccessPage(['FieldStaff','ContainersStaff'],'expenses',$pdo,42),'multi-role union');
checkPolicy(!clmsCanRolesAccessPage(['ContainersStaff'],'admin_users',$pdo,42),'admin stays protected');
checkPolicy(clmsUserCanSeeAllCustomers($pdo,42,['ContainersStaff']),'granted page sees other creators');
checkPolicy(!clmsUserCanSeeAllCustomers($pdo,42,['FieldStaff']),'no data grant for denied role');
checkPolicy($can('products.read','WarehouseStaff'),'HS tax page grants its product search');
checkPolicy(!$can('products.write','WarehouseStaff'),'HS tax lookup grants no product edits');
checkPolicy(!$can('hs-code-catalog.read','LebanonAdmin'),'Nondelegable configuration page cannot grant access to non-admin');
checkPolicy(!$can('products.write','LebanonAdmin'),'Configuration lookup grants no product edits');
foreach (clmsPageCapabilityMap() as $key=>$pages) {
    foreach ($pages as $page) checkPolicy(isset(clmsSidebarPageRegistry()[$page]), 'registered dependency '.$key.':'.$page);
}
$lookupDependencies = [
    'recycle_bin'=>['recycle-bin.read'],
    'orders'=>['orders.read','customers.lookup','suppliers.read','products.read','countries.read'],
    'receiving'=>['orders.read','customers.read','suppliers.read'],
    'warehouse_stock'=>['orders.read','customers.read','suppliers.read'],
    'procurement_drafts'=>['customers.lookup','suppliers.read','products.read','hs-code-catalog.read'],
    'consolidation'=>['orders.read','countries.read','containers.read'],
    'assign_container'=>['orders.read','containers.read'],
    'containers'=>['orders.read','containers.read'],
    'expenses'=>['orders.read','containers.read','suppliers.read'],
    'financials'=>['orders.read'], 'customers'=>['countries.read','orders.read'],
    'suppliers'=>['suppliers.read'], 'products'=>['products.read','suppliers.read','hs-code-catalog.read'],
    'hs_code_tax'=>['products.read','hs-code-catalog.read'],
];
foreach (['ContainersStaff','FieldStaff','ChinaEmployee','WarehouseStaff','LebanonAdmin'] as $role) {
    foreach ($lookupDependencies as $page=>$keys) {
        $GLOBALS['pagePolicySettings']=[$role=>[$page]];
        clmsLoadRoleSidebarPageSettings($pdo,true);
        checkPolicy(clmsCanRolesAccessPage([$role],$page,$pdo,42),'page can be assigned '.$role.':'.$page);
        foreach($keys as $key) checkPolicy($can($key,$role),'page search dependency '.$role.':'.$page.':'.$key);
        checkPolicy(!$can('page:admin_users',$role),'lookup never grants user administration');
    }
}

$GLOBALS['pagePolicySettings']=['FieldStaff'=>['recycle_bin']];
clmsLoadRoleSidebarPageSettings($pdo,true);
foreach(['recycle-bin.restore','shipment-drafts.read','shipment-drafts.write','page:procurement_drafts'] as $key)checkPolicy(!clmsUserCan($key,['SuperAdmin'],$pdo,42,['FieldStaff']),'Bin page does not grant '.$key);
$GLOBALS['pagePolicySettings']=['ChinaAdmin'=>[]];
clmsLoadRoleSidebarPageSettings($pdo,true);
checkPolicy(!clmsUserCan('recycle-bin.read',['ChinaAdmin'],$pdo,42,['ChinaAdmin']),'Revoked bin page beats legacy admin read fallback');
echo "PASS: $count page capability assertions (no live database used)\n";
