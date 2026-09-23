<?php
require_once __DIR__.'/AuditService.php';
require_once __DIR__.'/RetentionPolicyService.php';

/** Only reversible, non-finalized draft headers. Cargo memberships are never resurrected. */
final class RecycleBinService
{
    public const TYPES = ['procurement_draft'=>'procurement_drafts', 'shipment_draft'=>'shipment_drafts'];

    public static function table(string $type): string
    {
        if (!isset(self::TYPES[$type])) throw new DomainException('Unsupported recycle-bin record', 422);
        return self::TYPES[$type];
    }

    public static function canAccess(string $type, bool $write=false): bool
    {
        self::table($type);
        return hasPageAccess('recycle_bin')
            && hasPermission($type==='shipment_draft'?'shipment-drafts.read':'page:procurement_drafts')
            && (!$write || (hasPermission('recycle-bin.restore',['SuperAdmin'])
                && hasPermission($type==='shipment_draft'?'shipment-drafts.write':'page:procurement_drafts')));
    }

    public static function authorize(string $type, bool $write=false): void
    {
        if (!self::canAccess($type,$write)) throw new DomainException($write
            ? 'Restore requires Recycle Bin access, Restore permission and access to edit this record type'
            : 'Recycle Bin and record-type access required',403);
    }

    public static function mark(PDO $pdo,string $type,int $id,int $actor,$reason=null,array $releasedOrderIds=[]): void
    {
        if (!$pdo->inTransaction()) throw new LogicException('Deletion must share the business transaction');
        $table=self::table($type);
        $s=$pdo->prepare("SELECT * FROM $table WHERE id=? FOR UPDATE");$s->execute([$id]);$old=$s->fetch(PDO::FETCH_ASSOC);
        if (!$old) throw new DomainException('Record not found',404);
        if ($old['deleted_at']!==null) throw new DomainException('Record is already in the Recycle Bin',409);
        if (!in_array($old['status'], $type==='shipment_draft'?['draft']:['draft','cancelled'],true)
            || !empty($old['converted_order_id'])) throw new DomainException('Converted or finalized records cannot be deleted',409);
        if($reason!==null&&!is_string($reason))throw new DomainException('Deletion reason must be text',422);
        $reason=trim($reason??'');
        if (mb_strlen($reason)>500) throw new DomainException('Deletion reason is limited to 500 characters',422);
        if ($type==='shipment_draft') {
            $s=$pdo->prepare('SELECT 1 FROM shipment_draft_orders WHERE shipment_draft_id=? LIMIT 1 FOR UPDATE');$s->execute([$id]);
            if ($s->fetchColumn()) throw new LogicException('Release cargo before archiving the shipment draft');
            $old['order_ids']=array_values(array_map('intval',$releasedOrderIds));
        }
        $extra=$type==='shipment_draft'?', container_id=NULL':'';
        $pdo->prepare("UPDATE $table SET deleted_at=NOW(),deleted_by=?,delete_reason=?,delete_generation=delete_generation+1$extra WHERE id=?")->execute([$actor,$reason?:null,$id]);
        AuditService::record($pdo,$type,$id,'deleted',$old,['reference'=>self::reference($type,$old),'reason'=>$reason],$actor);
    }

    public static function reference(string $type,array $row): string
    {
        return $type==='procurement_draft' ? 'PD-'.$row['id'].' — '.$row['name'] : 'Shipment draft #'.$row['id'].(!empty($row['booking_number'])?' — '.$row['booking_number']:'');
    }

    public static function restore(PDO $pdo,string $type,int $id,int $actor,string $version): void
    {
        self::authorize($type,true);
        self::mutate($pdo,$type,$id,$actor,$version,false);
    }

    public static function purge(PDO $pdo,string $type,int $id,int $actor,string $version,string $confirmation): void
    {
        self::authorize($type,true);
        if (!hasAnyRole(['SuperAdmin'])) throw new DomainException('Permanent deletion requires SuperAdmin',403);
        if ($confirmation!=="DELETE $type $id") throw new DomainException('Type the exact permanent-deletion confirmation',422);
        requireRecentAuthentication();
        self::mutate($pdo,$type,$id,$actor,$version,true);
    }

    private static function mutate(PDO $pdo,string $type,int $id,int $actor,string $version,bool $purge): void
    {
        $table=self::table($type);
        AuditService::begin($pdo);
        try {
            $s=$pdo->prepare("SELECT * FROM $table WHERE id=? FOR UPDATE");$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);
            if (!$row || !$row['deleted_at']) throw new DomainException('Record is no longer in the Recycle Bin; refresh the list',409);
            if (!hash_equals(self::version($row),$version)) throw new DomainException('Record changed; refresh before restoring',409);
            if (!in_array($row['status'],$type==='shipment_draft'?['draft']:['draft','cancelled'],true) || !empty($row['converted_order_id'])) throw new DomainException('Record is no longer a recoverable draft',409);
            if ($type==='procurement_draft' && !empty($row['supplier_id'])) {
                $s=$pdo->prepare('SELECT id FROM suppliers WHERE id=? FOR UPDATE');$s->execute([$row['supplier_id']]);
                if (!$s->fetchColumn()) throw new DomainException('Original supplier is missing; reconcile the draft before recovery',409);
            }
            if ($type==='shipment_draft') {
                $s=$pdo->prepare('SELECT 1 FROM shipment_draft_orders WHERE shipment_draft_id=? LIMIT 1 FOR UPDATE');$s->execute([$id]);
                if ($s->fetchColumn() || !empty($row['container_id'])) throw new DomainException('Deleted draft has cargo reservations; reconcile before recovery',409);
            }
            if ($purge) {
                // These are business records. A delete click does not override the approved 10-year policy.
                $s=$pdo->prepare("SELECT created_at >= '1000-01-01' AND created_at < DATE_SUB(NOW(),INTERVAL 10 YEAR) FROM $table WHERE id=?");$s->execute([$id]);
                if (!$s->fetchColumn() || RetentionPolicyService::held($pdo,'cargo',"$type:$id")) throw new DomainException('Business retention or a legal hold prevents permanent deletion',409);
                if($type==='shipment_draft'){
                    $s=$pdo->prepare("SELECT id FROM tracking_push_log WHERE entity_type='shipment_draft' AND entity_id=? LIMIT 1 FOR UPDATE");$s->execute([$id]);
                    if($s->fetchColumn())throw new DomainException('Tracking history prevents permanent deletion',409);
                }
                // Preserve any dependants rather than allowing CASCADE/SET NULL to erase their history.
                $refs=$pdo->prepare("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME=?");$refs->execute([$table]);
                foreach ($refs->fetchAll(PDO::FETCH_ASSOC) as $ref) {
                    $t='`'.str_replace('`','``',$ref['TABLE_NAME']).'`';$c='`'.str_replace('`','``',$ref['COLUMN_NAME']).'`';
                    $s=$pdo->prepare("SELECT 1 FROM $t WHERE $c=? LIMIT 1 FOR UPDATE");$s->execute([$id]);
                    if ($s->fetchColumn()) throw new DomainException('Related business records prevent permanent deletion',409);
                }
                AuditService::record($pdo,$type,$id,'permanently_deleted',$row,['reference'=>self::reference($type,$row)],$actor);
                $pdo->prepare("DELETE FROM $table WHERE id=?")->execute([$id]);
            } else {
                $pdo->prepare("UPDATE $table SET deleted_at=NULL,deleted_by=NULL,delete_reason=NULL WHERE id=?")->execute([$id]);
                AuditService::record($pdo,$type,$id,'restored',$row,['reference'=>self::reference($type,$row),'cargo_reassigned'=>false],$actor);
            }
            $pdo->commit();
        } catch (Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public static function version(array $row): string {return hash('sha256',json_encode($row,JSON_THROW_ON_ERROR));}
    public static function shipmentDeletionRevision(array $row,array $ids): string
    {
        $ids=array_map('intval',$ids);sort($ids,SORT_NUMERIC);
        $values=[];foreach(['id','status','container_id','container_number','booking_number','tracking_url','delete_generation'] as $key)$values[$key]=(string)($row[$key]??'');
        return hash('sha256',json_encode([$values,$ids],JSON_THROW_ON_ERROR));
    }
}
