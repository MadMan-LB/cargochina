<?php

/**
 * Design Attachments API - attach design files to products or order_items
 * GET ?entity_type=product|order_item&entity_id=N
 * POST { entity_type, entity_id, file_path, file_type?, internal_note? }
 * DELETE /{id}
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();
    $userId = getAuthUserId() ?? 0;

    $validTypes = ['product', 'order_item'];

    switch ($method) {
        case 'GET':
            $entityType = trim($_GET['entity_type'] ?? '');
            $entityId = (int) ($_GET['entity_id'] ?? 0);
            if (!in_array($entityType, $validTypes, true) || $entityId <= 0) {
                jsonError('entity_type (product|order_item) and entity_id required', 400);
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
            $entityType = trim($input['entity_type'] ?? '');
            $entityId = (int) ($input['entity_id'] ?? 0);
            $filePath = normalizeStoredUploadPath((string) ($input['file_path'] ?? ''));
            $fileType = trim($input['file_type'] ?? '') ?: strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $internalNote = isset($input['internal_note']) ? trim($input['internal_note']) : null;
            if (!in_array($entityType, $validTypes, true) || $entityId <= 0 || !$filePath) {
                jsonError('entity_type (product|order_item), entity_id, and file_path required', 400);
            }
            // Validate entity exists
            if ($entityType === 'product') {
                $chk = $pdo->prepare("SELECT 1 FROM products WHERE id = ?");
                $chk->execute([$entityId]);
                if (!$chk->fetch()) jsonError('Product not found', 404);
            } else {
                $chk = $pdo->prepare("SELECT 1 FROM order_items WHERE id = ?");
                $chk->execute([$entityId]);
                if (!$chk->fetch()) jsonError('Order item not found', 404);
            }
            $stmt = $pdo->prepare("INSERT INTO design_attachments (entity_type, entity_id, file_path, file_type, uploaded_by, internal_note) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$entityType, $entityId, $filePath, $fileType, $userId ?: null, $internalNote]);
            $newId = (int) $pdo->lastInsertId();
            $row = $pdo->prepare("SELECT id, entity_type, entity_id, file_path, file_type, internal_note, uploaded_at FROM design_attachments WHERE id = ?");
            $row->execute([$newId]);
            $data = $row->fetch(PDO::FETCH_ASSOC);
            $data['file_path'] = normalizeStoredUploadPath((string) $data['file_path']);
            $data['url'] = '/cargochina/backend/' . $data['file_path'];
            jsonResponse(['data' => $data], 201);
            break;

        case 'DELETE':
            if (!$id || (int) $id <= 0) {
                jsonError('ID required', 400);
            }
            $stmt = $pdo->prepare("DELETE FROM design_attachments WHERE id = ?");
            $stmt->execute([(int) $id]);
            if ($stmt->rowCount() === 0) {
                jsonError('Attachment not found', 404);
            }
            jsonResponse(['data' => ['deleted' => true, 'id' => (int) $id]]);
            break;

        default:
            jsonError('Method not allowed', 405);
    }
};
