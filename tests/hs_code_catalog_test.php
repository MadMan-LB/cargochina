<?php

/**
 * HS Code Tariff Catalog test — schema, search, import flow
 * Requires migration 042 applied. Run: php tests/hs_code_catalog_test.php
 */

$root = dirname(__DIR__);
require_once $root . '/backend/config/database.php';

$pdo = getDb();

// 1. Table exists and has expected columns
$cols = $pdo->query("SHOW COLUMNS FROM hs_code_tariff_catalog")->fetchAll(PDO::FETCH_COLUMN);
$expected = ['id', 'hs_code', 'name', 'category', 'tariff_rate', 'vat', 'parent_directory_code', 'parent_directory_name', 'section_code', 'section_name', 'source_file', 'imported_at'];
foreach ($expected as $c) {
    if (!in_array($c, $cols, true)) {
        echo "FAIL: hs_code_tariff_catalog missing column: $c\n";
        exit(1);
    }
}
echo "OK: hs_code_tariff_catalog schema\n";

// 2. Search supports prefix-first matching on normalized HS code
$stmt = $pdo->prepare("
    SELECT id, hs_code, name
    FROM hs_code_tariff_catalog
    WHERE REPLACE(REPLACE(REPLACE(UPPER(hs_code), '.', ''), '-', ''), ' ', '') LIKE ?
    ORDER BY hs_code
    LIMIT 5
");
$stmt->execute(['0101%']);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "OK: catalog prefix search query (found " . count($rows) . " rows)\n";
if (!$rows) {
    echo "FAIL: expected seeded catalog matches for normalized prefix 0101\n";
    exit(1);
}

// 3. Safe catalog lookup is available to every operational role.
$rbac = require $root . '/backend/config/rbac.php';
$expectedReadRoles = ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'ContainersStaff', 'FieldStaff', 'SuperAdmin'];
$catalogReadRoles = $rbac['hs-code-catalog']['read'] ?? [];
foreach ($expectedReadRoles as $role) {
    if (!in_array($role, $catalogReadRoles, true)) {
        echo "FAIL: hs-code-catalog lookup missing operational role: $role\n";
        exit(1);
    }
}
echo "OK: catalog lookup RBAC includes every operational role\n";

// 4. Import flow: verify handler path resolution (hs codes folder)
$baseDir = $root . '/hs codes';
if (!is_dir($baseDir)) {
    echo "SKIP: hs codes folder not found (create for import test)\n";
} else {
    $csvFiles = glob($baseDir . '/*.csv');
    if (empty($csvFiles)) {
        echo "SKIP: no CSV files in hs codes folder\n";
    } else {
        echo "OK: hs codes folder has " . count($csvFiles) . " CSV file(s)\n";
    }
}

echo "HS Code Catalog test passed.\n";
