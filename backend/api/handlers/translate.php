<?php

/**
 * Translate API - POST /translate
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 2) . '/backend/services/TranslationService.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    if ($method !== 'POST') {
        jsonError('Method not allowed', 405);
    }

    $text = trim($input['text'] ?? '');
    $sourceLang = $input['source_lang'] ?? 'zh';
    $targetLang = $input['target_lang'] ?? 'en';

    if ($text === '') {
        jsonError('Missing required field: text', 400);
    }

    $pdo = getDb();
    $svc = new TranslationService($pdo);
    $translated = $svc->translate($text, $sourceLang, $targetLang);

    jsonResponse(['data' => ['translated' => $translated]]);
};
