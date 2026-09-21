<?php
/** RFC 6238; factor and replay/throttle state live outside the database and web root. */
final class OwnerSecondFactorService
{
    public static function code(string $key,int $step):string
    {
        if(strlen($key)<20||$step<0)throw new RuntimeException('Owner factor unavailable');
        $bytes=hash_hmac('sha1',pack('N2',intdiv($step,4294967296),$step%4294967296),$key,true);$offset=ord($bytes[19])&15;$n=unpack('N',substr($bytes,$offset,4))[1]&0x7fffffff;return str_pad((string)($n%1000000),6,'0',STR_PAD_LEFT);
    }
    public static function verify(array $cfg,int $actor,string $code):void
    {
        $key=base64_decode($cfg['owner_totp_keys'][(string)$actor]??'',true);
        $config=realpath(getenv('CLMS_OWNER_CONFIG_FILE')?:'');
        if($key===false||strlen($key)<20||!$config)throw new DomainException('Owner second factor is not configured',503);
        $path=$config.'.mfa-state';$f=fopen($path,'c+b');if(!$f||!flock($f,LOCK_EX))throw new RuntimeException('Owner second factor unavailable');
        try{
            $raw=stream_get_contents($f);$all=$raw===''?[]:json_decode($raw,true,32,JSON_THROW_ON_ERROR);$state=$all[$actor]??['last_step'=>-1,'failures'=>0,'blocked_until'=>0];$now=time();
            if($state['blocked_until']>$now)throw new DomainException('Owner second factor temporarily throttled',429);
            if($state['blocked_until']&&$state['blocked_until']<=$now)$state['failures']=0;
            $matched=null;$step=intdiv($now,30);
            if(preg_match('/^\d{6}$/',$code))foreach([$step-1,$step,$step+1] as $candidate)if($candidate>$state['last_step']&&hash_equals(self::code($key,$candidate),$code)){$matched=$candidate;break;}
            if($matched===null){$state['failures']++;if($state['failures']>=5)$state['blocked_until']=$now+900;}
            else $state=['last_step'=>$matched,'failures'=>0,'blocked_until'=>0];
            $all[$actor]=$state;$encoded=json_encode($all,JSON_THROW_ON_ERROR);rewind($f);if(!ftruncate($f,0)||fwrite($f,$encoded)!==strlen($encoded)||!fflush($f))throw new RuntimeException('Owner second factor state unavailable');
            if($matched===null)throw new DomainException('Invalid, expired or already used owner verification code',403);
        }finally{flock($f,LOCK_UN);fclose($f);}
    }
}
