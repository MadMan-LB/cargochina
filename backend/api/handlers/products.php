<?php

/**
 * Products API - GET list, GET one, POST create, PUT update, DELETE, GET suggest (duplicate matching)
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__,2).'/services/AuditService.php';
require_once dirname(__DIR__,2).'/services/CatalogRevisionService.php';
require_once dirname(__DIR__,2).'/services/ProductWriteService.php';
require_once dirname(__DIR__,2).'/services/MasterDataImportService.php';
require_once dirname(__DIR__, 2) . '/services/ItemClassificationService.php';

function productConfirmedClassification(PDO $pdo,array $input,?string $descriptionEn,?string $descriptionCn,?int $supplierId): array
{
    $service=new ItemClassificationService($pdo);
    $code=trim((string)($input['item_type_code']??''));
    if($code===''){
        $suggestion=$service->suggest(['description_en'=>$descriptionEn,'description_cn'=>$descriptionCn,'supplier_id'=>$supplierId]);
        jsonError('Item type confirmation is required before saving this product',422,['item_type_code'=>$suggestion['item_type_code'],'confidence'=>$suggestion['confidence'],'reason'=>$suggestion['reason']]);
    }
    $code=$service->normalize($code);
    if($code==='unclassified'||empty($input['item_type_confirmed']))jsonError('Choose and confirm a specific item type before saving',422,['item_type_code'=>$code]);
    return ['item_type_code'=>$code,'confidence'=>isset($input['item_type_confidence'])?(float)$input['item_type_confidence']:1.0,'source'=>'manual','is_confirmed'=>true];
}

function hasProductDescEntries(PDO $pdo): bool
{
    try {
        $pdo->query("SELECT 1 FROM product_description_entries LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function productHasColumn(PDO $pdo, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM products LIKE ?");
        $stmt->execute([$column]);
        return (bool) $stmt->rowCount();
    } catch (Throwable $e) {
        return false;
    }
}

function ensureProductHighAlertColumn(PDO $pdo): bool
{
    static $checked = false;
    static $available = false;

    if ($checked) {
        return $available;
    }

    $checked = true;
    if (productHasColumn($pdo, 'high_alert_note')) {
        $available = true;
        return true;
    }

    try {
        $pdo->exec("ALTER TABLE products ADD COLUMN high_alert_note TEXT NULL");
    } catch (Throwable $e) {
        // If another process added it first or the DB user cannot alter schema,
        // fall back to a final existence check and keep the request usable.
    }

    $available = productHasColumn($pdo, 'high_alert_note');
    return $available;
}

function buildProductSearchSql(string $query, array &$params, string $productAlias = 'p', string $supplierAlias = 's'): string
{
    $terms = preg_split('/\s+/', trim($query)) ?: [];
    $terms = array_values(array_filter($terms, fn($term) => $term !== ''));
    if (!$terms) {
        return '1=1';
    }

    $clauses = [];
    foreach ($terms as $term) {
        $like = clmsSearchLike($term);
        $clauses[] = "(CAST($productAlias.id AS CHAR) LIKE ? OR $productAlias.description_cn LIKE ? OR $productAlias.description_en LIKE ? OR COALESCE($productAlias.packaging, '') LIKE ? OR COALESCE($productAlias.hs_code, '') LIKE ? OR COALESCE($supplierAlias.name, '') LIKE ?)";
        array_push($params, $like, $like, $like, $like, $like, $like);
    }

    return implode(' AND ', $clauses);
}

return function (string $method, ?string $id, ?string $action, array $input) {
    require_once __DIR__ . '/../authorization.php';
    clmsAuthorizeApiRequest('products', $method, $id, $action);
    $pdo = getDb();
    if ($method === 'GET') { require_once dirname(__DIR__, 2) . '/services/QueryFilterService.php'; QueryFilterService::validate($_GET, 'products'); }
    requirePermission($method==='GET'?'products.read':($method==='POST'?($id==='import'?'products.import':'products.create'):'products.write'),['ChinaAdmin','ChinaEmployee','SuperAdmin']);
    if(($method==='POST'&&$id===null)||$method==='PUT'){
        $input=ProductWriteService::normalize($pdo,$input);
        MasterDataImportService::lock($pdo,'product');
    }
    $createClaim=null;
    if($method==='POST' && $id===null) {
        $createClaim=OperationReplayService::claim($pdo,'catalog_products',$input,(int)getAuthUserId());
        if($createClaim['previous_id']) jsonResponse(['data'=>['id'=>$createClaim['previous_id']],'idempotent_replay'=>true]);
    }
    $hasHighAlertColumn = productHasColumn($pdo,'high_alert_note');

    switch ($method) {
        case 'GET':
            if ($id === 'hs-codes') {
                $q = trim($_GET['q'] ?? '');
                $like = strlen($q) >= 1 ? clmsSearchLike($q) : '%';
                $stmt = $pdo->prepare("SELECT DISTINCT hs_code FROM products WHERE hs_code IS NOT NULL AND hs_code != '' AND hs_code LIKE ? ORDER BY hs_code LIMIT 15");
                $stmt->execute([$like]);
                $rows = array_map(fn($r) => ['id' => $r['hs_code'], 'hs_code' => $r['hs_code']], $stmt->fetchAll(PDO::FETCH_ASSOC));
                jsonResponse(['data' => $rows]);
            }
            if ($id === 'search') {
                $q = trim($input['q'] ?? $_GET['q'] ?? '');
                $supplierId = isset($_GET['supplier_id']) && $_GET['supplier_id'] !== '' ? (int) $_GET['supplier_id'] : null;
                if (strlen($q) < 1) {
                    jsonResponse(['data' => []]);
                }
                $searchCols = "p.id, p.description_cn, p.description_en, p.hs_code, p.cbm, p.weight, p.length_cm, p.width_cm, p.height_cm, p.pieces_per_carton, p.unit_price, p.image_paths, p.packaging, p.supplier_id, s.name as supplier_name";
                if ($hasHighAlertColumn) {
                    $searchCols .= ", p.high_alert_note";
                }
                if (productHasColumn($pdo, 'dimensions_scope')) $searchCols .= ", p.dimensions_scope";
                if (productHasColumn($pdo, 'required_design')) $searchCols .= ", p.required_design";
                $params = [];
                $where = buildProductSearchSql($q, $params, 'p', 's');
                $where .= ' AND (p.supplier_id IS NULL OR (s.id IS NOT NULL AND '.SupplierLifecycleService::activeSql($pdo,'s').'))';
                if ($supplierId) {
                    $where .= " AND p.supplier_id = ?";
                    $params[] = $supplierId;
                }
                $stmt = $pdo->prepare("SELECT $searchCols FROM products p LEFT JOIN suppliers s ON p.supplier_id = s.id WHERE $where ORDER BY p.id DESC LIMIT 10");
                $stmt->execute($params);
                jsonResponse(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            }
            if ($id === 'suggest') {
                $q = trim($input['q'] ?? $_GET['q'] ?? '');
                if (strlen($q) < 2) {
                    jsonResponse(['data' => []]);
                }
                $like = clmsSearchLike($q);
                $stmt = $pdo->prepare("SELECT id, description_cn, description_en, cbm, weight, hs_code FROM products WHERE description_cn LIKE ? OR description_en LIKE ? OR hs_code LIKE ? LIMIT 20");
                $stmt->execute([$like, $like, $like]);
                $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $qLower = mb_strtolower($q);
                $scored = [];
                foreach ($candidates as $c) {
                    $similarity = 0;
                    foreach (['description_cn', 'description_en', 'hs_code'] as $f) {
                        $v = $c[$f] ?? '';
                        if ($v === '') continue;
                        similar_text($qLower, mb_strtolower($v), $pct);
                        $similarity = max($similarity, $pct);
                        if (stripos($v, $q) !== false) $similarity = max($similarity, 85);
                    }
                    $c['similarity'] = round($similarity, 1);
                    $scored[] = $c;
                }
                usort($scored, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
                jsonResponse(['data' => array_slice($scored, 0, 10)]);
            }
            if ($id === null) {
                $q = trim($_GET['q'] ?? '');
                $supplierId = isset($_GET['supplier_id']) && $_GET['supplier_id'] !== '' ? (int) $_GET['supplier_id'] : null;
                $hsCode = trim($_GET['hs_code'] ?? '');
                $alertFilter = trim($_GET['alert_filter'] ?? '');
                $imageFilter = trim($_GET['image_filter'] ?? '');
                $itemType=clmsNormalizeItemTypeFilter($_GET['item_type']??null);

                $where = [];
                $params = [];

                if ($q !== '') {
                    $where[] = buildProductSearchSql($q, $params, 'p', 's');
                }
                if ($supplierId) {
                    $where[] = "p.supplier_id = ?";
                    $params[] = $supplierId;
                }
                if ($hsCode !== '') {
                    $normalizedHsCode = preg_replace('/[^A-Z0-9]/', '', strtoupper($hsCode));
                    if ($normalizedHsCode !== '' && preg_match('/^[0-9.\-\s]+$/', $hsCode) === 1) {
                        $where[] = "REPLACE(REPLACE(REPLACE(UPPER(COALESCE(p.hs_code, '')), '.', ''), '-', ''), ' ', '') LIKE ?";
                        $params[] = $normalizedHsCode . '%';
                    } else {
                        $where[] = "COALESCE(p.hs_code, '') LIKE ?";
                        $params[] = clmsSearchLike($hsCode);
                    }
                }

                $alertClauses = [];
                if ($hasHighAlertColumn) {
                    $alertClauses[] = "(p.high_alert_note IS NOT NULL AND TRIM(p.high_alert_note) <> '')";
                }
                if (productHasColumn($pdo, 'required_design')) {
                    $alertClauses[] = "COALESCE(p.required_design, 0) = 1";
                }
                if ($alertFilter === 'with' && $alertClauses) {
                    $where[] = '(' . implode(' OR ', $alertClauses) . ')';
                } elseif ($alertFilter === 'without' && $alertClauses) {
                    $where[] = 'NOT (' . implode(' OR ', $alertClauses) . ')';
                }

                if ($imageFilter === 'with') {
                    $where[] = "(p.image_paths IS NOT NULL AND p.image_paths <> '' AND p.image_paths <> '[]')";
                } elseif ($imageFilter === 'without') {
                    $where[] = "(p.image_paths IS NULL OR p.image_paths = '' OR p.image_paths = '[]')";
                }

                if($itemType!==null){$where[]="COALESCE(ic.item_type_code,'unclassified')=?";$params[]=$itemType;}

                $sql = "SELECT p.*, s.name as supplier_name, COALESCE(ic.item_type_code,'unclassified') item_type_code, ic.confidence item_type_confidence, COALESCE(ic.is_confirmed,0) item_type_confirmed FROM products p LEFT JOIN suppliers s ON p.supplier_id = s.id LEFT JOIN item_classifications ic ON ic.entity_type='product' AND ic.entity_id=p.id";
                if ($where) {
                    $sql .= ' WHERE ' . implode(' AND ', $where);
                }
                $limit=clmsQueryLimit($_GET['limit']??null,50,200);$offset=clmsQueryOffset($_GET['offset']??null);
                $countStmt=$pdo->prepare("SELECT COUNT(*) FROM ($sql) products_filtered");$countStmt->execute($params);$total=(int)$countStmt->fetchColumn();
                $sql .= " ORDER BY p.id DESC LIMIT ".($limit+1)." OFFSET ".$offset;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $hasMore=count($rows)>$limit;if($hasMore)$rows=array_slice($rows,0,$limit);
                foreach ($rows as &$r) {
                    $r['image_paths'] = $r['image_paths'] ? json_decode($r['image_paths'], true) : [];
                    $r['thumbnail_url'] = !empty($r['image_paths'][0])
                        ? '/cargochina/backend/thumb.php?path=' . rawurlencode($r['image_paths'][0]) . '&w=96&h=96&fit=cover'
                        : null;
                }
                jsonResponse(['data' => $rows,'meta'=>['limit'=>$limit,'offset'=>$offset,'has_more'=>$hasMore,'total'=>$total]]);
            }
            $revisionReadOwned = !$pdo->inTransaction();
            if ($revisionReadOwned) AuditService::begin($pdo);
            $stmt = $pdo->prepare("SELECT p.*, s.name as supplier_name, COALESCE(ic.item_type_code,'unclassified') item_type_code, ic.confidence item_type_confidence, COALESCE(ic.is_confirmed,0) item_type_confirmed FROM products p LEFT JOIN suppliers s ON p.supplier_id = s.id LEFT JOIN item_classifications ic ON ic.entity_type='product' AND ic.entity_id=p.id WHERE p.id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                jsonError('Product not found', 404);
            }
            $row['image_paths'] = $row['image_paths'] ? json_decode($row['image_paths'], true) : [];
            $row['thumbnail_url'] = !empty($row['image_paths'][0])
                ? '/cargochina/backend/thumb.php?path=' . rawurlencode($row['image_paths'][0]) . '&w=96&h=96&fit=cover'
                : null;
            if (hasProductDescEntries($pdo)) {
                $stmt2 = $pdo->prepare("SELECT description_text, description_translated, sort_order FROM product_description_entries WHERE product_id = ? ORDER BY sort_order, id");
                $stmt2->execute([$id]);
                $row['description_entries'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $row['description_entries'] = [];
            }
            if (!isset($row['pieces_per_carton'])) $row['pieces_per_carton'] = null;
            if (!isset($row['unit_price'])) $row['unit_price'] = null;
            $row['revision']=CatalogRevisionService::revision($pdo,'products',(int)$id);
            if ($revisionReadOwned) $pdo->commit();
            jsonResponse(['data' => $row]);

        case 'POST':
            if ($id === 'import') {
                $allowed=['description_cn','description_en','cbm','weight','hs_code','pieces_per_carton','unit_price','buy_price','sell_price','packaging','supplier_code','length_cm','width_cm','height_cm','dimensions_scope','item_type_code','item_type_confirmed','force_create'];
                $result=MasterDataImportService::run($pdo,'product',$input,['item_type_code','item_type_confirmed'],$allowed,function(array $row)use($pdo):?int{
                    $row['supplier_id']=null;
                    if(!empty($row['supplier_code'])){$s=$pdo->prepare('SELECT id FROM suppliers WHERE code=?');$s->execute([$row['supplier_code']]);$row['supplier_id']=$s->fetchColumn();if(!$row['supplier_id'])jsonError('Unknown product supplier code',422);}
                    $row=ProductWriteService::normalize($pdo,$row);
                    $classification=productConfirmedClassification($pdo,$row,$row['description_en']??null,$row['description_cn']??null,$row['supplier_id']);
                    $s=$pdo->prepare("SELECT id FROM products WHERE supplier_id <=> ? AND COALESCE(description_cn,'')=? AND COALESCE(description_en,'')=? LIMIT 1");$s->execute([$row['supplier_id'],$row['description_cn']??'',$row['description_en']??'']);if($s->fetchColumn())return null;
                    ProductWriteService::assertDuplicates($pdo,$row);
                    $fields=['supplier_id','description_cn','description_en','cbm','weight','hs_code','pieces_per_carton','unit_price','buy_price','sell_price','packaging','length_cm','width_cm','height_cm','dimensions_scope'];
                    $values=[];foreach($fields as $field)$values[]=$row[$field]??($field==='weight'?0:($field==='dimensions_scope'?'piece':null));
                    $pdo->prepare('INSERT INTO products('.implode(',',$fields).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')')->execute($values);$newId=(int)$pdo->lastInsertId();
                    (new ItemClassificationService($pdo))->set('product',$newId,$classification['item_type_code'],$classification['confidence'],true,getAuthUserId(),'manual');
                    return $newId;
                });
                jsonResponse(['data'=>$result]);
            }
            $forceCreate = !empty($input['force_create']);
            $cbm = (float) ($input['cbm'] ?? 0);
            $lengthCm = isset($input['length_cm']) ? (float) $input['length_cm'] : null;
            $widthCm = isset($input['width_cm']) ? (float) $input['width_cm'] : null;
            $heightCm = isset($input['height_cm']) ? (float) $input['height_cm'] : null;
            if ($lengthCm > 0 && $widthCm > 0 && $heightCm > 0) {
                $cbm = $lengthCm * $widthCm * $heightCm / 1000000;
            }
            if ($cbm <= 0) {
                jsonError('Provide CBM directly or L/H/W (cm) to calculate CBM', 400);
            }
            $weight = (float) ($input['weight'] ?? 0);
            if ($weight < 0) {
                jsonError('Weight must be non-negative', 400);
            }
            $supplierId = !empty($input['supplier_id']) ? (int) $input['supplier_id'] : null;
            $packaging = $input['packaging'] ?? null;
            $hsCode = $input['hs_code'] ?? null;
            $piecesPerCarton = isset($input['pieces_per_carton']) ? (int) $input['pieces_per_carton'] : null;
            $unitPrice = isset($input['unit_price']) && $input['unit_price'] !== ''
                ? clmsFinancialDecimal($input['unit_price'], 'Unit price', true)
                : null;
            $descriptionEntries = $input['description_entries'] ?? null;
            $descriptionCn = $input['description_cn'] ?? null;
            $descriptionEn = $input['description_en'] ?? null;
            if (is_array($descriptionEntries) && count($descriptionEntries) > 0) {
                $cnParts = [];
                $enParts = [];
                foreach ($descriptionEntries as $e) {
                    $text = trim($e['description_text'] ?? $e['text'] ?? '');
                    if ($text === '') continue;
                    $translated = trim($e['description_translated'] ?? $e['translated'] ?? '');
                    $cnParts[] = $text;
                    $enParts[] = $translated ?: $text;
                }
                $descriptionCn = implode(' | ', $cnParts) ?: null;
                $descriptionEn = implode(' | ', $enParts) ?: null;
            }
            $classification=productConfirmedClassification($pdo,$input,$descriptionEn,$descriptionCn,$supplierId);
            $imagePaths = isset($input['image_paths']) ? json_encode($input['image_paths']) : null;
            ProductWriteService::assertDuplicates($pdo,$input);
            $hasPpc = false;
            $hasUp = false;
            $hasBuy = false;
            $hasSell = false;
            $hasAlert = $hasHighAlertColumn;
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM products WHERE Field IN ('pieces_per_carton','unit_price','buy_price','sell_price')");
                $dbCols = $chk ? array_column($chk->fetchAll(PDO::FETCH_ASSOC), 'Field') : [];
                $hasPpc = in_array('pieces_per_carton', $dbCols, true);
                $hasUp = in_array('unit_price', $dbCols, true);
                $hasBuy = in_array('buy_price', $dbCols, true);
                $hasSell = in_array('sell_price', $dbCols, true);
            } catch (Throwable $e) {
            }
            $buyPrice = isset($input['buy_price']) && $input['buy_price'] !== ''
                ? clmsFinancialDecimal($input['buy_price'], 'Buy price', true)
                : null;
            $sellPrice = isset($input['sell_price']) && $input['sell_price'] !== ''
                ? clmsFinancialDecimal($input['sell_price'], 'Sell price', true)
                : null;
            $highAlertNote = isset($input['high_alert_note']) ? trim((string) $input['high_alert_note']) : null;
            $dimensionsScope = in_array($input['dimensions_scope'] ?? '', ['piece', 'carton'], true) ? $input['dimensions_scope'] : 'piece';
            $requiredDesign = isset($input['required_design']) ? (int) (bool) $input['required_design'] : 0;
            $cols = ['supplier_id', 'cbm', 'weight', 'length_cm', 'width_cm', 'height_cm', 'packaging', 'hs_code', 'description_cn', 'description_en', 'image_paths'];
            $vals = [$supplierId, $cbm, $weight, $lengthCm, $widthCm, $heightCm, $packaging, $hsCode, $descriptionCn, $descriptionEn, $imagePaths];
            if (productHasColumn($pdo, 'dimensions_scope')) {
                $cols[] = 'dimensions_scope';
                $vals[] = $dimensionsScope;
            }
            if (productHasColumn($pdo, 'required_design')) {
                $cols[] = 'required_design';
                $vals[] = $requiredDesign;
            }
            if ($hasPpc) {
                $cols[] = 'pieces_per_carton';
                $vals[] = $piecesPerCarton;
            }
            if ($hasUp) {
                $cols[] = 'unit_price';
                $vals[] = $unitPrice;
            }
            if ($hasBuy) {
                $cols[] = 'buy_price';
                $vals[] = $buyPrice;
            }
            if ($hasSell) {
                $cols[] = 'sell_price';
                $vals[] = $sellPrice;
            }
            if ($hasAlert) {
                $cols[] = 'high_alert_note';
                $vals[] = $highAlertNote ?: null;
            }
            $ph = implode(',', array_fill(0, count($vals), '?'));
            $colStr = implode(', ', $cols);
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO products ($colStr) VALUES ($ph)");
                $stmt->execute($vals);
                $newId = (int) $pdo->lastInsertId();
                (new ItemClassificationService($pdo))->set('product',$newId,$classification['item_type_code'],$classification['confidence'],true,getAuthUserId(),'manual');
                if (hasProductDescEntries($pdo) && is_array($descriptionEntries) && count($descriptionEntries) > 0) {
                    $ins = $pdo->prepare("INSERT INTO product_description_entries (product_id, description_text, description_translated, sort_order) VALUES (?, ?, ?, ?)");
                    foreach ($descriptionEntries as $i => $e) {
                        $text = trim($e['description_text'] ?? $e['text'] ?? '');
                        if ($text === '') continue;
                        $translated = trim($e['description_translated'] ?? $e['translated'] ?? '');
                        $ins->execute([$newId, $text, $translated ?: null, $i]);
                    }
                }
                AuditService::record($pdo,'product',$newId,'create',null,AuditService::snapshot($pdo,'products',$newId),getAuthUserId());
                OperationReplayService::record($pdo,'catalog_products',$newId,$createClaim,[],(int)getAuthUserId());
                $pdo->commit();
            } catch(Throwable $e) {
                if($pdo->inTransaction())$pdo->rollBack();
                throw $e;
            }
            $stmt = $pdo->prepare("SELECT p.*, s.name as supplier_name, ic.item_type_code, ic.confidence item_type_confidence, ic.is_confirmed item_type_confirmed FROM products p LEFT JOIN suppliers s ON p.supplier_id = s.id LEFT JOIN item_classifications ic ON ic.entity_type='product' AND ic.entity_id=p.id WHERE p.id = ?");
            $stmt->execute([$newId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $row['image_paths'] = $row['image_paths'] ? json_decode($row['image_paths'], true) : [];
            if (hasProductDescEntries($pdo)) {
                $stmt2 = $pdo->prepare("SELECT description_text, description_translated, sort_order FROM product_description_entries WHERE product_id = ? ORDER BY sort_order, id");
                $stmt2->execute([$newId]);
                $row['description_entries'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $row['description_entries'] = [];
            }
            jsonResponse(['data' => $row], 201);

        case 'PUT':
            if (!$id) {
                jsonError('ID required', 400);
            }
            $stmt = $pdo->prepare("SELECT id FROM products WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                jsonError('Product not found', 404);
            }
            $cbm = (float) ($input['cbm'] ?? 0);
            $lengthCm = isset($input['length_cm']) ? (float) $input['length_cm'] : null;
            $widthCm = isset($input['width_cm']) ? (float) $input['width_cm'] : null;
            $heightCm = isset($input['height_cm']) ? (float) $input['height_cm'] : null;
            if ($lengthCm > 0 && $widthCm > 0 && $heightCm > 0) {
                $cbm = $lengthCm * $widthCm * $heightCm / 1000000;
            }
            if ($cbm <= 0) {
                jsonError('Provide CBM directly or L/H/W (cm) to calculate CBM', 400);
            }
            $weight = (float) ($input['weight'] ?? 0);
            if ($weight < 0) {
                jsonError('Weight must be non-negative', 400);
            }
            $supplierId = isset($input['supplier_id']) ? ($input['supplier_id'] ? (int) $input['supplier_id'] : null) : null;
            $packaging = $input['packaging'] ?? null;
            $hsCode = $input['hs_code'] ?? null;
            $piecesPerCarton = isset($input['pieces_per_carton']) ? (int) $input['pieces_per_carton'] : null;
            $unitPrice = isset($input['unit_price']) && $input['unit_price'] !== ''
                ? clmsFinancialDecimal($input['unit_price'], 'Unit price', true)
                : null;
            $descriptionEntries = $input['description_entries'] ?? null;
            $descriptionCn = $input['description_cn'] ?? null;
            $descriptionEn = $input['description_en'] ?? null;
            if (is_array($descriptionEntries) && count($descriptionEntries) > 0) {
                $cnParts = [];
                $enParts = [];
                foreach ($descriptionEntries as $e) {
                    $text = trim($e['description_text'] ?? $e['text'] ?? '');
                    if ($text === '') continue;
                    $translated = trim($e['description_translated'] ?? $e['translated'] ?? '');
                    $cnParts[] = $text;
                    $enParts[] = $translated ?: $text;
                }
                $descriptionCn = implode(' | ', $cnParts) ?: null;
                $descriptionEn = implode(' | ', $enParts) ?: null;
            }
            $classification=productConfirmedClassification($pdo,$input,$descriptionEn,$descriptionCn,$supplierId);
            $imagePaths = isset($input['image_paths']) ? json_encode($input['image_paths']) : null;
            $hasPpc = false;
            $hasUp = false;
            $hasBuy = false;
            $hasSell = false;
            $hasAlert = $hasHighAlertColumn;
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM products WHERE Field IN ('pieces_per_carton','unit_price','buy_price','sell_price')");
                $dbCols = $chk ? array_column($chk->fetchAll(PDO::FETCH_ASSOC), 'Field') : [];
                $hasPpc = in_array('pieces_per_carton', $dbCols, true);
                $hasUp = in_array('unit_price', $dbCols, true);
                $hasBuy = in_array('buy_price', $dbCols, true);
                $hasSell = in_array('sell_price', $dbCols, true);
            } catch (Throwable $e) {
            }
            $buyPrice = isset($input['buy_price']) && $input['buy_price'] !== ''
                ? clmsFinancialDecimal($input['buy_price'], 'Buy price', true)
                : null;
            $sellPrice = isset($input['sell_price']) && $input['sell_price'] !== ''
                ? clmsFinancialDecimal($input['sell_price'], 'Sell price', true)
                : null;
            $highAlertNote = isset($input['high_alert_note']) ? trim((string) $input['high_alert_note']) : null;
            $dimensionsScope = in_array($input['dimensions_scope'] ?? '', ['piece', 'carton'], true) ? $input['dimensions_scope'] : 'piece';
            $requiredDesign = isset($input['required_design']) ? (int) (bool) $input['required_design'] : 0;
            $sets = ['supplier_id=?', 'cbm=?', 'weight=?', 'length_cm=?', 'width_cm=?', 'height_cm=?', 'packaging=?', 'hs_code=?', 'description_cn=?', 'description_en=?', 'image_paths=?'];
            $vals = [$supplierId, $cbm, $weight, $lengthCm, $widthCm, $heightCm, $packaging, $hsCode, $descriptionCn, $descriptionEn, $imagePaths];
            if (productHasColumn($pdo, 'dimensions_scope')) {
                $sets[] = 'dimensions_scope=?';
                $vals[] = $dimensionsScope;
            }
            if (productHasColumn($pdo, 'required_design')) {
                $sets[] = 'required_design=?';
                $vals[] = $requiredDesign;
            }
            if ($hasPpc) {
                $sets[] = 'pieces_per_carton=?';
                $vals[] = $piecesPerCarton;
            }
            if ($hasUp) {
                $sets[] = 'unit_price=?';
                $vals[] = $unitPrice;
            }
            if ($hasBuy) {
                $sets[] = 'buy_price=?';
                $vals[] = $buyPrice;
            }
            if ($hasSell) {
                $sets[] = 'sell_price=?';
                $vals[] = $sellPrice;
            }
            if ($hasAlert) {
                $sets[] = 'high_alert_note=?';
                $vals[] = $highAlertNote ?: null;
            }
            $vals[] = $id;
            AuditService::begin($pdo);
            $auditBefore=AuditService::snapshot($pdo,'products',(int)$id,true);
            CatalogRevisionService::assertCurrent($pdo,'products',(int)$id,$input);
            try {
                $pdo->prepare("UPDATE products SET " . implode(', ', $sets) . " WHERE id=?")->execute($vals);
                (new ItemClassificationService($pdo))->set('product',(int)$id,$classification['item_type_code'],$classification['confidence'],true,getAuthUserId(),'manual');
                if (hasProductDescEntries($pdo) && is_array($descriptionEntries)) {
                    $pdo->prepare("DELETE FROM product_description_entries WHERE product_id = ?")->execute([$id]);
                    if (count($descriptionEntries) > 0) {
                        $ins = $pdo->prepare("INSERT INTO product_description_entries (product_id, description_text, description_translated, sort_order) VALUES (?, ?, ?, ?)");
                        foreach ($descriptionEntries as $i => $e) {
                            $text = trim($e['description_text'] ?? $e['text'] ?? '');
                            if ($text === '') continue;
                            $translated = trim($e['description_translated'] ?? $e['translated'] ?? '');
                            $ins->execute([$id, $text, $translated ?: null, $i]);
                        }
                    }
                }
                AuditService::record($pdo,'product',(int)$id,'update',$auditBefore,AuditService::snapshot($pdo,'products',(int)$id),getAuthUserId());
                $pdo->commit();
            } catch(Throwable $e) {
                if($pdo->inTransaction())$pdo->rollBack();
                throw $e;
            }
            $stmt = $pdo->prepare("SELECT p.*, s.name as supplier_name, ic.item_type_code, ic.confidence item_type_confidence, ic.is_confirmed item_type_confirmed FROM products p LEFT JOIN suppliers s ON p.supplier_id = s.id LEFT JOIN item_classifications ic ON ic.entity_type='product' AND ic.entity_id=p.id WHERE p.id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $row['image_paths'] = $row['image_paths'] ? json_decode($row['image_paths'], true) : [];
            if (hasProductDescEntries($pdo)) {
                $stmt2 = $pdo->prepare("SELECT description_text, description_translated, sort_order FROM product_description_entries WHERE product_id = ? ORDER BY sort_order, id");
                $stmt2->execute([$id]);
                $row['description_entries'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $row['description_entries'] = [];
            }
            jsonResponse(['data' => $row]);

        case 'DELETE':
            if (!$id) {
                jsonError('ID required', 400);
            }
            AuditService::begin($pdo);
            $auditBefore=AuditService::snapshot($pdo,'products',(int)$id,true);
            CatalogRevisionService::assertCurrent($pdo,'products',(int)$id,$input);
            try {
                $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
                $pdo->prepare("DELETE FROM item_classifications WHERE entity_type='product' AND entity_id=?")->execute([$id]);
                $stmt->execute([$id]);
                if($stmt->rowCount()===0){$pdo->rollBack();jsonError('Product not found',404);}
                AuditService::record($pdo,'product',(int)$id,'delete',$auditBefore,null,getAuthUserId());
                $pdo->commit();
            } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
            if ($stmt->rowCount() === 0) {
                jsonError('Product not found', 404);
            }
            jsonResponse(['message' => 'Deleted']);

        default:
            jsonError('Method not allowed', 405);
    }
};
