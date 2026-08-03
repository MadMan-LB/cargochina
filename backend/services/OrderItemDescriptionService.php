<?php

require_once __DIR__ . '/TranslationService.php';

/**
 * Completes missing bilingual order-item descriptions without replacing staff-entered text.
 */
final class OrderItemDescriptionService
{
    private PDO $pdo;
    private TranslationService $translations;
    private ?bool $hasSharedCartons = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->translations = new TranslationService($pdo);
    }

    public function findItemsWithoutDescription(int $orderId): array
    {
        $rows = $this->fetchItems($orderId);
        $missing = [];

        foreach ($rows as $row) {
            if ($this->hasText($row['description_en'] ?? null) || $this->hasText($row['description_cn'] ?? null)) {
                continue;
            }

            $sharedContents = $this->decodeSharedContents($row['shared_carton_contents'] ?? null);
            if ($sharedContents && $this->sharedContentsHaveDescriptions($sharedContents)) {
                continue;
            }
            $missing[] = (int) $row['id'];
        }

        return $missing;
    }

    /**
     * Attempts missing translations in two batch requests. Existing values are never overwritten.
     */
    public function completeMissingForOrder(int $orderId): array
    {
        $rows = $this->fetchItems($orderId);
        $tasks = [
            'en:zh' => ['source' => 'en', 'target' => 'zh', 'texts' => [], 'indexes' => [], 'targets' => []],
            'zh:en' => ['source' => 'zh', 'target' => 'en', 'texts' => [], 'indexes' => [], 'targets' => []],
        ];
        $sharedByItem = [];
        $originalSharedJson = [];

        foreach ($rows as $row) {
            $itemId = (int) $row['id'];
            $english = trim((string) ($row['description_en'] ?? ''));
            $chinese = trim((string) ($row['description_cn'] ?? ''));
            if ($english !== '' && $chinese === '') {
                $this->addTask($tasks['en:zh'], $english, ['type' => 'item', 'item_id' => $itemId, 'field' => 'description_cn']);
            } elseif ($chinese !== '' && $english === '') {
                $this->addTask($tasks['zh:en'], $chinese, ['type' => 'item', 'item_id' => $itemId, 'field' => 'description_en']);
            }

            $rawShared = $row['shared_carton_contents'] ?? null;
            $shared = $this->decodeSharedContents($rawShared);
            if (!$shared) {
                continue;
            }
            $sharedByItem[$itemId] = $shared;
            $originalSharedJson[$itemId] = (string) $rawShared;
            foreach ($shared as $index => $content) {
                $contentEnglish = trim((string) ($content['description_en'] ?? ''));
                $contentChinese = trim((string) ($content['description_cn'] ?? ''));
                if ($contentEnglish !== '' && $contentChinese === '') {
                    $this->addTask($tasks['en:zh'], $contentEnglish, [
                        'type' => 'shared', 'item_id' => $itemId, 'index' => $index, 'field' => 'description_cn',
                    ]);
                } elseif ($contentChinese !== '' && $contentEnglish === '') {
                    $this->addTask($tasks['zh:en'], $contentChinese, [
                        'type' => 'shared', 'item_id' => $itemId, 'index' => $index, 'field' => 'description_en',
                    ]);
                }
            }
        }

        $itemUpdates = [];
        $sharedChanged = [];
        $translated = 0;
        $requested = 0;

        foreach ($tasks as $task) {
            if (!$task['texts']) {
                continue;
            }
            $requested += array_sum(array_map('count', $task['targets']));
            $results = $this->translations->translateBatchDetailed($task['texts'], $task['source'], $task['target']);
            foreach ($results as $index => $result) {
                $value = trim((string) ($result['translated_text'] ?? ''));
                if ($value === '') {
                    continue;
                }
                foreach ($task['targets'][$index] ?? [] as $target) {
                    $itemId = (int) $target['item_id'];
                    if ($target['type'] === 'item') {
                        $itemUpdates[$itemId][$target['field']] = $value;
                    } else {
                        $contentIndex = (int) $target['index'];
                        $sharedByItem[$itemId][$contentIndex][$target['field']] = $value;
                        $sharedChanged[$itemId] = true;
                    }
                    $translated++;
                }
            }
        }

        if ($itemUpdates) {
            $update = $this->pdo->prepare(
                "UPDATE order_items
                 SET description_en = CASE WHEN TRIM(COALESCE(description_en, '')) = '' THEN ? ELSE description_en END,
                     description_cn = CASE WHEN TRIM(COALESCE(description_cn, '')) = '' THEN ? ELSE description_cn END
                 WHERE id = ? AND order_id = ?"
            );
            foreach ($itemUpdates as $itemId => $values) {
                $update->execute([
                    $values['description_en'] ?? '',
                    $values['description_cn'] ?? '',
                    $itemId,
                    $orderId,
                ]);
            }
        }

        if ($sharedChanged && $this->supportsSharedCartons()) {
            $updateShared = $this->pdo->prepare(
                'UPDATE order_items SET shared_carton_contents = ? WHERE id = ? AND order_id = ? AND shared_carton_contents = ?'
            );
            foreach (array_keys($sharedChanged) as $itemId) {
                $encoded = json_encode($sharedByItem[$itemId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($encoded !== false) {
                    $updateShared->execute([$encoded, $itemId, $orderId, $originalSharedJson[$itemId]]);
                }
            }
        }

        return [
            'requested' => $requested,
            'translated' => $translated,
            'pending' => max(0, $requested - $translated),
        ];
    }

    private function fetchItems(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }
        $sharedColumn = $this->supportsSharedCartons() ? ', shared_carton_contents' : ", NULL AS shared_carton_contents";
        $stmt = $this->pdo->prepare(
            "SELECT id, description_en, description_cn$sharedColumn FROM order_items WHERE order_id = ? ORDER BY id"
        );
        $stmt->execute([$orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function addTask(array &$task, string $text, array $target): void
    {
        $key = hash('sha256', $text);
        if (!array_key_exists($key, $task['indexes'])) {
            $task['indexes'][$key] = count($task['texts']);
            $task['texts'][] = $text;
            $task['targets'][] = [];
        }
        $task['targets'][$task['indexes'][$key]][] = $target;
    }

    private function supportsSharedCartons(): bool
    {
        if ($this->hasSharedCartons !== null) {
            return $this->hasSharedCartons;
        }
        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM order_items LIKE 'shared_carton_contents'");
            $this->hasSharedCartons = $stmt && $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            $this->hasSharedCartons = false;
        }
        return $this->hasSharedCartons;
    }

    private function decodeSharedContents($value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        if (!$this->hasText($value)) {
            return [];
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function sharedContentsHaveDescriptions(array $contents): bool
    {
        foreach ($contents as $content) {
            if (!$this->hasText($content['description_en'] ?? null) && !$this->hasText($content['description_cn'] ?? null)) {
                return false;
            }
        }
        return true;
    }

    private function hasText($value): bool
    {
        return trim((string) ($value ?? '')) !== '';
    }
}
