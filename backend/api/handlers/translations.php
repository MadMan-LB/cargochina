<?php

/**
 * Translations API - POST lookup (cache or mock translate)
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 2) . '/services/TranslationService.php';

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

    $svc = new TranslationService($pdo);
    $translated = $svc->translate($text, $sourceLang, $targetLang);

    jsonResponse(['data' => ['translated' => $translated]]);
};
