<?php

/**
 * Translations API - POST lookup (cache or mock translate)
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    if ($method !== 'POST') {
        jsonError('Method not allowed', 405);
    }

    $pdo = getDb();
    $text = trim($input['text'] ?? '');
    $sourceLang = $input['source_lang'] ?? 'zh';
    $targetLang = $input['target_lang'] ?? 'en';

    if (!$text) {
        jsonError('Missing required field: text', 400);
    }

    $hash = hash('sha256', $text . $sourceLang . $targetLang);
    $stmt = $pdo->prepare("SELECT translated_text FROM translations WHERE original_hash = ?");
    $stmt->execute([$hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        jsonResponse(['data' => ['translated' => $row['translated_text'], 'cached' => true]]);
    }

    // Mock translation: prefix with [EN] for placeholder until real API is configured
    $translated = '[EN] ' . $text;
    try {
        $stmt = $pdo->prepare("INSERT INTO translations (original_hash, original_text, translated_text, source_lang, target_lang) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$hash, $text, $translated, $sourceLang, $targetLang]);
    } catch (PDOException $e) {
        // Duplicate hash race - fetch again
        $stmt = $pdo->prepare("SELECT translated_text FROM translations WHERE original_hash = ?");
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            jsonResponse(['data' => ['translated' => $row['translated_text'], 'cached' => true]]);
        }
        throw $e;
    }

    jsonResponse(['data' => ['translated' => $translated, 'cached' => false]]);
};
