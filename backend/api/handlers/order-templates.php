<?php

/**
 * Order Templates API - list, get, create (save item sets for reuse)
 * GET /order-templates - list
 * GET /order-templates/{id} - get with items
 * POST /order-templates - create { name, items[] }
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__,2).'/services/OperationReplayService.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    require_once __DIR__ . '/../authorization.php';
    clmsAuthorizeApiRequest('order-templates', $method, $id, $action);
    $userId = getAuthUserId();
    if (!$userId) {
        jsonError('Unauthorized', 401);
    }

    $pdo = getDb();
    requirePermission($method==='GET'?'orders.read':'orders.write');

    if ($method === 'GET' && $id === null) {
        $stmt = $pdo->query("SELECT id, name, created_at FROM order_templates ORDER BY name");
        jsonResponse(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($method === 'GET' && $id !== null) {
        $stmt = $pdo->prepare("SELECT id, name, created_at FROM order_templates WHERE id = ?");
        $stmt->execute([$id]);
        $tpl = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tpl) {
            jsonError('Template not found', 404);
        }
        $chk = @$pdo->query("SHOW COLUMNS FROM order_template_items LIKE 'supplier_id'");
        $hasSupplier = $chk && $chk->rowCount() > 0;
        $sql = $hasSupplier
            ? "SELECT oti.*, s.name as supplier_name FROM order_template_items oti LEFT JOIN suppliers s ON oti.supplier_id = s.id WHERE oti.template_id = ? ORDER BY oti.sort_order, oti.id"
            : "SELECT * FROM order_template_items WHERE template_id = ? ORDER BY sort_order, id";
        $si = $pdo->prepare($sql);
        $si->execute([$id]);
        $tpl['items'] = $si->fetchAll(PDO::FETCH_ASSOC);
        $tpl['requires_measurement_review'] = count(array_filter($tpl['items'], static fn(array $item): bool => !in_array($item['dimensions_scope'] ?? null, ['piece', 'carton'], true))) > 0;
        jsonResponse(['data' => $tpl]);
    }

    if ($method === 'POST' && $id === null) {
        if(!is_string($input['name']??null)||mb_strlen(trim($input['name']))>255)jsonError('Template name must be text within 255 characters',422);
        $input['items']=OrderWriteService::standardItems($pdo,$input['items']??null,null);
        foreach($input['items'] as &$item){if(!in_array($item['dimensions_scope']??'piece',['piece','carton'],true))jsonError('Invalid template dimensions scope',422);$item['item_no']=null;$item['shipping_code']=null;unset($item['id'],$item['existing_item_id']);}
        unset($item);
        $claim=OperationReplayService::claim($pdo,'order_template',$input,$userId);
        if($claim['previous_id'])jsonResponse(['data'=>['id'=>$claim['previous_id'],'name'=>$input['name']],'idempotent_replay'=>true]);
        $name = trim($input['name'] ?? '');
        $items = $input['items'] ?? [];
        if ($name === '') {
            jsonError('Template name is required', 400);
        }
        if (!is_array($items) || empty($items)) {
            jsonError('At least one item is required', 400);
        }

        $pdo->beginTransaction();
        register_shutdown_function(static function()use($pdo){if($pdo->inTransaction())$pdo->rollBack();});
        try {
            $ins = $pdo->prepare("INSERT INTO order_templates (name, created_by) VALUES (?, ?)");
            $ins->execute([$name, $userId]);
            $templateId = (int) $pdo->lastInsertId();

            $chk = @$pdo->query("SHOW COLUMNS FROM order_template_items LIKE 'supplier_id'");
            $hasSupplier = $chk && $chk->rowCount() > 0;
            $insCols = "template_id, sort_order, item_no, shipping_code, product_id, description_cn, description_en, cartons, qty_per_carton, quantity, unit, declared_cbm, declared_weight, item_length, item_width, item_height, unit_price, total_amount, notes";
            $insVals = "?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?";
            $metadataColumns = [];
            foreach (['dimensions_scope','sell_price','item_number', 'what_brand', 'brand', 'materials', 'copy_normal_goods', 'code', 'express_number', 'size', 'length', 'width', 'height'] as $column) {
                $colChk = @$pdo->query("SHOW COLUMNS FROM order_template_items LIKE " . $pdo->quote($column));
                if ($colChk && $colChk->rowCount() > 0) {
                    $metadataColumns[] = $column;
                    $insCols .= ", $column";
                    $insVals .= ",?";
                }
            }
            if ($hasSupplier) {
                $insCols .= ", supplier_id";
                $insVals .= ",?";
            }
            if(!in_array('dimensions_scope',$metadataColumns,true)||!in_array('sell_price',$metadataColumns,true))jsonError('Template measurement migration 082 is required',503);
            $insItem = $pdo->prepare("INSERT INTO order_template_items ($insCols) VALUES ($insVals)");

            foreach ($items as $idx => $it) {
                $qty = (float) ($it['quantity'] ?? 0);
                $cartons = isset($it['cartons']) ? (int) $it['cartons'] : null;
                $qtyPerCtn = isset($it['qty_per_carton']) ? (float) $it['qty_per_carton'] : null;
                if ($qty <= 0 && ($cartons ?? 0) <= 0) continue;

                $unit = in_array($it['unit'] ?? '', ['cartons', 'pieces']) ? $it['unit'] : 'cartons';
                $desc = $it['description_cn'] ?? $it['description'] ?? '';
                $supplierId = !empty($it['supplier_id']) ? (int) $it['supplier_id'] : null;
                $params = [
                    $templateId,
                    $idx,
                    $it['item_no'] ?? null,
                    $it['shipping_code'] ?? null,
                    !empty($it['product_id']) ? (int) $it['product_id'] : null,
                    $desc,
                    $it['description_en'] ?? null,
                    $cartons,
                    $qtyPerCtn,
                    $qty > 0 ? $qty : null,
                    $unit,
                    isset($it['declared_cbm']) ? (float) $it['declared_cbm'] : null,
                    isset($it['declared_weight']) ? (float) $it['declared_weight'] : null,
                    isset($it['item_length']) ? (float) $it['item_length'] : (isset($it['length']) ? (float) $it['length'] : null),
                    isset($it['item_width']) ? (float) $it['item_width'] : (isset($it['width']) ? (float) $it['width'] : null),
                    isset($it['item_height']) ? (float) $it['item_height'] : (isset($it['height']) ? (float) $it['height'] : null),
                    isset($it['unit_price']) ? (float) $it['unit_price'] : null,
                    isset($it['total_amount']) ? (float) $it['total_amount'] : null,
                    $it['notes'] ?? null,
                ];
                foreach ($metadataColumns as $column) {
                    if ($column === 'dimensions_scope') {
                        $params[]=$it['dimensions_scope']??'piece';
                    } elseif ($column === 'item_number') {
                        $params[] = clmsPackingListItemNumber($it['item_number'] ?? null);
                    } elseif (in_array($column, ['length', 'width', 'height'], true)) {
                        $params[] = isset($it[$column]) ? (float) $it[$column] : (isset($it['item_' . $column]) ? (float) $it['item_' . $column] : null);
                    } elseif ($column === 'brand') {
                        $params[] = $it['brand'] ?? $it['what_brand'] ?? null;
                    } elseif ($column === 'what_brand') {
                        $params[] = $it['what_brand'] ?? $it['brand'] ?? null;
                    } else {
                        $params[] = $it[$column] ?? null;
                    }
                }
                if ($hasSupplier) {
                    $params[] = $supplierId;
                }
                $insItem->execute($params);
            }
            OperationReplayService::record($pdo,'order_template',$templateId,$claim,['name'=>$name,'item_count'=>count($items)],$userId);
            $pdo->commit();
            jsonResponse(['data' => ['id' => $templateId, 'name' => $name]], 201);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    jsonError('Method not allowed', 405);
};
