<?php

/**
 * Translation Service - cache + stub translate
 */
class TranslationService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function translate(string $text, string $sourceLang = 'zh', string $targetLang = 'en'): string
    {
        $text = trim($text);
        if ($text === '') return '';

        $sourceLang = $this->normalizeLang($sourceLang, 'zh');
        $targetLang = $this->normalizeLang($targetLang, 'en');
        if ($sourceLang === $targetLang) {
            return $text;
        }

        $hash = hash('sha256', $text . $sourceLang . $targetLang);
        $stmt = $this->pdo->prepare("SELECT translated_text FROM translations WHERE original_hash = ?");
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row['translated_text'];
        }

        $translated = $this->stubTranslate($text, $sourceLang, $targetLang);
        try {
            $this->pdo->prepare("INSERT INTO translations (original_hash, original_text, translated_text, source_lang, target_lang) VALUES (?, ?, ?, ?, ?)")
                ->execute([$hash, $text, $translated, $sourceLang, $targetLang]);
        } catch (PDOException $e) {
            $stmt->execute([$hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row['translated_text'];
            throw $e;
        }
        return $translated;
    }

    private function normalizeLang(string $lang, string $fallback): string
    {
        $lang = strtolower(trim($lang));
        if ($lang === '') {
            return $fallback;
        }

        return substr($lang, 0, 2) ?: $fallback;
    }

    private function stubTranslate(string $text, string $sourceLang, string $targetLang): string
    {
        if ($sourceLang === $targetLang) {
            return $text;
        }

        $tag = strtoupper($targetLang ?: 'EN');
        return '[' . $tag . '] ' . $text;
    }
}
