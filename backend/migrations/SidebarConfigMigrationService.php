<?php

/** Portable equivalent of migrations 062/065 sidebar JSON operations. */
final class SidebarConfigMigrationService
{
    public static function apply(PDO $pdo, bool $withDefaults): void
    {
        $stmt=$pdo->prepare("SELECT key_value FROM system_config WHERE key_name='ROLE_SIDEBAR_PAGES_JSON' LIMIT 1");
        $stmt->execute();
        $raw=$stmt->fetchColumn();
        if ($raw === false && !$withDefaults) return;
        $defaults = [
            'ChinaAdmin'=>['dashboard','orders','pipeline','consolidation','containers','assign_container','expenses','financials','balances','hs_code_tax','calendar','warehouse_stock','procurement_drafts','downloads','suppliers','customers','products','notifications','notification_preferences'],
            'ChinaEmployee'=>['dashboard','orders','balances','procurement_drafts','downloads','suppliers','products','notifications'],
            'LebanonAdmin'=>['dashboard','pipeline','consolidation','containers','assign_container','expenses','financials','balances','hs_code_tax','calendar','warehouse_stock','downloads','notifications','notification_preferences'],
            'WarehouseStaff'=>['dashboard','receiving','expenses','warehouse_stock','downloads','notifications'],
            'ContainersStaff'=>['consolidation','containers','assign_container','warehouse_stock'],
            'FieldStaff'=>['dashboard','suppliers','notifications'],
        ];
        $settings=is_string($raw)?json_decode($raw,true):null;
        if (!is_array($settings) || array_is_list($settings)) {
            if (!$withDefaults) return;
            $settings=$defaults;
        }
        foreach (['ChinaAdmin','ChinaEmployee','LebanonAdmin'] as $role) {
            $pages=$settings[$role]??null;
            if (!is_array($pages) || !array_is_list($pages)) {
                if (!$withDefaults) $pages=[];
                else $pages=$defaults[$role];
            }
            if (!in_array('balances',$pages,true)) $pages[]='balances';
            $settings[$role]=$pages;
        }
        $encoded=json_encode($settings,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if ($raw === false) {
            $pdo->prepare("INSERT INTO system_config(key_name,key_value) VALUES ('ROLE_SIDEBAR_PAGES_JSON',?)")->execute([$encoded]);
        } elseif ($encoded !== $raw) {
            $pdo->prepare("UPDATE system_config SET key_value=? WHERE key_name='ROLE_SIDEBAR_PAGES_JSON'")->execute([$encoded]);
        }
    }
}
