<?php

/**
 * Products API - GET list, GET one, POST create, PUT update, DELETE, GET suggest (duplicate matching)
 */

require_once __DIR__ . '/../helpers.php';

function hasProductDescEntries(PDO $pdo): bool
{
    try {
        $pdo->query("SELECT 1 FROM product_description_entries LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();

    switch ($method) {
        case 'GET':
            if ($id === 'hs-codes') {
                $q = trim($_GET['q'] ?? '');
                $like = strlen($q) >= 1 ? '%' . preg_replace('/\s+/', '%', $q) . '%' : '%';
                $stmt = $pdo->prepare("SELECT DISTINCT hs_code FROM products WHERE hs_code IS NOT NULL AND hs_code != '' AND hs_code LIKE ? ORDER BY hs_code LIMIT 15");
                $stmt->execute([$like]);
                $rows = array_map(fn($r) => ['id' => $r['hs_code'], 'hs_code' => $r['hs_code']], $stmt->fetchAll(PDO::FETCH_ASSOC));
                jsonResponse(['data' => $rows]);
            }
            if ($id === 'search') {
                $q = trim($input['q'] ?? $_GET['q'] ?? '');
                if (strlen($q) < 1) {
                    jsonResponse(['data' => []]);
                }
                $like = '%' . preg_replace('/\s+/', '%', $q) . '%';
                $stmt = $pdo->prepare("SELECT p.id, p.description_cn, p.description_en, p.hs_code, p.cbm, p.weight, p.length_cm, p.width_cm, p.height_cm, p.pieces_per_carton, p.unit_price, p.image_paths, p.supplier_id, s.name as supplier_name FROM products p LEFT JOIN suppliers s ON p.supplier_id = s.id WHERE p.description_cn LIKE ? OR p.description_en LIKE ? OR p.hs_code LIKE ? ORDER BY p.id DESC LIMIT 10");
                $stmt->execute([$like, $like, $like]);
                jsonResponse(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            }
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
                    $r['thumbnail_url'] = !empty($r['image_paths'][0]) ? '/cargochina/backend/' . $r['image_paths'][0] : null;
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
            $row['thumbnail_url'] = !empty($row['image_paths'][0]) ? '/cargochina/backend/' . $row['image_paths'][0] : null;
            if (hasProductDescEntries($pdo)) {
                $stmt2 = $pdo->prepare("SELECT description_text, description_translated, sort_order FROM product_description_entries WHERE product_id = ? ORDER BY sort_order, id");
                $stmt2->execute([$id]);
                $row['description_entries'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $row['description_entries'] = [];
            }
            if (!isset($row['pieces_per_carton'])) $row['pieces_per_carton'] = null;
            if (!isset($row['unit_price'])) $row['unit_price'] = null;
            jsonResponse(['data' => $row]);

        case 'POST':
            if ($id === 'import') {
                $csv = trim($input['csv'] ?? $input['data'] ?? '');
                if (!$csv) jsonError('No CSV data provided', 400);
                $lines = preg_split('/\r\n|\r|\n/', $csv);
                $header = array_map('trim', array_map('strtolower', str_getcsv(array_shift($lines) ?? '')));
                $col = fn($n) => array_search($n, $header) !== false ? array_search($n, $header) : (['description_cn' => 0, 'description_en' => 1, 'cbm' => 2, 'weight' => 3, 'hs_code' => 4, 'pieces_per_carton' => 5, 'unit_price' => 6, 'packaging' => 7, 'supplier_code' => 8][$n] ?? -1);
                $created = 0;
                $skipped = 0;
                $errors = [];
                $hasPpc = (bool) @$pdo->query("SHOW COLUMNS FROM products LIKE 'pieces_per_carton'")->rowCount();
                $hasUp = (bool) @$pdo->query("SHOW COLUMNS FROM products LIKE 'unit_price'")->rowCount();
                $hasLwh = (bool) @$pdo->query("SHOW COLUMNS FROM products LIKE 'length_cm'")->rowCount();
                foreach ($lines as $i => $line) {
                    $row = str_getcsv($line);
                    if (count($row) < 3) continue;
                    $descCn = trim($row[$col('description_cn')] ?? $row[0] ?? '');
                    $descEn = trim($row[$col('description_en')] ?? $row[1] ?? '');
                    $cbm = (float) ($row[$col('cbm')] ?? $row[2] ?? 0);
                    $weight = (float) ($row[$col('weight')] ?? $row[3] ?? 0);
                    if (!$descCn && !$descEn) {
                        $skipped++;
                        continue;
                    }
                    if ($cbm <= 0) {
                        $errors[] = "Row " . ($i + 2) . ": CBM required";
                        continue;
                    }
                    $supplierCode = $col('supplier_code') >= 0 && isset($row[$col('supplier_code')]) ? trim($row[$col('supplier_code')]) : null;
                    $supplierId = null;
                    if ($supplierCode) {
                        $s = $pdo->prepare("SELECT id FROM suppliers WHERE code = ?");
                        $s->execute([$supplierCode]);
                        $supplierId = $s->fetchColumn() ?: null;
                    }
                    $hsCode = $col('hs_code') >= 0 && isset($row[$col('hs_code')]) ? trim($row[$col('hs_code')]) : null;
                    $ppc = $hasPpc && $col('pieces_per_carton') >= 0 && isset($row[$col('pieces_per_carton')]) ? (int) $row[$col('pieces_per_carton')] : null;
                    $up = $hasUp && $col('unit_price') >= 0 && isset($row[$col('unit_price')]) ? (float) $row[$col('unit_price')] : null;
                    $packaging = $col('packaging') >= 0 && isset($row[$col('packaging')]) ? trim($row[$col('packaging')]) : null;
                    $chk = $pdo->prepare("SELECT id FROM products WHERE (? != '' AND description_cn = ?) OR (? != '' AND description_en = ?)");
                    $chk->execute([$descCn, $descCn, $descEn, $descEn]);
                    if ($chk->fetch()) {
                        $skipped++;
                        continue;
                    }
                    $cols = ['supplier_id', 'cbm', 'weight', 'description_cn', 'description_en', 'hs_code', 'packaging', 'image_paths'];
                    $vals = [$supplierId, $cbm, $weight, $descCn ?: null, $descEn ?: null, $hsCode ?: null, $packaging ?: null, null];
                    if ($hasLwh) {
                        $cols[] = 'length_cm';
                        $cols[] = 'width_cm';
                        $cols[] = 'height_cm';
                        $vals[] = null;
                        $vals[] = null;
                        $vals[] = null;
                    }
                    if ($hasPpc) {
                        $cols[] = 'pieces_per_carton';
                        $vals[] = $ppc;
                    }
                    if ($hasUp) {
                        $cols[] = 'unit_price';
                        $vals[] = $up;
                    }
                    try {
                        $ph = implode(',', array_fill(0, count($vals), '?'));
                        $pdo->prepare("INSERT INTO products (" . implode(',', $cols) . ") VALUES ($ph)")->execute($vals);
                        $created++;
                    } catch (PDOException $e) {
                        $errors[] = "Row " . ($i + 2) . ": " . $e->getMessage();
                    }
                }
                jsonResponse(['data' => ['created' => $created, 'skipped' => $skipped, 'errors' => $errors]]);
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
            $unitPrice = isset($input['unit_price']) ? (float) $input['unit_price'] : null;
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
            $hasPpc = false;
            $hasUp = false;
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM products WHERE Field IN ('pieces_per_carton','unit_price')");
                $cols = $chk ? array_column($chk->fetchAll(PDO::FETCH_ASSOC), 'Field') : [];
                $hasPpc = in_array('pieces_per_carton', $cols, true);
                $hasUp = in_array('unit_price', $cols, true);
            } catch (Throwable $e) {
            }
            $cols = ['supplier_id', 'cbm', 'weight', 'length_cm', 'width_cm', 'height_cm', 'packaging', 'hs_code', 'description_cn', 'description_en', 'image_paths'];
            $vals = [$supplierId, $cbm, $weight, $lengthCm, $widthCm, $heightCm, $packaging, $hsCode, $descriptionCn, $descriptionEn, $imagePaths];
            if ($hasPpc) {
                $cols[] = 'pieces_per_carton';
                $vals[] = $piecesPerCarton;
            }
            if ($hasUp) {
                $cols[] = 'unit_price';
                $vals[] = $unitPrice;
            }
            $ph = implode(',', array_fill(0, count($vals), '?'));
            $colStr = implode(', ', $cols);
            $stmt = $pdo->prepare("INSERT INTO products ($colStr) VALUES ($ph)");
            $stmt->execute($vals);
            $newId = (int) $pdo->lastInsertId();
            if (hasProductDescEntries($pdo) && is_array($descriptionEntries) && count($descriptionEntries) > 0) {
                $ins = $pdo->prepare("INSERT INTO product_description_entries (product_id, description_text, description_translated, sort_order) VALUES (?, ?, ?, ?)");
                foreach ($descriptionEntries as $i => $e) {
                    $text = trim($e['description_text'] ?? $e['text'] ?? '');
                    if ($text === '') continue;
                    $translated = trim($e['description_translated'] ?? $e['translated'] ?? '');
                    $ins->execute([$newId, $text, $translated ?: null, $i]);
                }
            }
            $stmt = $pdo->prepare("SELECT p.*, s.name as supplier_name FROM products p LEFT JOIN suppliers s ON p.supplier_id = s.id WHERE p.id = ?");
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
            $unitPrice = isset($input['unit_price']) ? (float) $input['unit_price'] : null;
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
            $imagePaths = isset($input['image_paths']) ? json_encode($input['image_paths']) : null;
            $hasPpc = false;
            $hasUp = false;
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM products WHERE Field IN ('pieces_per_carton','unit_price')");
                $cols = $chk ? array_column($chk->fetchAll(PDO::FETCH_ASSOC), 'Field') : [];
                $hasPpc = in_array('pieces_per_carton', $cols, true);
                $hasUp = in_array('unit_price', $cols, true);
            } catch (Throwable $e) {
            }
            $sets = ['supplier_id=?', 'cbm=?', 'weight=?', 'length_cm=?', 'width_cm=?', 'height_cm=?', 'packaging=?', 'hs_code=?', 'description_cn=?', 'description_en=?', 'image_paths=?'];
            $vals = [$supplierId, $cbm, $weight, $lengthCm, $widthCm, $heightCm, $packaging, $hsCode, $descriptionCn, $descriptionEn, $imagePaths];
            if ($hasPpc) {
                $sets[] = 'pieces_per_carton=?';
                $vals[] = $piecesPerCarton;
            }
            if ($hasUp) {
                $sets[] = 'unit_price=?';
                $vals[] = $unitPrice;
            }
            $vals[] = $id;
            $pdo->prepare("UPDATE products SET " . implode(', ', $sets) . " WHERE id=?")->execute($vals);
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
            $stmt = $pdo->prepare("SELECT p.*, s.name as supplier_name FROM products p LEFT JOIN suppliers s ON p.supplier_id = s.id WHERE p.id = ?");
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
