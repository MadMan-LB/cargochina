<?php

/**
 * Procurement Drafts API - draft order lists for suppliers
 * Roles: authenticated operational users
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 2) . '/services/OrderWriteService.php';
require_once dirname(__DIR__, 2) . '/services/LegacyProcurementMetricsService.php';
require_once dirname(__DIR__, 2) . '/services/RecycleBinService.php';

function procurementDraftRevision(PDO $pdo,int $id): string
{
    $s=$pdo->prepare('SELECT name,supplier_id,status,converted_order_id,delete_generation FROM procurement_drafts WHERE deleted_at IS NULL AND id=?');$s->execute([$id]);$header=$s->fetch(PDO::FETCH_ASSOC)?:[];
    $s=$pdo->prepare('SELECT id,product_id,quantity,notes,sort_order FROM procurement_draft_items WHERE draft_id=? ORDER BY id');$s->execute([$id]);
    return OrderWriteService::requestHash(['header'=>$header,'items'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
}

/** Match the mutation revision exactly, using rows already loaded for the list. */
function procurementDraftLoadedRevision(array $draft, array $items): string
{
    $header = array_intersect_key($draft, array_flip(['name','supplier_id','status','converted_order_id','delete_generation']));
    usort($items, static fn($a, $b) => (int)$a['id'] <=> (int)$b['id']);
    $items = array_map(static fn($item) => array_intersect_key($item, array_flip(['id','product_id','quantity','notes','sort_order'])), $items);
    return OrderWriteService::requestHash(['header'=>$header,'items'=>$items]);
}

function procurementValidateItems(PDO $pdo,$items,?int $supplierId): array
{
    if(!is_array($items)||!$items||count($items)>500)jsonError('Provide between 1 and 500 procurement items',422);
    SupplierLifecycleService::requireActive($pdo,(int)$supplierId);
    foreach($items as &$item){
        if(!is_array($item))jsonError('Procurement item must be an object',422);
        OrderWriteService::validateRawNumbers($item);
        $item['quantity']=OrderWriteService::number($item['quantity']??null,'Quantity');if(!$item['quantity'])jsonError('Procurement quantity must be positive',422);
        if(!empty($item['product_id'])){$s=$pdo->prepare('SELECT supplier_id FROM products WHERE id=?');$s->execute([$item['product_id']]);$product=$s->fetch(PDO::FETCH_ASSOC);if(!$product)jsonError('Product not found',422);SupplierLifecycleService::requireActive($pdo,(int)$product['supplier_id']);if($supplierId&&!empty($product['supplier_id'])&&(int)$product['supplier_id']!==$supplierId)jsonError('Product belongs to another supplier',422);}
        elseif(trim((string)($item['notes']??''))==='')jsonError('Procurement item needs a product or description',422);
    }
    unset($item);return $items;
}
require_once dirname(__DIR__, 2) . '/services/TranslationService.php';

function procurementDraftResolveDescriptionPair(PDO $pdo, array $item): array
{
    $english = trim((string) ($item['description_en'] ?? ''));
    $chinese = trim((string) ($item['description_cn'] ?? ''));
    $fallback = trim((string) ($item['notes'] ?? ''));
    $service = new TranslationService($pdo);

    if ($english === '' && $chinese === '' && $fallback !== '') {
        if ($service->detectLanguage($fallback) === 'zh') {
            $chinese = $fallback;
        } else {
            $english = $fallback;
        }
    }
    if ($english === '' && $chinese === '') {
        jsonError(
            'An English or Chinese description is required.',
            422,
            ['items.description' => 'Enter at least one description language.']
        );
    }

    return ['description_en' => $english, 'description_cn' => $chinese];
}

function procurementDraftCopyNormalGoodsDisplay($value): string
{
    $raw = trim((string) ($value ?? ''));
    return match (strtolower($raw)) {
        'copy' => clmsT('Copy Goods'),
        'normal' => clmsT('Normal Goods'),
        default => $raw,
    };
}

function procurementDraftOutputCsv(array $draft, array $items, string $filename): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $out = fopen('php://output', 'w');
    clmsWriteCsv($out, [clmsT('Procurement Draft'), '#' . (int) ($draft['id'] ?? 0)]);
    clmsWriteCsv($out, [clmsT('Name'), (string) ($draft['name'] ?? '')]);
    clmsWriteCsv($out, [clmsT('Supplier'), (string) ($draft['supplier_name'] ?? '')]);
    clmsWriteCsv($out, [clmsT('Status'), clmsStatusLabel((string) ($draft['status'] ?? ''))]);
    clmsWriteCsv($out, ['']);
    clmsWriteCsv($out, array_map('clmsT', ['What Brand', 'Copy / Normal Goods', 'Code', 'Line', 'English Description', 'Chinese Description', 'Notes', 'Quantity', 'Factory Price', 'Customer Price', 'Total Amount', 'CBM Total', 'Weight Total', 'Express Number', 'Size', 'Photo Count']));
    foreach ($items as $index => $item) {
        $qty = (float) ($item['quantity'] ?? 0);
        $cbm = (float) ($item['cbm'] ?? 0);
        $weight = (float) ($item['weight'] ?? 0);
        $unitPrice = (float) ($item['unit_price'] ?? 0);
        $imagePaths = !empty($item['image_paths']) ? (is_string($item['image_paths']) ? (json_decode($item['image_paths'], true) ?: []) : $item['image_paths']) : [];
        clmsWriteCsv($out, [
            trim((string) ($item['what_brand'] ?? '')),
            procurementDraftCopyNormalGoodsDisplay($item['copy_normal_goods'] ?? ''),
            trim((string) ($item['code'] ?? '')),
            'PD-' . (int) ($draft['id'] ?? 0) . '-L' . ($index + 1),
            trim((string) ($item['description_en'] ?? '')),
            trim((string) ($item['description_cn'] ?? '')),
            trim((string) ($item['notes'] ?? '')),
            $qty ?: '',
            $unitPrice ?: '',
            $unitPrice ?: '',
            ($qty > 0 && $unitPrice > 0) ? round($qty * $unitPrice, 4) : '',
            ($qty > 0 && $cbm > 0) ? round($cbm * $qty, 6) : '',
            ($qty > 0 && $weight > 0) ? round($weight * $qty, 4) : '',
            trim((string) ($item['express_number'] ?? '')),
            trim((string) ($item['size'] ?? '')),
            count($imagePaths),
        ]);
    }
    fclose($out);
    exit;
}

return function (string $method, ?string $id, ?string $action, array $input) {
    require_once __DIR__ . '/../authorization.php';
    clmsAuthorizeApiRequest('procurement-drafts', $method, $id, $action);
    $pdo = getDb();
    if ($method === 'GET') { require_once dirname(__DIR__, 2) . '/services/QueryFilterService.php'; QueryFilterService::validate($_GET, 'procurement-drafts'); }
    if($method==='GET'&&($action==='export'||$id==='export'))clmsBeginExportSnapshot($pdo);
    if (!getAuthUserId()) jsonError('Unauthorized', 401);
    requirePermission('page:procurement_drafts', ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'ContainersStaff', 'FieldStaff', 'SuperAdmin']);

    if ($action === 'convert' && $method === 'POST' && $id) {
        $handler = require __DIR__ . '/draft-orders.php';
        $handler('POST', 'legacy', (int)$id . '/migrate', $input);
        return;
    }
    if($method==='POST'&&$id!==null)jsonError('Invalid procurement action',400);

    if(in_array($method,['POST','PUT','DELETE'],true)){
        $pdo->beginTransaction();register_shutdown_function(static function()use($pdo){if($pdo->inTransaction())$pdo->rollBack();});
        if($id){
            $s=$pdo->prepare('SELECT id FROM procurement_drafts WHERE deleted_at IS NULL AND id=? FOR UPDATE');$s->execute([$id]);if(!$s->fetchColumn())jsonError('Draft not found',404);
            if(!is_string($input['revision']??null)||!hash_equals(procurementDraftRevision($pdo,(int)$id),$input['revision']))jsonError('Procurement draft changed or revision is missing; reload before saving',409);
        }
    }

    if ($method === 'GET' && $id && $action === 'export') {
        $stmt = $pdo->prepare("SELECT pd.*, s.name as supplier_name FROM procurement_drafts pd LEFT JOIN suppliers s ON pd.supplier_id = s.id WHERE pd.deleted_at IS NULL AND pd.id = ?");
        $stmt->execute([$id]);
        $draft = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$draft) jsonError('Draft not found', 404);
        $chk = $pdo->query("SHOW COLUMNS FROM products LIKE 'image_paths'");
        $imgCol = ($chk && $chk->rowCount() > 0) ? 'p.image_paths AS product_image_paths,' : '';
        $chkDim = $pdo->query("SHOW COLUMNS FROM products LIKE 'dimensions_scope'");
        $dimCol = ($chkDim && $chkDim->rowCount() > 0) ? 'p.dimensions_scope,' : '';
        $itemsStmt = $pdo->prepare("SELECT pdi.*, p.description_cn, p.description_en, p.cbm, p.weight, p.unit_price, $imgCol $dimCol p.pieces_per_carton FROM procurement_draft_items pdi LEFT JOIN products p ON pdi.product_id = p.id WHERE pdi.draft_id = ? ORDER BY pdi.sort_order, pdi.id");
        $itemsStmt->execute([$id]);
        $draftItems = array_map([LegacyProcurementMetricsService::class, 'normalize'], $itemsStmt->fetchAll(PDO::FETCH_ASSOC));
        $format = clmsExportFormat('xlsx');
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $draft['name'] ?? 'draft');
        if ($format === 'csv') {
            procurementDraftOutputCsv($draft, $draftItems, 'procurement_draft_' . $draft['id'] . '_' . $safeName . '.csv');
        }
        $orderLike = ['id' => $draft['id'], 'supplier_name' => $draft['supplier_name'] ?? ''];
        $excelItems = [];
        foreach ($draftItems as $i => $it) {
            $qty = (float) ($it['quantity'] ?? 0);
            $cbm = (float) ($it['cbm'] ?? 0);
            $weight = (float) ($it['weight'] ?? 0);
            $unitPrice = (float) ($it['unit_price'] ?? 0);
            $excelItems[] = [
                'item_no'               => 'PD-' . $draft['id'] . '-L' . ($i + 1),
                'what_brand'            => trim((string) ($it['what_brand'] ?? '')),
                'copy_normal_goods'     => trim((string) ($it['copy_normal_goods'] ?? '')),
                'code'                  => trim((string) ($it['code'] ?? '')),
                'description_en'        => trim((string) ($it['description_en'] ?? '')),
                'description_cn'        => trim((string) ($it['description_cn'] ?? '')),
                'quantity'              => $qty,
                'cartons'               => 1,
                'qty_per_carton'        => $qty,
                'declared_cbm'          => $it['declared_cbm'],
                'declared_weight'       => $it['declared_weight'],
                'unit_price'            => $unitPrice,
                'sell_price'             => $unitPrice,
                'supplier_name'          => $orderLike['supplier_name'],
                'express_number'         => trim((string) ($it['express_number'] ?? '')),
                'size'                   => trim((string) ($it['size'] ?? '')),
                'image_paths'            => clmsMergeImagePathLists(
                    $it['image_paths'] ?? [],
                    $it['product_image_paths'] ?? []
                ),
                'dimensions_scope'      => $it['dimensions_scope'] ?? 'piece',
                'product_dimensions_scope' => $it['dimensions_scope'] ?? 'piece',
            ];
        }
        require_once dirname(__DIR__, 2) . '/services/OrderExcelService.php';
        $filename = 'procurement_draft_' . $draft['id'] . '_' . $safeName . '_' . date('Ymd_His') . '.xlsx';
        (new OrderExcelService($pdo))->exportOrder($orderLike, $excelItems, $filename);
        exit;
    }

    switch ($method) {
        case 'GET':
            if ($id === null) {
                $stmt = $pdo->query("SELECT pd.*, s.name as supplier_name FROM procurement_drafts pd LEFT JOIN suppliers s ON pd.supplier_id = s.id WHERE pd.deleted_at IS NULL ORDER BY pd.created_at DESC");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $itemsByDraft = [];
                // Bounded IN lists work with native PDO prepares and MySQL 5.5.
                foreach (array_chunk(array_column($rows, 'id'), 200) as $ids) {
                    $marks = implode(',', array_fill(0, count($ids), '?'));
                    $items = $pdo->prepare("SELECT pdi.*, p.description_cn, p.description_en FROM procurement_draft_items pdi LEFT JOIN products p ON pdi.product_id = p.id WHERE pdi.draft_id IN ($marks) ORDER BY pdi.draft_id, pdi.sort_order, pdi.id");
                    $items->execute($ids);
                    foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) $itemsByDraft[$item['draft_id']][] = $item;
                }
                foreach ($rows as &$r) {
                    $r['items'] = $itemsByDraft[$r['id']] ?? [];
                    $r['revision'] = procurementDraftLoadedRevision($r, $r['items']);
                }
                unset($r);
                jsonResponse(['data' => $rows]);
            }
            $stmt = $pdo->prepare("SELECT pd.*, s.name as supplier_name FROM procurement_drafts pd LEFT JOIN suppliers s ON pd.supplier_id = s.id WHERE pd.deleted_at IS NULL AND pd.id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) jsonError('Draft not found', 404);
            $items = $pdo->prepare("SELECT pdi.*, p.description_cn, p.description_en, p.cbm, p.weight, p.dimensions_scope, p.pieces_per_carton FROM procurement_draft_items pdi LEFT JOIN products p ON pdi.product_id = p.id WHERE pdi.draft_id = ? ORDER BY pdi.sort_order, pdi.id");
            $items->execute([$id]);
            $row['items'] = array_map([LegacyProcurementMetricsService::class, 'normalize'], $items->fetchAll(PDO::FETCH_ASSOC));
            $row['revision']=procurementDraftRevision($pdo,(int)$id);
            jsonResponse(['data' => $row]);

        case 'POST':
            $key=OrderWriteService::requestKey($input['idempotency_key']??null);
            $claimName='clms-legacy-'.substr(hash('sha256',$pdo->query('SELECT DATABASE()')->fetchColumn().$key),0,45);
            $claim=$pdo->prepare('SELECT GET_LOCK(?,5)');$claim->execute([$claimName]);if((int)$claim->fetchColumn()!==1)jsonError('Procurement creation is in progress; retry later',409);
            register_shutdown_function(static function()use($pdo,$claimName){$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$claimName]);});
            require_once dirname(__DIR__,2).'/services/AuditReplayLookupService.php';
            $previous=AuditReplayLookupService::find($pdo,'procurement_draft',$key,true);
            if($previous){$audit=json_decode($previous['new_value'],true);if((int)$previous['user_id']!==getAuthUserId()||!hash_equals($audit['request_hash']??'',OrderWriteService::requestHash($input)))jsonError('Procurement idempotency key belongs to another payload',409);$s=$pdo->prepare('SELECT * FROM procurement_drafts WHERE deleted_at IS NULL AND id=?');$s->execute([$previous['entity_id']]);$saved=$s->fetch(PDO::FETCH_ASSOC);if(!$saved)jsonError('Original procurement draft was removed; use a new request',409);$saved['revision']=procurementDraftRevision($pdo,(int)$saved['id']);$pdo->commit();jsonResponse(['data'=>$saved,'idempotent_replay'=>true]);}
            if(isset($input['name'])&&!is_string($input['name']))jsonError('Name must be text',422);
            $name = trim($input['name'] ?? '');
            if (!$name) jsonError('Name required', 400);
            $supplierId = OrderWriteService::number($input['supplier_id']??null,'Supplier',true,0,4294967295);
            $supplierId = $supplierId ? (int)$supplierId : null;
            $validatedItems=procurementValidateItems($pdo,$input['items']??[],$supplierId);
            $userId = getAuthUserId();
            $pdo->prepare("INSERT INTO procurement_drafts (name, supplier_id, status, created_by) VALUES (?,?, 'draft', ?)")
                ->execute([$name, $supplierId, $userId]);
            $newId = (int) $pdo->lastInsertId();
            $items = $validatedItems;
            $ins = $pdo->prepare("INSERT INTO procurement_draft_items (draft_id, product_id, quantity, notes, sort_order) VALUES (?,?,?,?,?)");
            foreach ($items as $i => $it) {
                $ins->execute([$newId, !empty($it['product_id']) ? (int) $it['product_id'] : null, (float) ($it['quantity'] ?? 0), trim($it['notes'] ?? '') ?: null, $i]);
            }
            $stmt = $pdo->prepare("SELECT pd.*, s.name as supplier_name FROM procurement_drafts pd LEFT JOIN suppliers s ON pd.supplier_id = s.id WHERE pd.deleted_at IS NULL AND pd.id = ?");
            $stmt->execute([$newId]);
            $saved=$stmt->fetch(PDO::FETCH_ASSOC);$saved['revision']=procurementDraftRevision($pdo,$newId);
            $pdo->prepare("INSERT INTO audit_log(entity_type,entity_id,action,new_value,user_id) VALUES ('procurement_draft',?,'create',?,?)")->execute([$newId,json_encode(['idempotency_key'=>$key,'request_hash'=>OrderWriteService::requestHash($input)]),$userId]);
            $pdo->commit();jsonResponse(['data'=>$saved],201);

        case 'PUT':
            if (!$id) jsonError('ID required', 400);
            $stmt = $pdo->prepare("SELECT * FROM procurement_drafts WHERE deleted_at IS NULL AND id = ?");
            $stmt->execute([$id]);
            $draft = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$draft) jsonError('Draft not found', 404);
            if ($draft['status'] !== 'draft' && $draft['status'] !== 'pending_review') jsonError('Draft cannot be edited in current status', 400);
            $next=['draft'=>['pending_review','cancelled'],'pending_review'=>['draft','sent_to_supplier','cancelled']];
            if(isset($input['status'])&&$input['status']!==$draft['status']&&!in_array($input['status'],$next[$draft['status']]??[],true))jsonError('Invalid procurement state transition; use the conversion workflow to create an order',409);
            if(array_key_exists('name',$input)&&(!is_string($input['name'])||trim($input['name'])===''))jsonError('Name is required and must be text',422);
            $supplierId=array_key_exists('supplier_id',$input)?OrderWriteService::number($input['supplier_id'],'Supplier',true,0,4294967295):$draft['supplier_id'];
            if(array_key_exists('items',$input))$input['items']=procurementValidateItems($pdo,$input['items'],$supplierId?(int)$supplierId:null);
            elseif(array_key_exists('supplier_id',$input)){
                $existingItems=$pdo->prepare('SELECT * FROM procurement_draft_items WHERE draft_id=?');$existingItems->execute([$id]);
                procurementValidateItems($pdo,$existingItems->fetchAll(PDO::FETCH_ASSOC),$supplierId?(int)$supplierId:null);
            }

            $updates = [];
            $params = [];
            foreach (['name', 'supplier_id', 'status'] as $col) {
                if (array_key_exists($col, $input)) {
                    $v = $input[$col];
                    if ($col === 'supplier_id') $v = $v ? (int) $v : null;
                    $updates[] = "$col = ?";
                    $params[] = $v;
                }
            }
            if (!empty($updates)) {
                $params[] = $id;
                $pdo->prepare("UPDATE procurement_drafts SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
            }
            if (isset($input['items'])) {
                $pdo->prepare("DELETE FROM procurement_draft_items WHERE draft_id = ?")->execute([$id]);
                $ins = $pdo->prepare("INSERT INTO procurement_draft_items (draft_id, product_id, quantity, notes, sort_order) VALUES (?,?,?,?,?)");
                foreach ($input['items'] as $i => $it) {
                    $ins->execute([$id, !empty($it['product_id']) ? (int) $it['product_id'] : null, (float) ($it['quantity'] ?? 0), trim($it['notes'] ?? '') ?: null, $i]);
                }
            }
            $stmt = $pdo->prepare("SELECT pd.*, s.name as supplier_name FROM procurement_drafts pd LEFT JOIN suppliers s ON pd.supplier_id = s.id WHERE pd.deleted_at IS NULL AND pd.id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $items = $pdo->prepare("SELECT pdi.*, p.description_cn, p.description_en FROM procurement_draft_items pdi LEFT JOIN products p ON pdi.product_id = p.id WHERE pdi.draft_id = ? ORDER BY pdi.sort_order");
            $items->execute([$id]);
            $row['items'] = array_map([LegacyProcurementMetricsService::class, 'normalize'], $items->fetchAll(PDO::FETCH_ASSOC));
            $row['revision']=procurementDraftRevision($pdo,(int)$id);
            $pdo->prepare("INSERT INTO audit_log(entity_type,entity_id,action,old_value,new_value,user_id) VALUES ('procurement_draft',?,'update',?,?,?)")->execute([$id,json_encode($draft),json_encode($row),getAuthUserId()]);
            $pdo->commit();
            jsonResponse(['data' => $row]);

        case 'DELETE':
            if (!$id) jsonError('ID required', 400);
            $stmt = $pdo->prepare("SELECT status FROM procurement_drafts WHERE deleted_at IS NULL AND id = ?");
            $stmt->execute([$id]);
            $d = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$d) jsonError('Draft not found', 404);
            if ($d['status'] !== 'draft' && $d['status'] !== 'cancelled') jsonError('Only draft or cancelled can be deleted', 400);
            try { RecycleBinService::mark($pdo,'procurement_draft',(int)$id,(int)getAuthUserId(),$input['delete_reason']??null); }
            catch(DomainException $e){if($pdo->inTransaction())$pdo->rollBack();jsonError($e->getMessage(),$e->getCode()?:409);}
            $pdo->commit();
            jsonResponse(['data' => ['deleted' => true]]);

        default:
            jsonError('Method not allowed', 405);
    }
};
