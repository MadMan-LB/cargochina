<?php

/**
 * Design Attachments API - attach design/files to supported entities
 * GET ?entity_type=product|order_item|customer|supplier&entity_id=N
 * POST { entity_type, entity_id, file_path, file_type?, internal_note? }
 * DELETE /{id}
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__,2).'/services/OrderWriteService.php';

function lockAttachmentEntity(PDO $pdo,string $type,int $entityId): void
{
    if($type==='order_item'){lockEditableDesignItem($pdo,$entityId);return;}
    $table=['customer'=>'customers','supplier'=>'suppliers','product'=>'products'][$type];
    if($type==='customer')clmsRequireCustomerAccess($pdo,$entityId);
    $s=$pdo->prepare("SELECT id FROM $table WHERE id=? FOR UPDATE");$s->execute([$entityId]);if(!$s->fetchColumn())jsonError('Attachment owner not found',404);
}

function lockEditableDesignItem(PDO $pdo,int $itemId): void
{
    $s=$pdo->prepare('SELECT o.id,o.status,o.customer_id FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE oi.id=? FOR UPDATE');$s->execute([$itemId]);$order=$s->fetch(PDO::FETCH_ASSOC);
    if(!$order)jsonError('Order item not found',404);
    clmsRequireCustomerAccess($pdo,(int)$order['customer_id']);
    OrderWriteService::lockMutableProcurement($pdo,(int)$order['id']);
}

return function (string $method, ?string $id, ?string $action, array $input) {
    require_once __DIR__ . '/../authorization.php';
    clmsAuthorizeApiRequest('design-attachments', $method, $id, $action);
    $pdo = getDb();
    $userId = getAuthUserId() ?? 0;
    requireAuth();
    requirePermission('design-attachments');
    if($method!=='GET'){
        $pdo->beginTransaction();
        register_shutdown_function(static function()use($pdo){if($pdo->inTransaction())$pdo->rollBack();});
    }

    $validTypes = ['product', 'order_item', 'customer', 'supplier'];

    switch ($method) {
        case 'GET':
            $entityType = trim($_GET['entity_type'] ?? '');
            $entityId = (int) ($_GET['entity_id'] ?? 0);
            if (!in_array($entityType, $validTypes, true) || $entityId <= 0) {
                jsonError('entity_type (product|order_item|customer|supplier) and entity_id required', 400);
            }
            if ($entityType === 'customer') {
                clmsRequireCustomerAccess($pdo, $entityId);
            } elseif ($entityType === 'order_item') {
                $chk = $pdo->prepare(
                    "SELECT o.customer_id
                     FROM order_items oi JOIN orders o ON o.id=oi.order_id
                     WHERE oi.id = ?
                     LIMIT 1"
                );
                $chk->execute([$entityId]);
                $owner=$chk->fetchColumn();if(!$owner)jsonError('Order item not found',404);clmsRequireCustomerAccess($pdo,(int)$owner);
            }
            $stmt = $pdo->prepare("SELECT id, entity_type, entity_id, file_path, file_type, internal_note, uploaded_at FROM design_attachments WHERE entity_type = ? AND entity_id = ? ORDER BY uploaded_at DESC");
            $stmt->execute([$entityType, $entityId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['file_path'] = normalizeStoredUploadPath((string) $r['file_path'], false);
                $r['url'] = '/cargochina/backend/' . $r['file_path'];
            }
            jsonResponse(['data' => $rows]);
            break;

        case 'POST':
            if ($id !== null) {
                jsonError('POST without ID', 400);
            }
            foreach(['entity_type','file_path','file_type','internal_note'] as $field)if(isset($input[$field])&&!is_string($input[$field]))jsonError("$field must be text",422);
            if(strlen($input['internal_note']??'')>65535||strlen($input['file_type']??'')>50)jsonError('Attachment metadata exceeds its supported length',422);
            $entityType = trim($input['entity_type'] ?? '');
            $entityId = (int)OrderWriteService::number($input['entity_id']??null,'Attachment owner',true,0,4294967295);
            $filePath = normalizeStoredUploadPath((string) ($input['file_path'] ?? ''));
            $fileType = trim($input['file_type'] ?? '') ?: strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $internalNote = isset($input['internal_note']) ? trim($input['internal_note']) : null;
            if (!in_array($entityType, $validTypes, true) || $entityId <= 0 || !$filePath) {
                jsonError('entity_type (product|order_item|customer|supplier), entity_id, and file_path required', 400);
            }
            lockAttachmentEntity($pdo,$entityType,$entityId);
            $existing=$pdo->prepare('SELECT id,file_type,internal_note FROM design_attachments WHERE entity_type=? AND entity_id=? AND file_path=? FOR UPDATE');$existing->execute([$entityType,$entityId,$filePath]);$saved=$existing->fetch(PDO::FETCH_ASSOC);
            if($saved){if((string)$saved['file_type']!==$fileType||(string)$saved['internal_note']!==(string)$internalNote)jsonError('This file is already attached with different metadata',409);$pdo->commit();jsonResponse(['data'=>$saved+['file_path'=>$filePath,'url'=>'/cargochina/backend/'.$filePath],'idempotent_replay'=>true]);}
            // Validate entity exists
            if ($entityType === 'product') {
                $chk = $pdo->prepare("SELECT 1 FROM products WHERE id = ?");
                $chk->execute([$entityId]);
                if (!$chk->fetch()) jsonError('Product not found', 404);
            } elseif ($entityType === 'customer') {
                clmsRequireCustomerAccess($pdo, $entityId);
            } elseif ($entityType === 'supplier') {
                $chk = $pdo->prepare("SELECT 1 FROM suppliers WHERE id = ?");
                $chk->execute([$entityId]);
                if (!$chk->fetch()) jsonError('Supplier not found', 404);
            } else {
                lockEditableDesignItem($pdo,$entityId);
                $chk = $pdo->prepare(
                    "SELECT 1
                     FROM order_items oi
                     WHERE oi.id = ?
                     LIMIT 1"
                );
                $chk->execute([$entityId]);
                if (!$chk->fetchColumn()) jsonError('Order item not found', 404);
            }
            $stmt = $pdo->prepare("INSERT INTO design_attachments (entity_type, entity_id, file_path, file_type, uploaded_by, internal_note) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$entityType, $entityId, $filePath, $fileType, $userId ?: null, $internalNote]);
            $newId = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO audit_log(entity_type,entity_id,action,new_value,user_id) VALUES ('design_attachment',?,'create',?,?)")->execute([$newId,json_encode(['entity_type'=>$entityType,'entity_id'=>$entityId,'file_path'=>$filePath]),$userId]);
            $row = $pdo->prepare("SELECT id, entity_type, entity_id, file_path, file_type, internal_note, uploaded_at FROM design_attachments WHERE id = ?");
            $row->execute([$newId]);
            $data = $row->fetch(PDO::FETCH_ASSOC);
            $data['file_path'] = normalizeStoredUploadPath((string) $data['file_path']);
            $data['url'] = '/cargochina/backend/' . $data['file_path'];
            $pdo->commit();
            jsonResponse(['data' => $data], 201);
            break;

        case 'DELETE':
            if (!$id || (int) $id <= 0) {
                jsonError('ID required', 400);
            }
            $existing=$pdo->prepare('SELECT entity_type,entity_id FROM design_attachments WHERE id=?');$existing->execute([(int)$id]);$attachment=$existing->fetch(PDO::FETCH_ASSOC);
            if(!$attachment)jsonError('Attachment not found',404);
            lockAttachmentEntity($pdo,$attachment['entity_type'],(int)$attachment['entity_id']);
            $stmt = $pdo->prepare("DELETE FROM design_attachments WHERE id = ?");
            $stmt->execute([(int) $id]);
            if ($stmt->rowCount() === 0) {
                jsonError('Attachment not found', 404);
            }
            $pdo->prepare("INSERT INTO audit_log(entity_type,entity_id,action,old_value,user_id) VALUES ('design_attachment',?,'delete',?,?)")->execute([(int)$id,json_encode($attachment),$userId]);
            $pdo->commit();
            jsonResponse(['data' => ['deleted' => true, 'id' => (int) $id]]);
            break;

        default:
            jsonError('Method not allowed', 405);
    }
};
