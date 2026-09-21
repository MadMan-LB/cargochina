<?php
require_once dirname(__DIR__).'/api/helpers.php';

/** Shared guard for originals and thumbnails; possession of a file name grants no access. */
final class UploadAccessService
{
    public static function requireSchema(PDO $pdo): void
    {
        if (!$pdo->query("SHOW TABLES LIKE 'upload_assets'")->fetchColumn()) jsonError('Upload ownership migration 083 is required',503);
    }

    public static function register(PDO $pdo, string $path, int $userId): void
    {
        self::requireSchema($pdo);
        $meta=clmsResolveStoredUploadPathMeta($path,true);
        $file=$meta['resolved_path'];
        $s=$pdo->prepare('SELECT uploader_user_id,content_sha256 FROM upload_assets WHERE path=?');$s->execute([$meta['normalized']]);$existing=$s->fetch(PDO::FETCH_ASSOC);
        $hash=hash_file('sha256',$file);
        if ($existing) {
            if ((int)$existing['uploader_user_id']!==$userId || !hash_equals($existing['content_sha256'],$hash)) throw new RuntimeException('Upload ownership or contents changed');
            return;
        }
        $pdo->prepare('INSERT INTO upload_assets(path,uploader_user_id,byte_size,content_sha256) VALUES (?,?,?,?)')->execute([$meta['normalized'],$userId,filesize($file),$hash]);
    }

    private static function hasOperationalReference(PDO $pdo,string $path): bool
    {
        foreach (['warehouse_receipt_photos','warehouse_receipt_item_photos','shipment_draft_documents'] as $table) {
            $s=$pdo->prepare("SELECT 1 FROM $table WHERE file_path=? LIMIT 1");$s->execute([$path]);if($s->fetchColumn())return true;
        }
        $s=$pdo->prepare("SELECT 1 FROM design_attachments WHERE file_path=? AND entity_type<>'customer' LIMIT 1");$s->execute([$path]);if($s->fetchColumn())return true;
        foreach (['order_items'=>'image_paths','products'=>'image_paths','suppliers'=>'payment_links','customers'=>'payment_links'] as $table=>$column) {
            $escaped=strtr($path,['\\'=>'\\\\','%'=>'\\%','_'=>'\\_']);
            $s=$pdo->prepare("SELECT 1 FROM $table WHERE JSON_SEARCH(CASE WHEN JSON_VALID($column) THEN $column ELSE '[]' END,'one',?) IS NOT NULL LIMIT 1");$s->execute([$escaped]);if($s->fetchColumn())return true;
        }
        $s=$pdo->prepare("SELECT 1 FROM order_items WHERE JSON_SEARCH(COALESCE(shared_carton_contents,'[]'),'one',?,NULL,'$[*].photo_paths[*]') IS NOT NULL LIMIT 1");$s->execute([strtr($path,['\\'=>'\\\\','%'=>'\\%','_'=>'\\_'])]);return (bool)$s->fetchColumn();
    }

    public static function authorize(PDO $pdo,string $path,?string $confirmationToken=null): void
    {
        try{$path=clmsResolveStoredUploadPathMeta($path,false)['normalized'];}catch(InvalidArgumentException $e){jsonError('Invalid upload path',422);}
        // Passport/ID ownership also survives attachment removal through its creation audit.
        $s=$pdo->prepare("SELECT entity_id customer_id,file_path FROM design_attachments WHERE entity_type='customer' AND file_path LIKE ? UNION SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(new_value,'$.entity_id')) AS UNSIGNED) customer_id,JSON_UNQUOTE(JSON_EXTRACT(new_value,'$.file_path')) file_path FROM audit_log WHERE entity_type='design_attachment' AND action='create' AND JSON_UNQUOTE(JSON_EXTRACT(new_value,'$.entity_type'))='customer' AND JSON_UNQUOTE(JSON_EXTRACT(new_value,'$.file_path'))=?");
        $s->execute(['%'.strtr(basename($path),['\\'=>'\\\\','%'=>'\\%','_'=>'\\_']),$path]);$customers=[];
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row){try{$candidate=clmsResolveStoredUploadPathMeta($row['file_path'],false)['normalized'];if($candidate===$path)$customers[]=$row['customer_id'];}catch(InvalidArgumentException $e){/* Invalid legacy paths cannot identify this valid upload. */}}
        if($customers){requireAuth();requirePermission('design-attachments');foreach(array_unique($customers) as $customerId)clmsRequireCustomerAccess($pdo,(int)$customerId);return;}
        if($confirmationToken!==null&&$confirmationToken!==''){
            if(strlen($confirmationToken)>128)jsonError('Invalid receipt photo access',403);
            $s=$pdo->prepare("SELECT o.id FROM orders o JOIN warehouse_receipts wr ON wr.order_id=o.id AND wr.voided_at IS NULL JOIN warehouse_receipt_photos p ON p.receipt_id=wr.id WHERE o.confirmation_token=? AND p.file_path IN (?,?,?) AND o.status IN ('Confirmed','AwaitingCustomerConfirmation') LIMIT 1");
            $s->execute([$confirmationToken,$path,'backend/'.$path,'/cargochina/backend/'.$path]);if($s->fetchColumn())return;
            jsonError('Receipt photo access has expired or does not match this order',403);
        }
        $userId=requireAuth();requirePermission('uploads.read');
        // Legacy files retain the existing operational policy; private attachment tombstones above still apply.
        if($pdo->query("SHOW TABLES LIKE 'upload_assets'")->fetchColumn()){
            $s=$pdo->prepare('SELECT uploader_user_id FROM upload_assets WHERE path=?');$s->execute([$path]);$owner=$s->fetchColumn();
            if($owner!==false && (int)$owner!==$userId && !self::hasOperationalReference($pdo,$path))jsonError('Unattached upload belongs to another operator',403);
        }
    }
}
