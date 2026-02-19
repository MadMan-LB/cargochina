<?php

/**
 * Search API tests - customers, suppliers, products
 * Run: php tests/search_test.php
 * Requires DB with test data; run migrations first.
 */

$root = dirname(__DIR__);
require_once $root . '/backend/config/database.php';

$passed = 0;
$failed = 0;

function runSearchHandler(string $root, string $resource, string $q): string
{
    $rootEsc = addslashes(str_replace('\\', '/', $root));
    $qEsc = addslashes($q);
    $code = "<?php\n\$_GET = ['q' => '$qEsc'];\nrequire '$rootEsc/backend/config/database.php';\nrequire '$rootEsc/backend/api/helpers.php';\n\$pdo = getDb();\n\$h = require '$rootEsc/backend/api/handlers/$resource.php';\n\$h('GET', 'search', null, []);\n";
    $tmp = tempnam(sys_get_temp_dir(), 'search_test_');
    file_put_contents($tmp . '.php', $code);
    $php = (defined('PHP_BINARY') && file_exists(PHP_BINARY)) ? PHP_BINARY : 'php';
    $out = shell_exec(escapeshellcmd($php) . ' ' . escapeshellarg($tmp . '.php') . ' 2>&1');
    @unlink($tmp . '.php');
    @unlink($tmp);
    return $out ?? '';
}

function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "PASS: $name\n";
        $passed++;
    } catch (Throwable $e) {
        echo "FAIL: $name - " . $e->getMessage() . "\n";
        $failed++;
    }
}

$root = dirname(__DIR__);

test('Customers search returns array', function () use ($root) {
    $out = runSearchHandler($root, 'customers', 'a');
    $j = json_decode($out, true);
    if (!$j || !isset($j['data']) || !is_array($j['data'])) {
        throw new Exception('Expected {data: []}, got: ' . substr($out, 0, 200));
    }
});

test('Suppliers search returns array', function () use ($root) {
    $out = runSearchHandler($root, 'suppliers', 'a');
    $j = json_decode($out, true);
    if (!$j || !isset($j['data']) || !is_array($j['data'])) {
        throw new Exception('Expected {data: []}, got: ' . substr($out, 0, 200));
    }
});

test('Products search returns array', function () use ($root) {
    $out = runSearchHandler($root, 'products', 'a');
    $j = json_decode($out, true);
    if (!$j || !isset($j['data']) || !is_array($j['data'])) {
        throw new Exception('Expected {data: []}, got: ' . substr($out, 0, 200));
    }
});

test('Search empty q returns empty array', function () use ($root) {
    $out = runSearchHandler($root, 'customers', '');
    $j = json_decode($out, true);
    if (!$j || !isset($j['data']) || $j['data'] !== []) {
        throw new Exception('Expected {data: []}, got: ' . substr($out, 0, 200));
    }
});

echo "\nTotal: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
