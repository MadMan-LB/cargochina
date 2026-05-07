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
    $GLOBALS['clms_ui_locale_current'] = $normalized;
    setcookie('clms_ui_locale', $normalized, [
        'expires' => time() + (86400 * 365),
        'path' => '/cargochina',
        'samesite' => 'Lax',
    ]);
    return $normalized;
}

function clmsGetUiLocale(): string
{
    if (!empty($GLOBALS['clms_ui_locale_current'])) {
        return clmsNormalizeUiLocale($GLOBALS['clms_ui_locale_current']);
    }

    if (isset($_SESSION['clms_ui_locale'])) {
        $GLOBALS['clms_ui_locale_current'] = clmsNormalizeUiLocale($_SESSION['clms_ui_locale']);
        return $GLOBALS['clms_ui_locale_current'];
    }

    if (!empty($_COOKIE['clms_ui_locale'])) {
        return clmsSetUiLocale($_COOKIE['clms_ui_locale']);
    }

    return clmsSetUiLocale('en');
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
    return clmsResolveUiTranslation($text, $translations[$locale] ?? [], $locale);
}

function clmsNormalizeTranslationText(string $text): string
{
    $text = str_replace("\xc2\xa0", ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function clmsTranslationPatternsForLocale(string $locale, array $localeTranslations): array
{
    static $patterns = [];
    if (isset($patterns[$locale])) {
        return $patterns[$locale];
    }

    $compiled = [];
    foreach (($localeTranslations['strings'] ?? []) as $source => $translated) {
        $normalizedSource = clmsNormalizeTranslationText((string) $source);
        if (!preg_match_all('/\{([A-Za-z0-9_]+)\}/', $normalizedSource, $matches, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        $regex = '';
        $offset = 0;
        $placeholders = [];
        foreach ($matches[0] as $index => $match) {
            $token = $match[0];
            $position = $match[1];
            $regex .= preg_quote(substr($normalizedSource, $offset, $position - $offset), '/');
            $regex .= '(.+?)';
            $placeholders[] = $matches[1][$index][0];
            $offset = $position + strlen($token);
        }
        $regex .= preg_quote(substr($normalizedSource, $offset), '/');
        $compiled[] = [
            'regex' => '/^' . $regex . '$/u',
            'placeholders' => $placeholders,
            'translated' => (string) $translated,
        ];
    }

    $patterns[$locale] = $compiled;
    return $compiled;
}

function clmsResolveUiTranslation(string $text, array $localeTranslations, ?string $locale = null): string
{
    $normalized = clmsNormalizeTranslationText($text);
    $strings = $localeTranslations['strings'] ?? [];
    $statuses = $localeTranslations['statuses'] ?? [];

    foreach ([$text, $normalized] as $candidate) {
        if ($candidate !== '' && isset($strings[$candidate])) {
            return $strings[$candidate];
        }
        if ($candidate !== '' && isset($statuses[$candidate])) {
            return $statuses[$candidate];
        }
    }

    $locale = clmsNormalizeUiLocale($locale ?? clmsGetUiLocale());
    foreach (clmsTranslationPatternsForLocale($locale, $localeTranslations) as $pattern) {
        if (!preg_match($pattern['regex'], $normalized, $matches)) {
            continue;
        }
        $translated = $pattern['translated'];
        foreach ($pattern['placeholders'] as $index => $placeholder) {
            $translated = str_replace('{' . $placeholder . '}', $matches[$index + 1] ?? '', $translated);
        }
        return $translated;
    }

    return $text;
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

if (!defined('CLMS_I18N_DISABLE_AUTO_SWITCH') || CLMS_I18N_DISABLE_AUTO_SWITCH !== true) {
    clmsMaybeHandleUiLocaleSwitch();
}
