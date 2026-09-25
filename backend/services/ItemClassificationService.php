<?php

class ItemClassificationService
{
    private PDO $pdo;
    private const CODES = ['normal', 'replica', 'cosmetics', 'branded', 'food', 'dangerous', 'other', 'unclassified'];

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function normalize($value): string
    {
        $raw = mb_strtolower(trim((string) $value));
        $compact = preg_replace('/[\s_\/\\-]+/u', '', $raw) ?? $raw;
        $map = [
            'normal' => 'normal', 'normalgoods' => 'normal', '普通货物' => 'normal', '普货' => 'normal',
            'copy' => 'replica', 'copygoods' => 'replica', 'replica' => 'replica', '仿牌' => 'replica', '仿货' => 'replica',
            'cosmetics' => 'cosmetics', 'cosmetic' => 'cosmetics', '化妆品' => 'cosmetics', '美妆' => 'cosmetics',
            'branded' => 'branded', 'brandedgoods' => 'branded', 'brand' => 'branded', '品牌货物' => 'branded', '品牌' => 'branded',
            'food' => 'food', 'foods' => 'food', '食品' => 'food', '食物' => 'food',
            'dangerous' => 'dangerous', 'dangerousgoods' => 'dangerous', 'hazmat' => 'dangerous', 'hazardous' => 'dangerous', '危险品' => 'dangerous',
            'other' => 'other', '其他' => 'other', 'unclassified' => 'unclassified',
        ];
        $code = $map[$compact] ?? $map[$raw] ?? '';
        return in_array($code, self::CODES, true) ? $code : 'unclassified';
    }

    public function suggest(array $item): array
    {
        $text = mb_strtolower(implode(' ', array_filter([
            $item['name'] ?? null, $item['description'] ?? null, $item['description_en'] ?? null,
            $item['description_cn'] ?? null, $item['category'] ?? null, $item['brand'] ?? null,
            $item['supplier_name'] ?? null,
        ], static fn($v) => trim((string) $v) !== '')));
        $rules = [
            'dangerous' => ['dangerous', 'hazard', 'hazmat', 'battery', 'lithium', 'flammable', 'chemical', '危险', '电池', '锂电'],
            'cosmetics' => ['cosmetic', 'makeup', 'lipstick', 'perfume', 'cream', '化妆', '口红', '香水', '护肤'],
            'food' => ['food', 'snack', 'drink', 'beverage', 'tea', 'coffee', '食品', '零食', '饮料', '茶叶'],
            'replica' => ['replica', 'copy goods', 'counterfeit', 'imitation', '仿牌', '仿货', '高仿'],
            'branded' => ['branded', 'brand name', 'logo', 'trademark', '品牌', '商标'],
        ];
        foreach ($rules as $code => $keywords) {
            foreach ($keywords as $keyword) {
                if ($text !== '' && mb_strpos($text, $keyword) !== false) {
                    return ['item_type_code' => $code, 'confidence' => 0.9000, 'requires_confirmation' => true, 'reason' => 'keyword:' . $keyword];
                }
            }
        }
        // Normal must never be a silent fallback. Unknown items are explicitly
        // unclassified until an operator confirms a real type.
        return ['item_type_code' => 'unclassified', 'confidence' => 0.2500, 'requires_confirmation' => true, 'reason' => 'insufficient_evidence'];
    }

    public function set(string $entityType,int $entityId,string $code,?float $confidence,bool $confirmed,?int $userId,string $source): void
    {
        $owns=!$this->pdo->inTransaction();if($owns)$this->pdo->beginTransaction();
        try{$this->setLocked($entityType,$entityId,$code,$confidence,$confirmed,$userId,$source);if($owns)$this->pdo->commit();}
        catch(Throwable $e){if($owns&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    private function setLocked(string $entityType, int $entityId, string $code, ?float $confidence, bool $confirmed, ?int $userId, string $source): void
    {
        if (!in_array($entityType,['product','order_item'],true) || $entityId <= 0) throw new InvalidArgumentException('Invalid classification entity');
        if ($confidence !== null && (!is_finite($confidence) || $confidence<0 || $confidence>1)) throw new InvalidArgumentException('Invalid classification confidence');
        $normalized = $this->normalize($code);
        if ($normalized==='unclassified' && strtolower(trim($code))!=='unclassified') throw new InvalidArgumentException('Invalid item type');
        $code = $normalized;
        $table=$entityType==='product'?'products':'order_items';$lock=$this->pdo->prepare("SELECT id FROM $table WHERE id=? FOR UPDATE");$lock->execute([$entityId]);if(!$lock->fetchColumn())throw new InvalidArgumentException('Classification entity not found');
        $oldStmt = $this->pdo->prepare('SELECT item_type_code, is_confirmed FROM item_classifications WHERE entity_type=? AND entity_id=?');
        $oldStmt->execute([$entityType, $entityId]);
        $old = $oldStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $sql = "INSERT INTO item_classifications (entity_type, entity_id, item_type_code, confidence, source, is_confirmed, confirmed_by, confirmed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE item_type_code=VALUES(item_type_code), confidence=VALUES(confidence), source=VALUES(source), is_confirmed=VALUES(is_confirmed), confirmed_by=VALUES(confirmed_by), confirmed_at=VALUES(confirmed_at)";
        $this->pdo->prepare($sql)->execute([$entityType, $entityId, $code, $confidence, $source, $confirmed ? 1 : 0, $confirmed ? $userId : null, $confirmed ? date('Y-m-d H:i:s') : null]);
        if (!$old || $old['item_type_code'] !== $code || (bool) $old['is_confirmed'] !== $confirmed) {
            $this->pdo->prepare('INSERT INTO item_classification_history (entity_type, entity_id, old_type_code, new_type_code, confidence, source, changed_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$entityType, $entityId, $old['item_type_code'] ?? null, $code, $confidence, $source, $userId]);
        }
    }

    public function get(string $entityType, int $entityId): ?array
    {
        return $this->getMany($entityType, [$entityId])[$entityId] ?? null;
    }

    /** Request-local result, never retained across saves or permission changes. */
    public function getMany(string $entityType, array $entityIds): array
    {
        $result = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $entityIds))), 200) as $ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->pdo->prepare("SELECT ic.*, it.label_en, it.label_zh FROM item_classifications ic JOIN item_types it ON it.code=ic.item_type_code WHERE ic.entity_type=? AND ic.entity_id IN ($marks)");
            $stmt->execute(array_merge([$entityType], $ids));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $result[(int)$row['entity_id']] = $row;
        }
        return $result;
    }
}
