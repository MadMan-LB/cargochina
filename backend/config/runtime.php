<?php

/**
 * Production runtime hardening shared by web pages, API, and CLI tools.
 */

if (!function_exists('clmsLoadEnvFile')) {
    function clmsLoadEnvFile(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '') {
                continue;
            }
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
                putenv($key . '=' . $value);
            }
        }
    }
}

if (!function_exists('clmsEnvFlag')) {
    function clmsEnvFlag(string $key, bool $default = false): bool
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('clmsIsDebugEnabled')) {
    function clmsIsDebugEnabled(): bool
    {
        return clmsEnvFlag('APP_DEBUG', false);
    }
}

if (!function_exists('clmsConfigureRuntime')) {
    function clmsConfigureRuntime(): void
    {
        static $configured = false;
        if ($configured) {
            return;
        }

        $rootDir = dirname(__DIR__, 2);
        clmsLoadEnvFile($rootDir . '/.env');

        $debug = clmsIsDebugEnabled();
        error_reporting(E_ALL);
        @ini_set('display_errors', $debug ? '1' : '0');
        @ini_set('display_startup_errors', $debug ? '1' : '0');
        @ini_set('log_errors', '1');
        @ini_set('html_errors', '0');

        $logDir = $rootDir . '/logs';
        if (is_dir($logDir) || @mkdir($logDir, 0755, true)) {
            @ini_set('error_log', $logDir . '/php_errors.log');
        }

        $configured = true;
    }
}

clmsConfigureRuntime();
