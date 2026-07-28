<?php

require_once dirname(__DIR__) . '/backend/services/TranslationService.php';

$reflection = new ReflectionClass(TranslationService::class);
$service = $reflection->newInstanceWithoutConstructor();
$extract = $reflection->getMethod('extractTranslatedText');
$extractBatch = $reflection->getMethod('extractTranslatedTexts');
$providerCode = $reflection->getMethod('providerLanguageCode');
$provider = $reflection->getProperty('provider');
$provider->setValue($service, 'google');

$tests = [
    'Google Cloud v2 response' => [
        ['data' => ['translations' => [['translatedText' => '工业泵 &amp; 阀门']]]],
        '工业泵 & 阀门',
    ],
    'LibreTranslate response' => [
        ['translatedText' => 'Industrial pump'],
        'Industrial pump',
    ],
    'Generic nested response' => [
        ['data' => ['translated' => 'Technical model ZX-9000']],
        'Technical model ZX-9000',
    ],
];

$failed = 0;
foreach ($tests as $name => [$payload, $expected]) {
    $actual = $extract->invoke($service, $payload);
    if ($actual !== $expected) {
        echo "FAIL: $name - expected '$expected', got '$actual'\n";
        $failed++;
    } else {
        echo "PASS: $name\n";
    }
}

$zhCode = $providerCode->invoke($service, 'zh');
if ($zhCode !== 'zh-CN') {
    echo "FAIL: Google Simplified Chinese code - expected 'zh-CN', got '$zhCode'\n";
    $failed++;
} else {
    echo "PASS: Google Simplified Chinese code\n";
}

$batch = $extractBatch->invoke($service, [
    'data' => [
        'translations' => [
            ['translatedText' => 'Industrial valve'],
            ['translatedText' => '工业泵 &amp; 阀门'],
        ],
    ],
]);
if ($batch !== ['Industrial valve', '工业泵 & 阀门']) {
    echo "FAIL: Google batch response ordering/entity decoding\n";
    $failed++;
} else {
    echo "PASS: Google batch response ordering/entity decoding\n";
}

$detectLanguage = $reflection->getMethod('detectLanguage');
if ($detectLanguage->invoke($service, 'أصيلة للإنسال') !== 'ar') {
    echo "FAIL: Arabic tariff-name detection\n";
    $failed++;
} else {
    echo "PASS: Arabic tariff-name detection\n";
}

exit($failed === 0 ? 0 : 1);
