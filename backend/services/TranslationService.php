<?php

/**
 * Cached, configurable and non-blocking translation service.
 *
 * Providers:
 * - disabled (default): queue work and return an empty missing-language value
 * - google: Google Cloud Translation Basic v2 using TRANSLATION_API_KEY
 * - libretranslate/generic: JSON POST to TRANSLATION_API_URL
 *
 * Critical business transactions must use translateDetailed() and may persist the
 * original value even when status is pending/failed. No API key is logged.
 */
class TranslationService
{
    private PDO $pdo;
    private string $provider;
    private string $apiUrl;
    private string $apiKey;
    private int $timeoutSeconds;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $provider = strtolower(trim((string) ($_ENV['TRANSLATION_PROVIDER'] ?? getenv('TRANSLATION_PROVIDER') ?: 'disabled')));
        $this->provider = in_array($provider, ['google-cloud', 'google_cloud'], true) ? 'google' : $provider;
        $this->apiUrl = trim((string) ($_ENV['TRANSLATION_API_URL'] ?? getenv('TRANSLATION_API_URL') ?: ''));
        $this->apiKey = trim((string) ($_ENV['TRANSLATION_API_KEY'] ?? getenv('TRANSLATION_API_KEY') ?: ''));
        $this->timeoutSeconds = max(1, min(30, (int) ($_ENV['TRANSLATION_TIMEOUT_SECONDS'] ?? getenv('TRANSLATION_TIMEOUT_SECONDS') ?: 8)));
        if ($this->provider === 'google' && $this->apiUrl === '') {
            $this->apiUrl = 'https://translation.googleapis.com/language/translate/v2';
        }
    }

    public function detectLanguage(string $text): string
    {
        $hasHan = preg_match('/[\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}]/u', $text) === 1;
        $hasLatin = preg_match('/[A-Za-z]/u', $text) === 1;
        if ($hasHan && !$hasLatin) return 'zh';
        if ($hasLatin && !$hasHan) return 'en';
        if ($hasHan) return 'zh';
        return 'en';
    }

    public function translate(string $text, string $sourceLang = 'auto', string $targetLang = 'en'): string
    {
        $result = $this->translateDetailed($text, $sourceLang, $targetLang);
        return (string) ($result['translated_text'] ?? '');
    }

    public function translateDetailed(string $text, string $sourceLang = 'auto', string $targetLang = 'en'): array
    {
        // Preserve the submitted source exactly in returned provenance. Only an
        // empty/whitespace-only input is normalized to empty.
        $original = $text;
        if (trim($original) === '') {
            return ['translated_text' => '', 'status' => 'empty', 'source_lang' => 'auto', 'target_lang' => $this->normalizeLang($targetLang, 'en'), 'provenance' => 'original'];
        }

        $sourceLang = $this->normalizeLang($sourceLang, 'auto');
        if ($sourceLang === 'auto') $sourceLang = $this->detectLanguage($original);
        $targetLang = $this->normalizeLang($targetLang, 'en');
        if ($sourceLang === $targetLang) {
            return ['translated_text' => $original, 'status' => 'original', 'source_lang' => $sourceLang, 'target_lang' => $targetLang, 'provenance' => 'original'];
        }

        $hash = $this->sourceHash($original);
        $manual = $this->manualCorrection($hash, $sourceLang, $targetLang);
        if ($manual !== null) {
            return ['translated_text' => $manual, 'status' => 'manual', 'source_lang' => $sourceLang, 'target_lang' => $targetLang, 'provenance' => 'manual'];
        }

        $cached = $this->cachedTranslation($hash, $original, $sourceLang, $targetLang);
        if ($cached !== null) {
            return ['translated_text' => $cached, 'status' => 'translated', 'source_lang' => $sourceLang, 'target_lang' => $targetLang, 'provenance' => 'cache'];
        }

        $configurationError = $this->configurationError();
        if ($configurationError !== null) {
            $this->queue($hash, $original, $sourceLang, $targetLang, 'pending', $configurationError);
            return [
                'translated_text' => '',
                'status' => 'pending',
                'source_lang' => $sourceLang,
                'target_lang' => $targetLang,
                'provenance' => 'pending',
                'error_code' => 'provider_not_configured',
                'retryable' => false,
            ];
        }

        try {
            $translated = $this->requestProvider($original, $sourceLang, $targetLang);
            if (trim($translated) === '') {
                throw new RuntimeException('Translation provider returned an empty value');
            }
            $this->storeSuccess($hash, $original, $translated, $sourceLang, $targetLang);
            return [
                'translated_text' => $translated,
                'status' => 'translated',
                'source_lang' => $sourceLang,
                'target_lang' => $targetLang,
                'provenance' => 'automatic',
                'retryable' => false,
            ];
        } catch (Throwable $e) {
            $safeError = mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 500);
            $this->queue($hash, $original, $sourceLang, $targetLang, 'failed', $safeError);
            $this->safeLog('translation_failed provider=' . $this->provider . ' error=' . $safeError);
            return [
                'translated_text' => '',
                'status' => 'failed',
                'source_lang' => $sourceLang,
                'target_lang' => $targetLang,
                'provenance' => 'failed',
                'error_code' => 'provider_failed',
                'retryable' => true,
            ];
        }
    }

    public function saveManualCorrection(string $original, string $sourceLang, string $targetLang, string $translated, ?int $userId): void
    {
        if (trim($original) === '' || trim($translated) === '') throw new InvalidArgumentException('Original and translated text are required');
        $sourceLang = $this->normalizeLang($sourceLang, $this->detectLanguage($original));
        $targetLang = $this->normalizeLang($targetLang, $sourceLang === 'zh' ? 'en' : 'zh');
        $sql = "INSERT INTO translation_manual_corrections
                (source_hash, original_text, source_lang, target_lang, translated_text, corrected_by)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE translated_text=VALUES(translated_text), corrected_by=VALUES(corrected_by), corrected_at=CURRENT_TIMESTAMP";
        $this->pdo->prepare($sql)->execute([$this->sourceHash($original), $original, $sourceLang, $targetLang, $translated, $userId]);
    }

    private function requestProvider(string $text, string $sourceLang, string $targetLang): string
    {
        $url = $this->apiUrl;
        $payload = [
            'q' => $text,
            'source' => $this->providerLanguageCode($sourceLang),
            'target' => $this->providerLanguageCode($targetLang),
            'format' => 'text',
        ];
        if ($this->provider === 'google') {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'key=' . rawurlencode($this->apiKey);
        } elseif ($this->apiKey !== '') {
            $payload['api_key'] = $this->apiKey;
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) throw new RuntimeException('Could not encode translation request');

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_CONNECTTIMEOUT => min(5, $this->timeoutSeconds), CURLOPT_TIMEOUT => $this->timeoutSeconds]);
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($response === false || $error !== '') throw new RuntimeException('Translation network request failed');
            if ($status < 200 || $status >= 300) throw new RuntimeException('Translation provider HTTP ' . $status);
        } else {
            $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $body, 'timeout' => $this->timeoutSeconds, 'ignore_errors' => true]]);
            $response = @file_get_contents($url, false, $context);
            if ($response === false) throw new RuntimeException('Translation network request failed');
            $statusLine = $http_response_header[0] ?? '';
            if (preg_match('/\s(\d{3})\s/', $statusLine, $matches) && ((int) $matches[1] < 200 || (int) $matches[1] >= 300)) {
                throw new RuntimeException('Translation provider HTTP ' . (int) $matches[1]);
            }
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) throw new RuntimeException('Translation provider returned invalid JSON');
        return $this->extractTranslatedText($decoded);
    }

    private function extractTranslatedText(array $decoded): string
    {
        $translated = (string) (
            $decoded['data']['translations'][0]['translatedText']
            ?? $decoded['translatedText']
            ?? $decoded['translated']
            ?? $decoded['data']['translatedText']
            ?? $decoded['data']['translated']
            ?? ''
        );
        return html_entity_decode($translated, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function configurationError(): ?string
    {
        if ($this->provider === '' || $this->provider === 'disabled') {
            return 'Translation provider is disabled';
        }
        if (!in_array($this->provider, ['google', 'libretranslate', 'generic'], true)) {
            return 'Unsupported translation provider';
        }
        if ($this->apiUrl === '') {
            return 'Translation provider URL is not configured';
        }
        if (!preg_match('#^https?://#i', $this->apiUrl)) {
            return 'Translation provider URL is invalid';
        }
        if ($this->provider === 'google' && $this->apiKey === '') {
            return 'Google Translation API key is not configured';
        }
        return null;
    }

    private function providerLanguageCode(string $lang): string
    {
        if ($this->provider === 'google' && $lang === 'zh') {
            return 'zh-CN';
        }
        return $lang;
    }

    private function cachedTranslation(string $hash, string $original, string $sourceLang, string $targetLang): ?string
    {
        $stmt = $this->pdo->prepare('SELECT translated_text FROM translations WHERE original_hash = ? AND source_lang = ? AND target_lang = ? LIMIT 1');
        $stmt->execute([hash('sha256', $original . $sourceLang . $targetLang), $sourceLang, $targetLang]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            // New records use a source-only hash in the queue but preserve the
            // legacy translations key format for compatibility.
            $stmt = $this->pdo->prepare('SELECT translated_text FROM translations WHERE original_hash = ? AND source_lang = ? AND target_lang = ? LIMIT 1');
            $stmt->execute([$this->translationHash($hash, $sourceLang, $targetLang), $sourceLang, $targetLang]);
            $value = $stmt->fetchColumn();
        }
        if ($value === false || trim((string) $value) === '') return null;
        if (preg_match('/^\[(?:EN|ZH)\]\s/u', (string) $value)) return null;
        return (string) $value;
    }

    private function manualCorrection(string $hash, string $sourceLang, string $targetLang): ?string
    {
        if (!$this->tableExists('translation_manual_corrections')) return null;
        $stmt = $this->pdo->prepare('SELECT translated_text FROM translation_manual_corrections WHERE source_hash=? AND source_lang=? AND target_lang=? LIMIT 1');
        $stmt->execute([$hash, $sourceLang, $targetLang]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    private function storeSuccess(string $hash, string $original, string $translated, string $sourceLang, string $targetLang): void
    {
        $translationHash = $this->translationHash($hash, $sourceLang, $targetLang);
        $sql = 'INSERT INTO translations (original_hash, original_text, translated_text, source_lang, target_lang) VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE translated_text=VALUES(translated_text)';
        $this->pdo->prepare($sql)->execute([$translationHash, $original, $translated, $sourceLang, $targetLang]);
        if ($this->tableExists('translation_jobs')) {
            $this->pdo->prepare("UPDATE translation_jobs SET status='translated', translated_text=?, last_error=NULL, updated_at=CURRENT_TIMESTAMP WHERE source_hash=? AND source_lang=? AND target_lang=?")
                ->execute([$translated, $hash, $sourceLang, $targetLang]);
        }
    }

    private function queue(string $hash, string $original, string $sourceLang, string $targetLang, string $status, string $error): void
    {
        if (!$this->tableExists('translation_jobs')) return;
        $sql = "INSERT INTO translation_jobs (source_hash, original_text, source_lang, target_lang, provider, status, attempt_count, last_error, next_retry_at)
                VALUES (?, ?, ?, ?, ?, ?, 1, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))
                ON DUPLICATE KEY UPDATE provider=VALUES(provider), status=IF(status='translated', status, VALUES(status)), attempt_count=attempt_count+1, last_error=IF(status='translated', last_error, VALUES(last_error)), next_retry_at=IF(status='translated', next_retry_at, VALUES(next_retry_at))";
        $this->pdo->prepare($sql)->execute([$hash, $original, $sourceLang, $targetLang, $this->provider, $status, $error]);
    }

    private function tableExists(string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) return $cache[$table];
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
        try { $cache[$table] = (bool) $this->pdo->query("SHOW TABLES LIKE " . $this->pdo->quote($table))->fetchColumn(); }
        catch (Throwable $e) { $cache[$table] = false; }
        return $cache[$table];
    }

    private function sourceHash(string $text): string { return hash('sha256', $text); }
    private function translationHash(string $hash, string $sourceLang, string $targetLang): string { return hash('sha256', $hash . '|' . $sourceLang . '|' . $targetLang); }
    private function normalizeLang(string $lang, string $fallback): string
    {
        $lang = strtolower(trim($lang));
        if ($lang === 'zh-cn' || $lang === 'zh-hans' || $lang === 'cn') return 'zh';
        if ($lang === 'en-us' || $lang === 'en-gb') return 'en';
        if ($lang === 'auto') return 'auto';
        return preg_match('/^[a-z]{2}$/', $lang) ? $lang : $fallback;
    }
    private function safeLog(string $message): void
    {
        $dir = dirname(__DIR__, 2) . '/logs';
        if (is_dir($dir)) @error_log(date('c') . ' ' . $message . PHP_EOL, 3, $dir . '/translation_errors.log');
    }
}
