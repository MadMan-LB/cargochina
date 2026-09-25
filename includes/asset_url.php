<?php
/** Public frontend files only. A content change changes the URL even if deployment preserves mtime. */
function clmsAssetUrl(string $url): string
{
    $parts = parse_url($url);
    if ($parts === false || isset($parts['host']) || isset($parts['scheme'])) return $url;
    $path = $parts['path'] ?? '';
    if (strpos($path, '/cargochina/frontend/') !== 0) return $url;
    $root = realpath(dirname(__DIR__) . '/frontend');
    $file = realpath(dirname(__DIR__) . substr($path, strlen('/cargochina')));
    if (!$root || !$file || strpos(str_replace('\\', '/', $file), str_replace('\\', '/', $root) . '/') !== 0 || !is_file($file)) return $url;
    static $versions = []; // Request-local; deployment requires no cache flush.
    if (!isset($versions[$file])) {
        $hash = @hash_file('sha256', $file);
        if (!is_string($hash)) return $url;
        $versions[$file] = substr($hash, 0, 16);
    }
    parse_str($parts['query'] ?? '', $query);
    $query['v'] = $versions[$file];
    return $path . '?' . http_build_query($query) . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
}
