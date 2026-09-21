<?php
require_once __DIR__.'/OrderWriteService.php';

/** Transactional audit-backed operation keys; external side effects are never performed here. */
final class OperationReplayService
{
    public static function claim(PDO $pdo,string $type,array $input,int $userId): array
    {
        $key=OrderWriteService::requestKey($input['idempotency_key']??null);
        $hash=OrderWriteService::requestHash($input);
        $name='operation:'.substr(hash('sha256',$pdo->query('SELECT DATABASE()')->fetchColumn().':'.$type.':'.$key),0,48);
        $s=$pdo->prepare('SELECT GET_LOCK(?,5)');$s->execute([$name]);if(!(int)$s->fetchColumn())jsonError('Operation is in progress; retry shortly',409);
        register_shutdown_function(static function()use($pdo,$name){$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);});
        // Call before establishing a transaction snapshot; the advisory lock serializes the key.
        $s=$pdo->prepare("SELECT entity_id,user_id,new_value FROM audit_log WHERE entity_type=? AND action='create' AND JSON_UNQUOTE(JSON_EXTRACT(new_value,'$.idempotency_key'))=? ORDER BY id LIMIT 1");$s->execute([$type,$key]);$previous=$s->fetch(PDO::FETCH_ASSOC);
        if($previous){$data=json_decode($previous['new_value'],true);if((int)$previous['user_id']!==$userId||!hash_equals($data['request_hash']??'',$hash))jsonError('Request key belongs to another payload or operator',409);}
        return ['idempotency_key'=>$key,'request_hash'=>$hash,'previous_id'=>$previous?(int)$previous['entity_id']:null,'previous_data'=>$previous?json_decode($previous['new_value'],true):null];
    }
    public static function record(PDO $pdo,string $type,int $id,array $claim,array $details,int $userId): void
    {
        if(!$pdo->inTransaction())throw new LogicException('Operation audit requires an active transaction');
        unset($claim['previous_id'],$claim['previous_data']);$pdo->prepare("INSERT INTO audit_log(entity_type,entity_id,action,new_value,user_id) VALUES (?,?,'create',?,?)")->execute([$type,$id,json_encode($details+$claim,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$userId]);
    }
}
