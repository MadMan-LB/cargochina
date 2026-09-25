<?php
require_once __DIR__.'/AuditService.php';

/** Supplier deletion hides the master record, never its cargo/accounting history. */
final class SupplierLifecycleService
{
    public static function ready(PDO $pdo): bool
    {
        static $cache=[];$key=spl_object_hash($pdo);
        if(!array_key_exists($key,$cache)){
            $s=$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='suppliers' AND COLUMN_NAME IN ('deleted_at','deleted_by','delete_reason','delete_generation')");
            $cache[$key]=(int)$s->fetchColumn()===4;
        }
        return $cache[$key];
    }
    public static function activeSql(PDO $pdo,string $alias=''): string
    {
        if($alias!==''&&!preg_match('/^[a-z_][a-z0-9_]*$/i',$alias))throw new InvalidArgumentException('Invalid supplier alias');
        return self::ready($pdo)?($alias!==''?$alias.'.':'').'deleted_at IS NULL':'1=1';
    }
    public static function requireActive(PDO $pdo,int $id): void
    {
        if($id<=0)return;
        $s=$pdo->prepare('SELECT id FROM suppliers WHERE id=? AND '.self::activeSql($pdo).($pdo->inTransaction()?' FOR UPDATE':''));$s->execute([$id]);
        if(!$s->fetchColumn())jsonError('Supplier is deleted or unavailable. Restore it before using it for new or edited items.',409);
    }
    public static function archive(PDO $pdo,int $id,int $actor,?string $reason): void
    {
        if(!self::ready($pdo))jsonError('Supplier recovery requires database migration 089_supplier_recovery.sql. Ask the administrator to apply it.',409);
        if(!$pdo->inTransaction())throw new LogicException('Supplier archive must be transactional');
        $s=$pdo->prepare('SELECT * FROM suppliers WHERE id=? FOR UPDATE');$s->execute([$id]);$old=$s->fetch(PDO::FETCH_ASSOC);
        if(!$old)jsonError('Supplier not found',404);
        if($old['deleted_at']!==null)jsonError('Supplier is already in the Recycle Bin',409);
        $reason=trim($reason??'');if(mb_strlen($reason)>500)jsonError('Deletion reason is limited to 500 characters',422);
        $pdo->prepare('UPDATE suppliers SET deleted_at=NOW(),deleted_by=?,delete_reason=?,delete_generation=delete_generation+1 WHERE id=?')->execute([$actor,$reason?:null,$id]);
        AuditService::record($pdo,'supplier',$id,'deleted',$old,['reference'=>$old['code'].' — '.$old['name'],'reason'=>$reason,'history_preserved'=>true],$actor);
    }
}
