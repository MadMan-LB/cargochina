<?php

/**
 * Upload API test - valid image, invalid extension, no file
 * Run: php tests/upload_test.php
 * Uses subprocess to capture handler output (handler calls exit).
 */

$root = dirname(__DIR__);
$passed = 0;
$failed = 0;

function runUploadInSubprocess(string $root, array $files): string
{
    $rootEsc = addslashes(str_replace('\\', '/', $root));
    $code = "<?php\n\$_FILES = " . var_export($files, true) . ";\n\$_SERVER['REQUEST_METHOD'] = 'POST';\nrequire '$rootEsc/backend/api/helpers.php';\n\$h = require '$rootEsc/backend/api/handlers/upload.php';\n\$h('POST', null, null, []);\n";
    $tmp = tempnam(sys_get_temp_dir(), 'upload_test_');
    file_put_contents($tmp . '.php', $code);
    $out = shell_exec('php ' . escapeshellarg($tmp . '.php') . ' 2>&1');
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

test('Upload no file returns JSON error', function () use ($root) {
    $out = runUploadInSubprocess($root, []);
    $j = json_decode($out, true);
    if (!$j || !isset($j['error']) || !isset($j['error']['message'])) {
        throw new Exception('Expected error object, got: ' . substr($out, 0, 300));
    }
});

test('Upload invalid extension returns JSON error', function () use ($root) {
    $tmp = sys_get_temp_dir() . '/clms_test_' . uniqid() . '.exe';
    file_put_contents($tmp, 'x');
    $out = runUploadInSubprocess($root, ['file' => ['name' => 'bad.exe', 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 1]]);
    @unlink($tmp);
    $j = json_decode($out, true);
    if (!$j || !isset($j['error']) || !isset($j['error']['message'])) {
        throw new Exception('Expected error object, got: ' . substr($out, 0, 300));
    }
});

test('Upload valid image returns JSON (data or error, never HTML)', function () use ($root) {
    $tmp = sys_get_temp_dir() . '/clms_test_' . uniqid() . '.jpg';
    file_put_contents($tmp, base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));
    $out = runUploadInSubprocess($root, ['file' => ['name' => 'test.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 100]]);
    @unlink($tmp);
    $j = json_decode($out, true);
    if (!$j) throw new Exception('Response must be JSON, got: ' . substr($out, 0, 150));
    if (isset($j['data']['path']) && isset($j['data']['url'])) return;
    if (isset($j['error']['message'])) return;
    throw new Exception('Expected data or error, got: ' . substr($out, 0, 200));
});

echo "\nTotal: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
