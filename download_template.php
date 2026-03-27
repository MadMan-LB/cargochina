<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/downloads_registry.php';

$slug = trim((string) ($_GET['slug'] ?? ''));
$userRoles = $_SESSION['user_roles'] ?? [];
$entry = $slug !== '' ? clmsFindDownloadEntry($slug, $userRoles) : null;

if (!$entry) {
    http_response_code(404);
    echo 'Download not found.';
    exit;
}

$mode = (string) ($entry['mode'] ?? 'file');
if ($mode === 'generated') {
    try {
        require_once __DIR__ . '/backend/services/DownloadExampleService.php';
        (new DownloadExampleService())->outputBySlug($slug);
    } catch (Throwable $e) {
        http_response_code(404);
        echo 'Download not available.';
    }
    exit;
}

if ($mode !== 'file') {
    http_response_code(404);
    echo 'Download not found.';
    exit;
}

$projectRoot = realpath(__DIR__);
$path = $entry['path'] ?? '';
$realPath = is_string($path) ? realpath($path) : false;

if (
    !$projectRoot ||
    !$realPath ||
    !is_file($realPath) ||
    !is_readable($realPath) ||
    strpos($realPath, $projectRoot . DIRECTORY_SEPARATOR) !== 0
) {
    http_response_code(404);
    echo 'Download not available.';
    exit;
}

$extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
$mimeMap = [
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'xls' => 'application/vnd.ms-excel',
    'csv' => 'text/csv; charset=utf-8',
];
$mimeType = $mimeMap[$extension] ?? 'application/octet-stream';
$downloadName = trim((string) ($entry['download_name'] ?? basename($realPath)));
$downloadName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $downloadName) ?: basename($realPath);

header('Content-Description: File Transfer');
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . (string) filesize($realPath));
header('Cache-Control: private, max-age=600');
header('Pragma: public');
header('X-Content-Type-Options: nosniff');

readfile($realPath);
exit;
