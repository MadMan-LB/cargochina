<?php

/**
 * Products API - GET list, GET one, POST create, PUT update, DELETE, GET suggest (duplicate matching)
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();

    switch ($method) {
        case 'GET':
            if ($id === 'suggest') {
                $q = trim($input['q'] ?? $_GET['q'] ?? '');
                if (strlen($q) < 2) {
                    jsonResponse(['data' => []]);
                }
                $like = "%$q%";
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
                $stmt = $pdo->query("SELECT p.*, s.name as supplier_name FROM products p LEFT JOIN suppliers s ON p.supplier_id = s.id ORDER BY p.id DESC");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as &$r) {
                    $r['image_paths'] = $r['image_paths'] ? json_decode($r['image_paths'], true) : [];
                }
                jsonResponse(['data' => $rows]);
            }
            $stmt = $pdo->prepare("SELECT p.*, s.name as supplier_name FROM products p LEFT JOIN suppliers s ON p.supplier_id = s.id WHERE p.id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                jsonError('Product not found', 404);
            }
            $row['image_paths'] = $row['image_paths'] ? json_decode($row['image_paths'], true) : [];
            jsonResponse(['data' => $row]);

        case 'POST':
            $forceCreate = !empty($input['force_create']);
            $cbm = (float) ($input['cbm'] ?? 0);
            $weight = (float) ($input['weight'] ?? 0);
            if ($cbm < 0 || $weight < 0) {
                jsonError('CBM and weight must be non-negative', 400);
            }
            $supplierId = !empty($input['supplier_id']) ? (int) $input['supplier_id'] : null;
            $packaging = $input['packaging'] ?? null;
            $hsCode = $input['hs_code'] ?? null;
            $descriptionCn = $input['description_cn'] ?? null;
            $descriptionEn = $input['description_en'] ?? null;
            $imagePaths = isset($input['image_paths']) ? json_encode($input['image_paths']) : null;
            if (!$forceCreate && ($descriptionCn || $descriptionEn || $hsCode)) {
                $search = trim($descriptionCn ?: $descriptionEn ?: $hsCode ?: '');
                if (strlen($search) >= 2) {
                    $like = '%' . $search . '%';
                    $stmt = $pdo->prepare("SELECT id, description_cn, description_en, hs_code FROM products WHERE description_cn LIKE ? OR description_en LIKE ? OR hs_code LIKE ? LIMIT 10");
                    $stmt->execute([$like, $like, $like]);
                    $dupes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($dupes as $d) {
                        $compare = $d['description_cn'] ?: $d['description_en'] ?: $d['hs_code'] ?: '';
                        similar_text($search, $compare, $pct);
                        if ($pct >= 70) {
                            jsonError('Possible duplicate product. Use force_create=true to create anyway, or reuse existing product #' . $d['id'], 409, ['suggested_ids' => array_column($dupes, 'id')]);
                        }
                    }
                }
            }
            $stmt = $pdo->prepare("INSERT INTO products (supplier_id, cbm, weight, packaging, hs_code, description_cn, description_en, image_paths) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$supplierId, $cbm, $weight, $packaging, $hsCode, $descriptionCn, $descriptionEn, $imagePaths]);
            $newId = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare("SELECT p.*, s.name as supplier_name FROM products p LEFT JOIN suppliers s ON p.supplier_id = s.id WHERE p.id = ?");
            $stmt->execute([$newId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $row['image_paths'] = $row['image_paths'] ? json_decode($row['image_paths'], true) : [];
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
            $weight = (float) ($input['weight'] ?? 0);
            if ($cbm < 0 || $weight < 0) {
                jsonError('CBM and weight must be non-negative', 400);
            }
            $supplierId = isset($input['supplier_id']) ? ($input['supplier_id'] ? (int) $input['supplier_id'] : null) : null;
            $packaging = $input['packaging'] ?? null;
            $hsCode = $input['hs_code'] ?? null;
            $descriptionCn = $input['description_cn'] ?? null;
            $descriptionEn = $input['description_en'] ?? null;
            $imagePaths = isset($input['image_paths']) ? json_encode($input['image_paths']) : null;
            $pdo->prepare("UPDATE products SET supplier_id=?, cbm=?, weight=?, packaging=?, hs_code=?, description_cn=?, description_en=?, image_paths=? WHERE id=?")
                ->execute([$supplierId, $cbm, $weight, $packaging, $hsCode, $descriptionCn, $descriptionEn, $imagePaths, $id]);
            $stmt = $pdo->prepare("SELECT p.*, s.name as supplier_name FROM products p LEFT JOIN suppliers s ON p.supplier_id = s.id WHERE p.id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $row['image_paths'] = $row['image_paths'] ? json_decode($row['image_paths'], true) : [];
            jsonResponse(['data' => $row]);

        case 'DELETE':
            if (!$id) {
                jsonError('ID required', 400);
            }
            $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                jsonError('Product not found', 404);
            }
            jsonResponse(['message' => 'Deleted']);

        default:
            jsonError('Method not allowed', 405);
    }
};
