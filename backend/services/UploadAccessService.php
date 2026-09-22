<?php
require_once dirname(__DIR__).'/api/helpers.php';

/** Shared guard for originals and thumbnails; possession of a file name grants no access. */
final class UploadAccessService
{
    private static function containsPath(mixed $value, string $path): bool
    {
        if (is_string($value)) return $value === $path;
        if (!is_array($value)) return false;
        foreach ($value as $nested) if (self::containsPath($nested, $path)) return true;
        return false;
    }

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
            if (!clmsSupportsJsonSearch($pdo)) {
                $rows=$pdo->query("SELECT $column FROM $table WHERE $column IS NOT NULL AND $column <> '' AND $column <> '[]'");
                while ($value=$rows->fetch(PDO::FETCH_NUM)) {
                    if (self::containsPath(json_decode((string)$value[0],true),$path)) return true;
                }
                continue;
            }
            $escaped=strtr($path,['\\'=>'\\\\','%'=>'\\%','_'=>'\\_']);
            $s=$pdo->prepare("SELECT 1 FROM $table WHERE JSON_SEARCH(CASE WHEN JSON_VALID($column) THEN $column ELSE '[]' END,'one',?) IS NOT NULL LIMIT 1");$s->execute([$escaped]);if($s->fetchColumn())return true;
        }
        if (!clmsSupportsJsonSearch($pdo)) {
            $rows=$pdo->query("SELECT shared_carton_contents FROM order_items WHERE shared_carton_contents IS NOT NULL AND shared_carton_contents <> '' AND shared_carton_contents <> '[]'");
            while ($row=$rows->fetch(PDO::FETCH_NUM)) {
                $contents=json_decode((string)$row[0],true);
                foreach (is_array($contents)?$contents:[] as $content) {
                    if (!is_array($content)) continue;
                    foreach (is_array($content['photo_paths']??null)?$content['photo_paths']:[] as $photo) if (is_string($photo)&&$photo===$path) return true;
                }
            }
            return false;
        }
        $s=$pdo->prepare("SELECT 1 FROM order_items WHERE JSON_SEARCH(CASE WHEN JSON_VALID(shared_carton_contents) THEN shared_carton_contents ELSE '[]' END,'one',?,NULL,'$[*].photo_paths[*]') IS NOT NULL LIMIT 1");$s->execute([strtr($path,['\\'=>'\\\\','%'=>'\\%','_'=>'\\_'])]);return (bool)$s->fetchColumn();
    }

    /** Private attachment ownership survives deletion. Never skip this check for an Excel image. */
    public static function privateCustomerReferences(PDO $pdo, string $path): array
    {
        $s=$pdo->prepare("SELECT entity_id customer_id,file_path FROM design_attachments WHERE entity_type='customer' AND file_path LIKE ?");
        $s->execute(['%'.strtr(basename($path),['\\'=>'\\\\','%'=>'\\%','_'=>'\\_'])]);
        $rows=$s->fetchAll(PDO::FETCH_ASSOC);
        if (clmsSupportsJsonSearch($pdo)) {
            $document="CASE WHEN JSON_VALID(new_value) THEN new_value ELSE '{}' END";
            $s=$pdo->prepare("SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT($document,'$.entity_id')) AS UNSIGNED) customer_id,JSON_UNQUOTE(JSON_EXTRACT($document,'$.file_path')) file_path FROM audit_log WHERE entity_type='design_attachment' AND action='create' AND JSON_UNQUOTE(JSON_EXTRACT($document,'$.entity_type'))='customer' AND JSON_UNQUOTE(JSON_EXTRACT($document,'$.file_path'))=?");
            $s->execute([$path]);$rows=array_merge($rows,$s->fetchAll(PDO::FETCH_ASSOC));
        } else {
            // Do not prefilter JSON text by filename: escaped Unicode/slashes may
            // otherwise hide private-document tombstones and weaken authorization.
            $s=$pdo->query("SELECT new_value FROM audit_log WHERE entity_type='design_attachment' AND action='create'");
            while ($row=$s->fetch(PDO::FETCH_ASSOC)) {
                $audit=json_decode((string)$row['new_value'],true);
                if (is_array($audit)&&($audit['entity_type']??null)==='customer'&&($audit['file_path']??null)===$path) {
                    $rows[]=['customer_id'=>(int)($audit['entity_id']??0),'file_path'=>$path];
                }
            }
        }
        $customers=[];
        foreach($rows as $row){try{$candidate=clmsResolveStoredUploadPathMeta($row['file_path'],false)['normalized'];if($candidate===$path)$customers[]=$row['customer_id'];}catch(InvalidArgumentException $e){/* Invalid legacy paths cannot identify this valid upload. */}}
        return array_values(array_unique($customers));
    }

    public static function authorize(PDO $pdo,string $path,?string $confirmationToken=null): void
    {
        try{$path=clmsResolveStoredUploadPathMeta($path,false)['normalized'];}catch(InvalidArgumentException $e){jsonError('Invalid upload path',422);}
        // Passport/ID ownership also survives attachment removal through its creation audit.
        $customers=self::privateCustomerReferences($pdo,$path);
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
