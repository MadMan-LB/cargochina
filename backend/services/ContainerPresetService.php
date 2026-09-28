<?php

final class ContainerPresetService
{
    /** Keep existing setting keys so deployed capacity settings remain authoritative. */
    public static function all(PDO $pdo): array
    {
        $values = ['CONTAINER_20HQ_CBM' => 28, 'CONTAINER_40HQ_CBM' => 68, 'CONTAINER_45HQ_CBM' => 78];
        $table = $pdo->query("SHOW TABLES LIKE 'business_settings'");
        if ($table->fetchColumn()) {
            $rows = $pdo->query("SELECT key_name,key_value FROM business_settings WHERE key_name IN ('CONTAINER_20HQ_CBM','CONTAINER_40HQ_CBM','CONTAINER_45HQ_CBM')");
            foreach ($rows as $row) {
                $value = $row['key_value'];
                if (!is_numeric($value) || !is_finite((float) $value) || (float) $value <= 0) {
                    throw new RuntimeException('Invalid container capacity in Business Settings');
                }
                $values[$row['key_name']] = (float) $value;
            }
        }
        $presets = [];
        foreach ([20, 40, 45] as $size) {
            $presets[$size . 'GP'] = ['max_cbm' => $values['CONTAINER_' . $size . 'HQ_CBM'], 'max_weight' => 28000];
        }
        return $presets;
    }
}
