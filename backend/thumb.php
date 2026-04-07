<?php

require_once __DIR__ . '/api/helpers.php';

$path = $_GET['path'] ?? '';
$width = max(24, min(800, (int) ($_GET['w'] ?? 160)));
$height = max(24, min(800, (int) ($_GET['h'] ?? 160)));
$fit = strtolower(trim((string) ($_GET['fit'] ?? 'contain')));
if (!in_array($fit, ['contain', 'cover'], true)) {
    $fit = 'contain';
}

try {
    $meta = clmsResolveStoredUploadPathMeta((string) $path, true);
    $sourcePath = $meta['resolved_path'] ?: $meta['full_path'];
    if (!is_file($sourcePath)) {
        throw new InvalidArgumentException('Uploaded file not found');
    }

    $imageInfo = @getimagesize($sourcePath);
    if (!$imageInfo || empty($imageInfo[2])) {
        http_response_code(415);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Thumbnail preview is only available for image files.';
        exit;
    }

    $sourceMime = (string) ($imageInfo['mime'] ?? 'application/octet-stream');

    $cacheDir = __DIR__ . '/cache/thumbs';
    if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0755, true)) {
        throw new RuntimeException('Thumbnail cache directory is not available');
    }

    $mtime = @filemtime($sourcePath) ?: time();
    $cacheKey = sha1($meta['normalized'] . '|' . $width . '|' . $height . '|' . $fit . '|' . $mtime);
    $cachePath = $cacheDir . '/' . $cacheKey . '.jpg';

    if (!is_file($cachePath)) {
        $src = clmsCreateImageResourceFromPath($sourcePath, $imageInfo);
        if (!$src) {
            header('Content-Type: ' . $sourceMime);
            header('Cache-Control: public, max-age=2592000, immutable');
            header('Content-Length: ' . filesize($sourcePath));
            readfile($sourcePath);
            exit;
        }

        $srcWidth = imagesx($src);
        $srcHeight = imagesy($src);

        if ($fit === 'cover') {
            $scale = max($width / max(1, $srcWidth), $height / max(1, $srcHeight));
            $drawWidth = (int) max(1, round($srcWidth * $scale));
            $drawHeight = (int) max(1, round($srcHeight * $scale));
            $dstX = (int) floor(($width - $drawWidth) / 2);
            $dstY = (int) floor(($height - $drawHeight) / 2);
        } else {
            $scale = min($width / max(1, $srcWidth), $height / max(1, $srcHeight), 1);
            $drawWidth = (int) max(1, round($srcWidth * $scale));
            $drawHeight = (int) max(1, round($srcHeight * $scale));
            $dstX = (int) floor(($width - $drawWidth) / 2);
            $dstY = (int) floor(($height - $drawHeight) / 2);
        }

        $dst = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $background);
        imagecopyresampled(
            $dst,
            $src,
            $dstX,
            $dstY,
            0,
            0,
            $drawWidth,
            $drawHeight,
            $srcWidth,
            $srcHeight
        );
        imagejpeg($dst, $cachePath, 82);
        imagedestroy($src);
        imagedestroy($dst);
    }

    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=2592000, immutable');
    header('Content-Length: ' . filesize($cachePath));
    readfile($cachePath);
    exit;
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Thumbnail generation failed.';
    exit;
}
