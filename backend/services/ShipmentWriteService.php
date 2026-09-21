<?php
require_once __DIR__.'/OrderWriteService.php';

final class ShipmentWriteService
{
    public static function revision(array $draft): string
    {
        $values=[];foreach(['id','status','container_id','container_number','booking_number','tracking_url'] as $field)$values[$field]=(string)($draft[$field]??'');
        return hash('sha256',json_encode($values,JSON_THROW_ON_ERROR));
    }
    public static function refs(array $input): array
    {
        foreach(['container_number'=>50,'booking_number'=>100,'tracking_url'=>500] as $field=>$max){
            if(!array_key_exists($field,$input))continue;
            if($input[$field]!==null&&!is_string($input[$field]))jsonError("$field must be text",422);
            $input[$field]=trim($input[$field]??'')?:null;if(mb_strlen($input[$field]??'')>$max)jsonError("$field exceeds its supported length",422);
        }
        if(!empty($input['tracking_url'])){
            $url=parse_url($input['tracking_url']);if(!$url||!in_array(strtolower($url['scheme']??''),['http','https'],true)||empty($url['host'])||isset($url['user'])||isset($url['pass'])||preg_match('/[\x00-\x20\x7f]/',$input['tracking_url']))jsonError('Tracking URL must be an HTTP or HTTPS address without credentials',422);
        }
        return $input;
    }
    public static function create(PDO $pdo,array $input,int $userId): array
    {
        $key=OrderWriteService::requestKey($input['idempotency_key']??null);
        if(array_diff(array_keys($input),['idempotency_key']))jsonError('Create an empty shipment draft, then use its membership workflow',422);
        $name='shipment-create:'.substr(hash('sha256',$pdo->query('SELECT DATABASE()')->fetchColumn().':'.$key),0,42);
        $s=$pdo->prepare('SELECT GET_LOCK(?,5)');$s->execute([$name]);if(!(int)$s->fetchColumn())jsonError('Shipment creation is busy; retry',409);
        $owned=!$pdo->inTransaction();if($owned)$pdo->beginTransaction();
        register_shutdown_function(static function()use($pdo,$name,$owned){if($owned&&$pdo->inTransaction())$pdo->rollBack();$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);});
        $s=$pdo->prepare("SELECT entity_id,user_id FROM audit_log WHERE entity_type='shipment_draft' AND action='create' AND JSON_UNQUOTE(JSON_EXTRACT(new_value,'$.idempotency_key'))=? ORDER BY id LIMIT 1");$s->execute([$key]);$prior=$s->fetch(PDO::FETCH_ASSOC);
        if($prior){if((int)$prior['user_id']!==$userId)jsonError('Shipment request key belongs to another operator',409);$id=(int)$prior['entity_id'];}
        else{
            $pdo->exec("INSERT INTO shipment_drafts(status) VALUES ('draft')");$id=(int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO audit_log(entity_type,entity_id,action,new_value,user_id) VALUES ('shipment_draft',?,'create',?,?)")->execute([$id,json_encode(['idempotency_key'=>$key,'status'=>'draft']),$userId]);
        }
        $s=$pdo->prepare('SELECT * FROM shipment_drafts WHERE id=?');$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);if(!$row)jsonError('Original shipment draft was removed; use a new request',409);
        if($owned)$pdo->commit();$row['revision']=self::revision($row);$row['already_applied']=(bool)$prior;return $row;
    }
}
