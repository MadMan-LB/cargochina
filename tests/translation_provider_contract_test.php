<?php

require_once dirname(__DIR__) . '/backend/services/TranslationService.php';

$reflection = new ReflectionClass(TranslationService::class);
$service = $reflection->newInstanceWithoutConstructor();
$extract = $reflection->getMethod('extractTranslatedText');
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

exit($failed === 0 ? 0 : 1);
