<?php

/**
 * File upload - returns path for use in receipts/attachments
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    if ($method !== 'POST') {
        jsonError('Method not allowed', 405);
    }

    if (empty($_FILES['file'])) {
        jsonError('No file uploaded', 400);
    }

    $file = $_FILES['file'];
    $config = require dirname(__DIR__, 2) . '/backend/config/config.php';
    $maxSize = $config['upload_max_size'] ?? 5242880;
    $allowed = $config['upload_allowed_extensions'] ?? ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        jsonError('Upload failed: ' . $file['error'], 400);
    }
    if ($file['size'] > $maxSize) {
        jsonError('File too large', 400);
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) {
        jsonError('File type not allowed', 400);
    }

    $uploadDir = dirname(__DIR__, 2) . '/backend/uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $path = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $path)) {
        jsonError('Failed to save file', 500);
    }

    jsonResponse(['data' => ['path' => 'uploads/' . $filename]], 201);
};
