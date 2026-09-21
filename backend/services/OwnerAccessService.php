<?php
/** File is provisioned outside the web root by the deployment owner, never via an API. */
final class OwnerAccessService
{
    public static function config(): array
    {
        $path=getenv('CLMS_OWNER_CONFIG_FILE')?:'';
        if($path==='')return [];
        $real=realpath($path);$root=realpath(dirname(__DIR__,2));
        $documentRoot=trim($_SERVER['DOCUMENT_ROOT']??'');$web=realpath($documentRoot!==''?$documentRoot:dirname($root));
        foreach(array_filter([$root,$web]) as $denied)if($real&&str_starts_with(strtolower(str_replace('\\','/',$real)),strtolower(str_replace('\\','/',$denied)).'/'))throw new RuntimeException('Owner configuration must be outside the web root');
        if(!$real||!is_file($real))throw new RuntimeException('Owner configuration unavailable');
        $value=json_decode(file_get_contents($real),true,32,JSON_THROW_ON_ERROR);
        if(!is_array($value))throw new RuntimeException('Invalid owner configuration');
        return $value;
    }
    public static function allowed(PDO $pdo,int $userId): bool
    {
        $cfg=self::config();
        if(empty($cfg['owner_verified'])||!in_array($userId,$cfg['owner_ids']??[],true))return false;
        $s=$pdo->prepare("SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE u.id=? AND u.is_active=1 AND r.code='SuperAdmin'");$s->execute([$userId]);return (bool)$s->fetchColumn();
    }
    public static function requireOwner(PDO $pdo,int $userId): void
    {
        if(!self::allowed($pdo,$userId))throw new DomainException('Verified owner access required',403);
    }
}
