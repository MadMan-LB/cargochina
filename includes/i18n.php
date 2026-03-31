<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function clmsSupportedUiLocales(): array
{
    return ['en', 'zh-CN'];
}

function clmsNormalizeUiLocale(?string $locale): string
{
    $value = strtolower(trim((string) $locale));
    return match ($value) {
        'zh', 'zh-cn', 'zh_cn', 'cn', 'zh-hans' => 'zh-CN',
        default => 'en',
    };
}

function clmsUiTranslations(): array
{
    static $translations = null;
    if ($translations === null) {
        $translations = require __DIR__ . '/ui_translations.php';
    }
    return $translations;
}

function clmsSetUiLocale(?string $locale): string
{
    $normalized = clmsNormalizeUiLocale($locale);
    $_SESSION['clms_ui_locale'] = $normalized;
    setcookie('clms_ui_locale', $normalized, [
        'expires' => time() + (86400 * 365),
        'path' => '/cargochina',
        'samesite' => 'Lax',
    ]);
    return $normalized;
}

function clmsGetUiLocale(): string
{
    static $locale = null;
    if ($locale !== null) {
        return $locale;
    }

    if (isset($_SESSION['clms_ui_locale'])) {
        $locale = clmsNormalizeUiLocale($_SESSION['clms_ui_locale']);
        return $locale;
    }

    if (!empty($_COOKIE['clms_ui_locale'])) {
        $locale = clmsSetUiLocale($_COOKIE['clms_ui_locale']);
        return $locale;
    }

    $locale = clmsSetUiLocale('en');
    return $locale;
}

function clmsGetUiQueryParam(): ?string
{
    foreach (['ui_lang', 'lang'] as $key) {
        if (!isset($_GET[$key])) {
            continue;
        }
        $value = trim((string) $_GET[$key]);
        if ($value !== '') {
            return $value;
        }
    }
    return null;
}

function clmsCurrentRequestPath(): string
{
    return $_SERVER['REQUEST_URI'] ?? '/cargochina/';
}

function clmsCurrentUrlWithUiLocale(string $locale): string
{
    $uri = clmsCurrentRequestPath();
    $parts = parse_url($uri);
    $path = $parts['path'] ?? '/cargochina/';
    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }
    $query['ui_lang'] = clmsNormalizeUiLocale($locale);
    $queryString = http_build_query($query);
    return $path . ($queryString !== '' ? ('?' . $queryString) : '');
}

function clmsMaybeHandleUiLocaleSwitch(): void
{
    static $handled = false;
    if ($handled) {
        return;
    }
    $handled = true;

    $requested = clmsGetUiQueryParam();
    if ($requested === null) {
        clmsGetUiLocale();
        return;
    }

    clmsSetUiLocale($requested);

    $uri = clmsCurrentRequestPath();
    $parts = parse_url($uri);
    $path = $parts['path'] ?? '/cargochina/';
    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }
    unset($query['ui_lang'], $query['lang']);
    $queryString = http_build_query($query);
    $target = $path . ($queryString !== '' ? ('?' . $queryString) : '');

    header('Location: ' . $target);
    exit;
}

function clmsTranslateText(string $text, ?string $locale = null): string
{
    $locale = clmsNormalizeUiLocale($locale ?? clmsGetUiLocale());
    if ($locale === 'en') {
        return $text;
    }

    $translations = clmsUiTranslations();
    return $translations[$locale]['strings'][$text] ?? $text;
}

function clmsT(string $text, array $params = [], ?string $locale = null): string
{
    $translated = clmsTranslateText($text, $locale);
    if (!$params) {
        return $translated;
    }

    $replacements = [];
    foreach ($params as $key => $value) {
        $replacements['{' . $key . '}'] = (string) $value;
    }
    return strtr($translated, $replacements);
}

function clmsStatusLabel(string $status, ?string $locale = null): string
{
    $locale = clmsNormalizeUiLocale($locale ?? clmsGetUiLocale());
    $translations = clmsUiTranslations();
    if ($locale !== 'en' && isset($translations[$locale]['statuses'][$status])) {
        return $translations[$locale]['statuses'][$status];
    }
    return $status;
}

function clmsGetClientTranslationPayload(): array
{
    $locale = clmsGetUiLocale();
    $translations = clmsUiTranslations();
    return [
        'locale' => $locale,
        'strings' => $translations[$locale]['strings'] ?? [],
        'statuses' => $translations[$locale]['statuses'] ?? [],
    ];
}

clmsMaybeHandleUiLocaleSwitch();
