<?php

/** Revision of editable catalog data, excluding derived financial/usage projections. */
final class CatalogRevisionService
{
    public static function revision(PDO $pdo, string $table, int $id, bool $lock = false): string
    {
        if (!in_array($table, ['products', 'suppliers'], true)) throw new InvalidArgumentException('Unsupported catalog');
        $s = $pdo->prepare("SELECT * FROM $table WHERE id=?" . ($lock ? ' FOR UPDATE' : ''));
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) jsonError('Catalog record no longer exists', 404);
        if ($table === 'products') {
            $s = $pdo->prepare("SELECT item_type_code,confidence,is_confirmed FROM item_classifications WHERE entity_type='product' AND entity_id=?");
            $s->execute([$id]);
            $row['classification'] = $s->fetch(PDO::FETCH_ASSOC) ?: null;
            $s = $pdo->prepare('SELECT description_text,description_translated,sort_order FROM product_description_entries WHERE product_id=? ORDER BY sort_order,id');
            $s->execute([$id]);
            $row['descriptions'] = $s->fetchAll(PDO::FETCH_ASSOC);
        }
        return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
    }

    public static function assertCurrent(PDO $pdo, string $table, int $id, array $input): void
    {
        if (!$pdo->inTransaction()) throw new LogicException('Catalog revision check requires a transaction');
        $revision = $input['revision'] ?? null;
        if (!is_string($revision) || !hash_equals(self::revision($pdo, $table, $id, true), $revision)) {
            jsonError('This record changed or the editor is out of date. Reopen it before saving; your changes have not been applied.', 409);
        }
    }
}
