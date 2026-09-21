<?php

final class SettingsWriteService
{
    public static function values(PDO $pdo, string $table, bool $lock = false): array
    {
        if (!in_array($table, ['system_config','business_settings'], true)) throw new InvalidArgumentException('Unsupported settings');
        $rows=$pdo->query("SELECT key_name,key_value FROM $table ORDER BY key_name".($lock?' FOR UPDATE':''))->fetchAll(PDO::FETCH_KEY_PAIR);
        return $rows;
    }
    public static function revision(array $values): string
    {
        ksort($values);
        return hash('sha256',json_encode($values,JSON_THROW_ON_ERROR));
    }
    public static function assertCurrent(array $values, array $input): void
    {
        if(!is_string($input['revision']??null)||!hash_equals(self::revision($values),$input['revision']))jsonError('Settings changed. Reload the settings before saving; no changes were applied.',409);
    }
    public static function arrivalDays($value): array
    {
        if(!is_string($value)&&!is_int($value))throw new InvalidArgumentException('Arrival notification days must be comma-separated whole numbers from 0 to 365');
        $parts=explode(',',(string)$value);$days=[];
        foreach($parts as $part){$part=trim($part);if(!preg_match('/^(?:0|[1-9][0-9]{0,2})$/',$part)||(int)$part>365)throw new InvalidArgumentException('Arrival notification days must be comma-separated whole numbers from 0 to 365');$days[]=(int)$part;}
        $days=array_values(array_unique($days));rsort($days,SORT_NUMERIC);return $days;
    }
}
