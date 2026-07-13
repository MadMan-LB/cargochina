<?php

/**
 * Safe bilingual backfill for existing product/order-item descriptions.
 *
 * Dry run (default): php scripts/backfill_bilingual.php --batch=100 --after-id=0
 * Apply:             php scripts/backfill_bilingual.php --apply --table=products
 *
 * Repeat with the reported next_after_id to resume. Existing non-empty language
 * values and manual corrections are never overwritten.
 */

$root = dirname(__DIR__);
require_once $root . '/backend/config/database.php';
require_once $root . '/backend/services/TranslationService.php';

$options = getopt('', ['apply', 'dry-run', 'table::', 'batch::', 'after-id::']);
$apply = array_key_exists('apply', $options) && !array_key_exists('dry-run', $options);
$tableOption = (string) ($options['table'] ?? 'all');
$batch = max(1, min(1000, (int) ($options['batch'] ?? 100)));
$afterId = max(0, (int) ($options['after-id'] ?? 0));
$allowed = ['products', 'order_items'];
$tables = $tableOption === 'all' ? $allowed : [$tableOption];
foreach ($tables as $table) {
    if (!in_array($table, $allowed, true)) {
        fwrite(STDERR, "Invalid --table. Use products, order_items, or all.\n");
        exit(2);
    }
}

$pdo = getDb();
$service = new TranslationService($pdo);
$summary = ['mode' => $apply ? 'apply' : 'dry-run', 'updated' => 0, 'queued_or_failed' => 0, 'skipped' => 0, 'examined' => 0, 'next_after_id' => $afterId, 'tables' => []];

foreach ($tables as $table) {
    $stmt = $pdo->prepare("SELECT id, description_cn, description_en FROM `$table` WHERE id > ? ORDER BY id ASC LIMIT $batch");
    $stmt->execute([$afterId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $tableSummary = ['examined' => 0, 'updated' => 0, 'queued_or_failed' => 0, 'skipped' => 0, 'next_after_id' => $afterId];

    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $tableSummary['examined']++;
        $summary['examined']++;
        $tableSummary['next_after_id'] = $id;
        $summary['next_after_id'] = max($summary['next_after_id'], $id);
        $cn = trim((string) ($row['description_cn'] ?? ''));
        $en = trim((string) ($row['description_en'] ?? ''));
        if (($cn === '' && $en === '') || ($cn !== '' && $en !== '')) {
            $tableSummary['skipped']++;
            $summary['skipped']++;
            continue;
        }
        if (!$apply) continue;

        $source = $cn !== '' ? (string) $row['description_cn'] : (string) $row['description_en'];
        $sourceLang = $cn !== '' ? 'zh' : 'en';
        $targetLang = $sourceLang === 'zh' ? 'en' : 'zh';
        $result = $service->translateDetailed($source, $sourceLang, $targetLang);
        $translated = (string) ($result['translated_text'] ?? '');
        if (trim($translated) === '') {
            $tableSummary['queued_or_failed']++;
            $summary['queued_or_failed']++;
            continue;
        }

        $column = $targetLang === 'zh' ? 'description_cn' : 'description_en';
        $pdo->beginTransaction();
        try {
            // Recheck emptiness inside the transaction so a concurrent manual
            // correction wins and is never overwritten.
            $update = $pdo->prepare("UPDATE `$table` SET `$column` = ? WHERE id = ? AND (`$column` IS NULL OR TRIM(`$column`) = '')");
            $update->execute([$translated, $id]);
            if ($update->rowCount() === 1) {
                $originalLang = $sourceLang;
                $sourceHash = hash('sha256', $source);
                $textEn = $sourceLang === 'en' ? $source : $translated;
                $textZh = $sourceLang === 'zh' ? $source : $translated;
                $enState = $sourceLang === 'en' ? 'original' : (($result['status'] ?? '') === 'manual' ? 'manual' : 'automatic');
                $zhState = $sourceLang === 'zh' ? 'original' : (($result['status'] ?? '') === 'manual' ? 'manual' : 'automatic');
                $registry = $pdo->prepare("INSERT INTO bilingual_text_registry
                    (entity_type, entity_id, field_key, original_lang, original_text, text_en, text_zh, en_state, zh_state, source_hash)
                    VALUES (?, ?, 'description', ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE text_en=IF(en_state='manual', text_en, VALUES(text_en)), text_zh=IF(zh_state='manual', text_zh, VALUES(text_zh)), en_state=IF(en_state='manual', en_state, VALUES(en_state)), zh_state=IF(zh_state='manual', zh_state, VALUES(zh_state)), source_hash=VALUES(source_hash)");
                $registry->execute([$table, $id, $originalLang, $source, $textEn, $textZh, $enState, $zhState, $sourceHash]);
                $tableSummary['updated']++;
                $summary['updated']++;
            } else {
                $tableSummary['skipped']++;
                $summary['skipped']++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $tableSummary['queued_or_failed']++;
            $summary['queued_or_failed']++;
            fwrite(STDERR, "$table#$id: " . get_class($e) . "\n");
        }
    }
    $summary['tables'][$table] = $tableSummary;
}

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($summary['queued_or_failed'] > 0 && $apply ? 1 : 0);
