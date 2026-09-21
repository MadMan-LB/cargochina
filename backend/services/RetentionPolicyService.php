<?php
/** Approved baseline. A hold always wins; age alone never authorizes destroying business history. */
final class RetentionPolicyService
{
    public const DAYS=['cargo'=>3653,'financial'=>3653,'uploads'=>3653,'audit'=>2557,'exports'=>90,'security_logs'=>366,'diagnostic_logs'=>90,'disabled_identity'=>2557,'expired_sessions'=>90];
    public static function held(PDO $p,string $scope,string $reference): bool
    {
        $s=$p->prepare("SELECT 1 FROM retention_holds WHERE released_at IS NULL AND (scope_type='all' OR (scope_type=? AND scope_reference IN ('*',?))) LIMIT 1");$s->execute([$scope,$reference]);return (bool)$s->fetchColumn();
    }
    public static function keepBackups(array $generations,?int $now=null):array
    {
        $now??=time();usort($generations,fn($a,$b)=>$b['timestamp']<=>$a['timestamp']);$keep=[];$daily=[];$monthly=[];$annual=[];
        foreach($generations as $g){$t=$g['timestamp'];$day=gmdate('Y-m-d',$t);$month=gmdate('Y-m',$t);$year=gmdate('Y',$t);
            $retain=!empty($g['held'])||$t>=$now-35*86400;
            foreach([['day',$day,35],['month',$month,12],['year',$year,3]] as [$kind,$bucket,$limit]){
                if($kind==='day')$set=&$daily;elseif($kind==='month')$set=&$monthly;else $set=&$annual;
                if(!isset($set[$bucket])&&count($set)<$limit){$set[$bucket]=true;$retain=true;}unset($set);
            }
            if($retain)$keep[]=$g['id'];
        }return $keep;
    }
    public static function sessionPurgeEligible(string $contents,int $mtime,int $now):bool
    {
        // Shared PHP directory: only marked CargoChina sessions whose expiry was actually recorded.
        // A stale mtime alone cannot prove session ownership or expiry.
        if(!str_contains($contents,'clms_app|s:10:"cargochina";'))return false;
        if(!preg_match('/(?:^|;)clms_expired_at\|i:(\d+);/',$contents,$m))return false;
        return !str_contains($contents,'user_id|')&&(int)$m[1]<=$now-90*86400&&$mtime<=$now-90*86400;
    }
}
