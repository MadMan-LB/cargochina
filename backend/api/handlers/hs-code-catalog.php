<?php

/**
 * HS Code Tariff Catalog API - search and import
 * Reference data from Lebanon customs (hs codes/ folder).
 * Roles: read = products roles; import = SuperAdmin only
 */

require_once __DIR__ . '/../helpers.php';

function normalizeHsCatalogSearchValue(?string $value): string
{
    $value = strtoupper(trim((string) $value));
    $value = preg_replace('/\s+/', '', $value);
    return preg_replace('/[^A-Z0-9]/', '', $value);
}

function hsCatalogNormalizedCodeSql(string $column = 'hs_code'): string
{
    return "REPLACE(REPLACE(REPLACE(UPPER($column), '.', ''), '-', ''), ' ', '')";
}

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();
    $userId = getAuthUserId();
    if (!$userId) {
        jsonError('Unauthorized', 401);
    }

    $tableCheck = @$pdo->query("SHOW TABLES LIKE 'hs_code_tariff_catalog'");
    if (!$tableCheck || $tableCheck->rowCount() === 0) {
        jsonError('hs_code_tariff_catalog table not found. Run migration 042 first.', 500);
    }

    switch ($method) {
        case 'GET':
            if (!hasAnyRole(['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'SuperAdmin'])) {
                jsonError('Forbidden', 403);
            }
            if ($id === 'files') {
                if (!hasAnyRole(['SuperAdmin'])) {
                    jsonError('Forbidden', 403);
                }
                $baseDir = dirname(__DIR__, 3) . '/hs codes';
                $files = [];
                if (is_dir($baseDir)) {
                    foreach (glob($baseDir . '/*.csv') as $f) {
                        $files[] = ['name' => basename($f), 'size' => filesize($f)];
                    }
                }
                jsonResponse(['data' => $files]);
            }
            if ($id !== null && $id !== 'search') {
                jsonError('Not found', 404);
            }
            $q = trim($_GET['q'] ?? '');
            $limit = min(500, max(5, (int) ($_GET['limit'] ?? 15)));
            if (strlen($q) < 1) {
                jsonResponse(['data' => [], 'meta' => ['query' => '', 'total' => 0, 'returned' => 0, 'limit' => $limit, 'truncated' => false, 'match_mode' => 'empty']]);
            }
            $normalizedQuery = normalizeHsCatalogSearchValue($q);
            $normalizedCodeSql = hsCatalogNormalizedCodeSql('hs_code');
            $numericLikeQuery = preg_match('/^[0-9.\-\s]+$/', $q) === 1;

            if ($numericLikeQuery && $normalizedQuery !== '') {
                $prefixParam = $normalizedQuery . '%';

                $countStmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM hs_code_tariff_catalog
                    WHERE {$normalizedCodeSql} LIKE ?
                ");
                $countStmt->execute([$prefixParam]);
                $total = (int) $countStmt->fetchColumn();

                $stmt = $pdo->prepare("
                    SELECT id, hs_code, name, category, tariff_rate, vat, section_name
                    FROM hs_code_tariff_catalog
                    WHERE {$normalizedCodeSql} LIKE ?
                    ORDER BY CASE
                        WHEN {$normalizedCodeSql} = ? THEN 0
                        ELSE 1
                    END ASC,
                    CHAR_LENGTH({$normalizedCodeSql}) ASC,
                    hs_code ASC
                    LIMIT {$limit}
                ");
                $stmt->execute([$prefixParam, $normalizedQuery]);
                $matchMode = 'hs_code_prefix';
            } else {
                $like = '%' . preg_replace('/\s+/', '%', $q) . '%';
                $prefix = $q . '%';

                $countStmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM hs_code_tariff_catalog
                    WHERE name LIKE ? OR category LIKE ? OR section_name LIKE ? OR hs_code LIKE ?
                ");
                $countStmt->execute([$like, $like, $like, $like]);
                $total = (int) $countStmt->fetchColumn();

                $stmt = $pdo->prepare("
                    SELECT id, hs_code, name, category, tariff_rate, vat, section_name
                    FROM hs_code_tariff_catalog
                    WHERE name LIKE ? OR category LIKE ? OR section_name LIKE ? OR hs_code LIKE ?
                    ORDER BY CASE
                        WHEN name LIKE ? THEN 0
                        WHEN category LIKE ? THEN 1
                        WHEN section_name LIKE ? THEN 2
                        WHEN hs_code LIKE ? THEN 3
                        ELSE 4
                    END ASC,
                    CHAR_LENGTH(hs_code) ASC,
                    hs_code ASC
                    LIMIT {$limit}
                ");
                $stmt->execute([$like, $like, $like, $like, $prefix, $prefix, $prefix, $prefix]);
                $matchMode = 'text_prefix_then_contains';
            }

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $data = array_map(function ($r) {
                return [
                    'id' => $r['hs_code'],
                    'hs_code' => $r['hs_code'],
                    'name' => $r['name'],
                    'category' => $r['category'],
                    'tariff_rate' => $r['tariff_rate'],
                    'vat' => $r['vat'],
                    'section_name' => $r['section_name'],
                ];
            }, $rows);
            jsonResponse([
                'data' => $data,
                'meta' => [
                    'query' => $q,
                    'normalized_query' => $normalizedQuery,
                    'total' => $total,
                    'returned' => count($data),
                    'limit' => $limit,
                    'truncated' => $total > count($data),
                    'match_mode' => $matchMode,
                ],
            ]);

        case 'POST':
            if (!hasAnyRole(['SuperAdmin'])) {
                jsonError('Forbidden. Only SuperAdmin can import HS catalog.', 403);
            }
            if ($id !== 'import') {
                jsonError('Use POST /hs-code-catalog/import', 400);
            }
            $source = trim($input['source'] ?? '');
            $baseDir = dirname(__DIR__, 3) . '/hs codes';
            $csvPath = null;
            if ($source === 'lebanon_customs_tariffs.csv' || $source === '') {
                $csvPath = $baseDir . '/lebanon_customs_tariffs.csv';
            } elseif (preg_match('/^[a-zA-Z0-9_\-\.]+\.csv$/', $source)) {
                $csvPath = $baseDir . '/' . $source;
            }
            if (!$csvPath || !is_file($csvPath)) {
                jsonError('CSV file not found. Place lebanon_customs_tariffs.csv in the "hs codes" folder.', 400);
            }
            $fp = fopen($csvPath, 'r');
            if (!$fp) {
                jsonError('Could not open CSV file', 500);
            }
            $header = fgetcsv($fp);
            if (!$header) {
                fclose($fp);
                jsonError('Invalid CSV: could not read header row', 400);
            }
            if (isset($header[0])) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            }
            if (!in_array('hs_code', $header, true)) {
                fclose($fp);
                jsonError('Invalid CSV: missing hs_code column. Expected: hs_code,name,category,tariff_rate,vat,...', 400);
            }
            $pdo->exec("TRUNCATE TABLE hs_code_tariff_catalog");
            $stmt = $pdo->prepare("
                INSERT INTO hs_code_tariff_catalog (hs_code, name, category, tariff_rate, vat, parent_directory_code, parent_directory_name, section_code, section_name, source_file)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $idx = array_flip($header);
            $get = function ($row, $key) use ($idx) {
                $i = $idx[$key] ?? -1;
                return $i >= 0 && isset($row[$i]) ? trim($row[$i]) : null;
            };
            $count = 0;
            while (($row = fgetcsv($fp)) !== false) {
                $hsCode = $get($row, 'hs_code');
                if (!$hsCode) continue;
                $stmt->execute([
                    $hsCode,
                    $get($row, 'name') ?: null,
                    $get($row, 'category') ?: null,
                    $get($row, 'tariff_rate') ?: null,
                    $get($row, 'vat') ?: null,
                    $get($row, 'parent_directory_code') ?: null,
                    $get($row, 'parent_directory_name') ?: null,
                    $get($row, 'section_code') ?: null,
                    $get($row, 'section_name') ?: null,
                    basename($csvPath),
                ]);
                $count++;
            }
            fclose($fp);
            logClms('hs_catalog_import', ['count' => $count, 'file' => basename($csvPath), 'user_id' => $userId]);
            jsonResponse(['data' => ['imported' => $count, 'file' => basename($csvPath)]]);

        default:
            jsonError('Method not allowed', 405);
    }
};
