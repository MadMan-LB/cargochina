<?php
final class LogRetentionService
{
    public const DAYS=['clms'=>366,'tracking_push'=>366,'php_errors'=>90,'translation_errors'=>90,'performance'=>90];
    public static function path(string $name):string
    {
        if(!isset(self::DAYS[$name]))throw new InvalidArgumentException('Unsupported log category');
        $dir=getenv('DB_NAME')==='clms_hardening_20260919'?sys_get_temp_dir().'/clms-hardening-logs':dirname(__DIR__,2).'/logs';
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Log directory unavailable');
        return $dir.'/'.$name.'-'.gmdate('Y-m-d').'.log';
    }
    public static function expired(string $name,int $mtime,int $now):bool
    {
        if(!preg_match('/^(clms|tracking_push|php_errors|translation_errors|performance)-(\d{4}-\d{2}-\d{2})\.log$/',$name,$m))return false;
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$m[2],new DateTimeZone('UTC'));
        if(!$date||$date->format('Y-m-d')!==$m[2])return false;
        $cutoff=$now-self::DAYS[$m[1]]*86400;return $date->getTimestamp()+86400<=$cutoff&&$mtime<=$cutoff;
    }
}
