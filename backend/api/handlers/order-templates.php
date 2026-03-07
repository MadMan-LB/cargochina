<?php

/**
 * Order Templates API - list, get, create (save item sets for reuse)
 * GET /order-templates - list
 * GET /order-templates/{id} - get with items
 * POST /order-templates - create { name, items[] }
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    $userId = getAuthUserId();
    if (!$userId) {
        jsonError('Unauthorized', 401);
    }

    $pdo = getDb();

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
        $si = $pdo->prepare("SELECT * FROM order_template_items WHERE template_id = ? ORDER BY sort_order, id");
        $si->execute([$id]);
        $tpl['items'] = $si->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(['data' => $tpl]);
    }

    if ($method === 'POST' && $id === null) {
        $name = trim($input['name'] ?? '');
        $items = $input['items'] ?? [];
        if ($name === '') {
            jsonError('Template name is required', 400);
        }
        if (!is_array($items) || empty($items)) {
            jsonError('At least one item is required', 400);
        }

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare("INSERT INTO order_templates (name, created_by) VALUES (?, ?)");
            $ins->execute([$name, $userId]);
            $templateId = (int) $pdo->lastInsertId();

            $insItem = $pdo->prepare("INSERT INTO order_template_items (template_id, sort_order, item_no, shipping_code, product_id, description_cn, description_en, cartons, qty_per_carton, quantity, unit, declared_cbm, declared_weight, item_length, item_width, item_height, unit_price, total_amount, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

            foreach ($items as $idx => $it) {
                $qty = (float) ($it['quantity'] ?? 0);
                $cartons = isset($it['cartons']) ? (int) $it['cartons'] : null;
                $qtyPerCtn = isset($it['qty_per_carton']) ? (float) $it['qty_per_carton'] : null;
                if ($qty <= 0 && ($cartons ?? 0) <= 0) continue;

                $unit = in_array($it['unit'] ?? '', ['cartons', 'pieces']) ? $it['unit'] : 'cartons';
                $desc = $it['description_cn'] ?? $it['description'] ?? '';
                $insItem->execute([
                    $templateId,
                    $idx,
                    $it['item_no'] ?? null,
                    $it['shipping_code'] ?? null,
                    $it['product_id'] ? (int) $it['product_id'] : null,
                    $desc,
                    $it['description_en'] ?? null,
                    $cartons,
                    $qtyPerCtn,
                    $qty > 0 ? $qty : null,
                    $unit,
                    isset($it['declared_cbm']) ? (float) $it['declared_cbm'] : null,
                    isset($it['declared_weight']) ? (float) $it['declared_weight'] : null,
                    isset($it['item_length']) ? (float) $it['item_length'] : null,
                    isset($it['item_width']) ? (float) $it['item_width'] : null,
                    isset($it['item_height']) ? (float) $it['item_height'] : null,
                    isset($it['unit_price']) ? (float) $it['unit_price'] : null,
                    isset($it['total_amount']) ? (float) $it['total_amount'] : null,
                    $it['notes'] ?? null,
                ]);
            }
            $pdo->commit();
            jsonResponse(['data' => ['id' => $templateId, 'name' => $name]], 201);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    jsonError('Method not allowed', 405);
};
