<?php

/**
 * File upload - returns path for use in receipts/attachments
 * Always returns JSON; errors use { "error": { "message": "...", "code": "...", "max_upload_mb"?, "file_size_mb"?, "request_id"? } }
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    header('Content-Type: application/json; charset=utf-8');
    $requestId = bin2hex(random_bytes(8));

    if ($method !== 'POST') {
        jsonResponse(['error' => ['message' => 'Method not allowed', 'code' => 'UPLOAD_FAILED', 'request_id' => $requestId]], 405);
    }

    if (empty($_FILES['file'])) {
        jsonResponse(['error' => ['message' => 'No file uploaded', 'code' => 'UPLOAD_FAILED', 'request_id' => $requestId]], 400);
    }

    try {
        $file = $_FILES['file'];
        $config = require dirname(__DIR__, 2) . '/config/config.php';
        $maxSize = (int) ($config['upload_max_size'] ?? 8388608);
        $maxMb = (float) ($config['upload_max_mb'] ?? 8);
        $allowed = $config['upload_allowed_extensions'] ?? ['jpg', 'jpeg', 'png', 'webp'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errMsg = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds server limit (max ' . $maxMb . ' MB)',
                UPLOAD_ERR_FORM_SIZE => 'File too large',
                UPLOAD_ERR_PARTIAL => 'Upload incomplete',
                UPLOAD_ERR_NO_FILE => 'No file uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Server config error',
                UPLOAD_ERR_CANT_WRITE => 'Failed to save file',
                UPLOAD_ERR_EXTENSION => 'Upload blocked by extension',
            ][$file['error']] ?? 'Upload failed (code ' . $file['error'] . ')';
            jsonResponse(['error' => ['message' => $errMsg, 'code' => 'UPLOAD_FAILED', 'max_upload_mb' => $maxMb, 'request_id' => $requestId]], 400);
        }
        if ($file['size'] > $maxSize) {
            $fileMb = round($file['size'] / 1048576, 1);
            jsonResponse(['error' => [
                'message' => 'File too large (' . $fileMb . ' MB). Max allowed ' . $maxMb . ' MB. Try compressing or use a smaller image.',
                'code' => 'FILE_TOO_LARGE',
                'max_upload_mb' => $maxMb,
                'file_size_mb' => $fileMb,
                'request_id' => $requestId,
            ]], 400);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) {
            jsonResponse(['error' => ['message' => 'File type not allowed. Allowed: ' . implode(', ', $allowed), 'code' => 'UPLOAD_FAILED', 'allowed_types' => $allowed, 'request_id' => $requestId]], 400);
        }

        $uploadDir = dirname(__DIR__, 2) . '/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $path = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $path)) {
            jsonResponse(['error' => ['message' => 'Failed to save file', 'code' => 'UPLOAD_FAILED']], 500);
        }

        $relPath = 'uploads/' . $filename;
        $url = '/cargochina/backend/' . $relPath;
        jsonResponse(['data' => ['path' => $relPath, 'url' => $url]], 201);
    } catch (Throwable $e) {
        $logDir = dirname(__DIR__, 3) . '/logs';
        if (!is_dir($logDir)) mkdir($logDir, 0755, true);
        error_log(date('Y-m-d H:i:s') . ' Upload error: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", 3, $logDir . '/php_errors.log');
        jsonResponse(['error' => ['message' => 'Upload failed: ' . $e->getMessage(), 'code' => 'UPLOAD_FAILED']], 500);
    }
};
