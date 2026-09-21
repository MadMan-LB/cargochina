<?php

/** Houssein-approved release policy; authorization still uses current DB roles. */
final class SessionPolicyService
{
    public static function establish(array &$session, int $version, string $credentialFingerprint, ?int $now=null, ?string $database=null): void
    {
        $now??=time();
        $session['clms_app']='cargochina';unset($session['clms_expired_at']);
        $database??=(string)(getenv('DB_NAME')?:($_ENV['DB_NAME']??'clms'));
        $session['clms_session']=['app'=>'cargochina','database'=>$database,'issued_at'=>$now,'last_activity'=>$now,'version'=>$version,'credential'=>$credentialFingerprint];
    }

    public static function privileged(array $roles): bool
    {
        return (bool)array_intersect($roles,['SuperAdmin','ChinaAdmin','LebanonAdmin']);
    }

    public static function validate(array &$session, array $user, array $roles, ?int $now=null, bool $activity=true, ?string $database=null): bool
    {
        $now??=time();$state=$session['clms_session']??null;
        $database??=(string)(getenv('DB_NAME')?:($_ENV['DB_NAME']??'clms'));
        $idle=self::privileged($roles)?1800:28800;
        $absolute=self::privileged($roles)?43200:2592000;
        $valid=is_array($state)&&($state['app']??'')==='cargochina'
            &&($state['database']??null)===$database
            &&is_int($state['issued_at']??null)&&is_int($state['last_activity']??null)
            &&$state['issued_at']<=$now&&$state['last_activity']<=$now
            &&$state['issued_at']<=$state['last_activity']
            &&$now-$state['issued_at']<$absolute&&$now-$state['last_activity']<$idle
            &&($state['version']??null)===(int)$user['session_version']
            &&is_string($state['credential']??null)
            &&hash_equals(hash('sha256',$user['password_hash']),$state['credential']);
        if(!$valid){unset($session['user_id'],$session['user_roles'],$session['user_name'],$session['clms_session']);$session['clms_app']='cargochina';$session['clms_expired_at']=$now;return false;}
        if($activity)$session['clms_session']['last_activity']=$now;
        return true;
    }

    public static function recent(array $session, ?int $now=null): bool
    {
        $issued=$session['clms_session']['issued_at']??null;$now??=time();
        return is_int($issued)&&$issued<=$now&&$now-$issued<900;
    }
}
