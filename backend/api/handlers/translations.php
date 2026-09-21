<?php

/**
 * Translations API - cached lookup/queue and authorized manual correction.
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 2) . '/services/TranslationService.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    require_once __DIR__ . '/../authorization.php';
    clmsAuthorizeApiRequest('translations', $method, $id, $action);
    if ($method !== 'POST') {
        jsonError('Method not allowed', 405);
    }

    $pdo = getDb();
    $text = (string) ($input['text'] ?? '');
    $sourceLang = $input['source_lang'] ?? 'auto';
    $targetLang = $input['target_lang'] ?? 'en';

    if (!$text) {
        jsonError('Missing required field: text', 400);
    }

    $svc = new TranslationService($pdo);
    if ($id === 'manual') {
        requirePermission('translations.correct', ['ChinaAdmin', 'SuperAdmin']);
        $translated = (string) ($input['translated'] ?? $input['translated_text'] ?? '');
        if (trim($translated) === '') jsonError('Missing required field: translated', 400);
        $svc->saveManualCorrection($text, $sourceLang, $targetLang, $translated, getAuthUserId());
        jsonResponse(['data' => ['translated' => $translated, 'status' => 'manual', 'provenance' => 'manual']]);
    }

    $result = $svc->translateDetailed($text, $sourceLang, $targetLang);
    jsonResponse(['data' => array_merge(['translated' => $result['translated_text']], $result)]);
};
