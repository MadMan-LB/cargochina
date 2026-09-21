<?php
/** Durable business history; diagnostics remain best effort. Never store credentials. */
final class AuditService
{
    public static function redact($value)
    {
        if(!is_array($value))return $value;
        $out=[];foreach($value as $key=>$entry){
            $secret=is_string($key)&&preg_match('/(?:password|secret|authorization|cookie|(?:^|_)token(?:$|_)|api_key|ciphertext|envelope|escrow_keys|credential_fingerprint|reauth|totp|verification_code)/i',$key)&&!preg_match('/_set$/i',$key);
            $out[$key]=$secret?'[redacted]':self::redact($entry);
        }return $out;
    }
    public static function snapshot(PDO $pdo,string $table,int $id,bool $lock=false): ?array
    {
        if(!in_array($table,['products','suppliers','users','supplier_interactions','business_settings'],true))throw new InvalidArgumentException('Unsupported audit entity');
        $s=$pdo->prepare("SELECT * FROM $table WHERE id=?".($lock?' FOR UPDATE':''));$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);
        if($row&&$table==='users'){
            $s=$pdo->prepare('SELECT role_id FROM user_roles WHERE user_id=? ORDER BY role_id');$s->execute([$id]);$row['roles']=$s->fetchAll(PDO::FETCH_COLUMN);
            $s=$pdo->prepare('SELECT department_id,is_primary FROM user_departments WHERE user_id=? ORDER BY department_id');$s->execute([$id]);$row['departments']=$s->fetchAll(PDO::FETCH_ASSOC);
        }
        return $row?self::redact($row):null;
    }
    public static function record(PDO $pdo,string $entity,int $id,string $action,?array $old,?array $new,?int $userId): void
    {
        if(!$pdo->inTransaction())throw new LogicException('Business audit must share its mutation transaction');
        $encode=fn($v)=>$v===null?null:json_encode(self::redact($v),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $pdo->prepare('INSERT INTO audit_log(entity_type,entity_id,action,old_value,new_value,user_id) VALUES (?,?,?,?,?,?)')->execute([$entity,$id,$action,$encode($old),$encode($new),$userId]);
    }
    public static function begin(PDO $pdo): void
    {
        $pdo->beginTransaction();register_shutdown_function(static function()use($pdo){if($pdo->inTransaction())$pdo->rollBack();});
    }
}
