<?php

/**
 * HS Code Tariff Catalog API - search and import
 * Reference data from Lebanon customs (hs codes/ folder).
 * Roles: safe lookup = operational roles; import/translation = SuperAdmin only
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 2) . '/services/TranslationService.php';
require_once dirname(__DIR__, 2) . '/services/HsCatalogImportService.php';

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

function hsCatalogHasColumn(PDO $pdo, string $column): bool
{
    static $cache = [];
    if (array_key_exists($column, $cache)) return $cache[$column];
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'hs_code_tariff_catalog'
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$column]);
    return $cache[$column] = ((int) $stmt->fetchColumn() > 0);
}

function normalizeHsCatalogCsvHeader(string $header): string
{
    $header = preg_replace('/^\xEF\xBB\xBF/', '', trim($header));
    $header = strtolower($header);
    return trim((string) preg_replace('/[^a-z0-9]+/', '_', $header), '_');
}

return function (string $method, ?string $id, ?string $action, array $input) {
    require_once __DIR__ . '/../authorization.php';
    clmsAuthorizeApiRequest('hs-code-catalog', $method, $id, $action);
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
            if (!hasPermission('hs-code-catalog.read', ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'ContainersStaff', 'FieldStaff', 'SuperAdmin'])) {
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
            $hasBilingualNames = hsCatalogHasColumn($pdo, 'name_en') && hsCatalogHasColumn($pdo, 'name_zh');
            $languageSelect = $hasBilingualNames ? ', name_en, name_zh' : ', NULL AS name_en, NULL AS name_zh';

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
                    SELECT id, hs_code, name {$languageSelect}, category, tariff_rate, vat, section_name
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
                $textColumns = $hasBilingualNames
                    ? ['name_en', 'name_zh', 'name', 'category', 'section_name', 'hs_code']
                    : ['name', 'category', 'section_name', 'hs_code'];
                $whereSql = implode(' OR ', array_map(fn($column) => "{$column} LIKE ?", $textColumns));
                $whereParams = array_fill(0, count($textColumns), $like);
                $rankSql = [];
                foreach ($textColumns as $rank => $column) {
                    $rankSql[] = "WHEN {$column} LIKE ? THEN {$rank}";
                }

                $countStmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM hs_code_tariff_catalog
                    WHERE {$whereSql}
                ");
                $countStmt->execute($whereParams);
                $total = (int) $countStmt->fetchColumn();

                $stmt = $pdo->prepare("
                    SELECT id, hs_code, name {$languageSelect}, category, tariff_rate, vat, section_name
                    FROM hs_code_tariff_catalog
                    WHERE {$whereSql}
                    ORDER BY CASE
                        " . implode("\n", $rankSql) . "
                        ELSE " . count($textColumns) . "
                    END ASC,
                    CHAR_LENGTH(hs_code) ASC,
                    hs_code ASC
                    LIMIT {$limit}
                ");
                $stmt->execute(array_merge($whereParams, array_fill(0, count($textColumns), $prefix)));
                $matchMode = 'text_prefix_then_contains';
            }

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $data = array_map(function ($r) {
                return [
                    'id' => $r['hs_code'],
                    'hs_code' => $r['hs_code'],
                    'name' => $r['name'],
                    'name_en' => $r['name_en'],
                    'name_zh' => $r['name_zh'],
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
                jsonError('Forbidden. Only SuperAdmin can manage the HS catalog.', 403);
            }
            if ($id === 'translate') {
                MasterDataImportService::lock($pdo,'hs_catalog');
                if (!hsCatalogHasColumn($pdo, 'name_en') || !hsCatalogHasColumn($pdo, 'name_zh')) {
                    jsonError('Run migration 080 before translating the HS catalog.', 409);
                }
                $limit = min(250, max(10, (int) ($input['limit'] ?? 100)));
                $stmt = $pdo->prepare("
                    SELECT id, name, name_en, name_zh
                    FROM hs_code_tariff_catalog
                    WHERE TRIM(COALESCE(name, '')) <> ''
                      AND (TRIM(COALESCE(name_en, '')) = '' OR TRIM(COALESCE(name_zh, '')) = '')
                    ORDER BY id
                    LIMIT {$limit}
                ");
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!$rows) {
                    jsonResponse(['data' => ['processed' => 0, 'updated' => 0, 'translated_values' => 0, 'remaining' => 0, 'errors' => []]]);
                }

                $service = new TranslationService($pdo);
                $missingEn = [];
                $missingZh = [];
                foreach ($rows as $row) {
                    if (trim((string) $row['name_en']) === '') $missingEn[] = $row;
                    if (trim((string) $row['name_zh']) === '') $missingZh[] = $row;
                }
                $enResults = $service->translateBatchDetailed(array_column($missingEn, 'name'), 'auto', 'en');
                $zhResults = $service->translateBatchDetailed(array_column($missingZh, 'name'), 'auto', 'zh');
                $updates = [];
                $errors = [];
                foreach ($missingEn as $index => $row) {
                    $result = $enResults[$index] ?? [];
                    $translated = trim((string) ($result['translated_text'] ?? ''));
                    if ($translated !== '') $updates[(int) $row['id']]['name_en'] = $translated;
                    elseif (!empty($result['error_code'])) $errors[] = (string) $result['error_code'];
                }
                foreach ($missingZh as $index => $row) {
                    $result = $zhResults[$index] ?? [];
                    $translated = trim((string) ($result['translated_text'] ?? ''));
                    if ($translated !== '') $updates[(int) $row['id']]['name_zh'] = $translated;
                    elseif (!empty($result['error_code'])) $errors[] = (string) $result['error_code'];
                }

                $updateStmt = $pdo->prepare("
                    UPDATE hs_code_tariff_catalog
                    SET name_en = COALESCE(?, name_en),
                        name_zh = COALESCE(?, name_zh),
                        translated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $translatedValues = 0;
                foreach ($updates as $rowId => $values) {
                    $nameEn = $values['name_en'] ?? null;
                    $nameZh = $values['name_zh'] ?? null;
                    $updateStmt->execute([$nameEn, $nameZh, $rowId]);
                    $translatedValues += ($nameEn !== null ? 1 : 0) + ($nameZh !== null ? 1 : 0);
                }
                $remaining = (int) $pdo->query("
                    SELECT COUNT(*) FROM hs_code_tariff_catalog
                    WHERE TRIM(COALESCE(name, '')) <> ''
                      AND (TRIM(COALESCE(name_en, '')) = '' OR TRIM(COALESCE(name_zh, '')) = '')
                ")->fetchColumn();
                $errors = array_values(array_unique($errors));
                logClms('hs_catalog_translate', [
                    'processed' => count($rows),
                    'updated' => count($updates),
                    'translated_values' => $translatedValues,
                    'remaining' => $remaining,
                    'errors' => $errors,
                    'user_id' => $userId,
                ]);
                jsonResponse(['data' => [
                    'processed' => count($rows),
                    'updated' => count($updates),
                    'translated_values' => $translatedValues,
                    'remaining' => $remaining,
                    'errors' => $errors,
                ]]);
            }
            if ($id !== 'import') {
                jsonError('Use POST /hs-code-catalog/import or /hs-code-catalog/translate', 400);
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
            if(filesize($csvPath)>16777216)jsonError('Catalog CSV exceeds 16 MB',422);
            $csv=file_get_contents($csvPath);
            if($csv===false)jsonError('Could not read CSV file',500);
            if(!hsCatalogHasColumn($pdo,'name_en')||!hsCatalogHasColumn($pdo,'name_zh'))jsonError('Catalog migration 080 is required',503);
            jsonResponse(['data'=>HsCatalogImportService::run($pdo,$csv,basename($csvPath),$input,(int)$userId)]);

        default:
            jsonError('Method not allowed', 405);
    }
};
