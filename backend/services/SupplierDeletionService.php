<?php

/** Hard deletion is only allowed for unused suppliers; never cascade business history. */
final class SupplierDeletionService
{
    public static function blockingReference(PDO $pdo, int $id): ?string
    {
        if (!$pdo->inTransaction()) throw new LogicException('Supplier deletion requires a transaction');
        // Include optional modules and legacy schemas, including SET NULL / CASCADE links.
        // These metadata and locking queries also work on MySQL 5.5 (no JSON functions).
        $columns = $pdo->query("SELECT c.TABLE_NAME, c.COLUMN_NAME FROM information_schema.COLUMNS c
            JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME
            WHERE c.TABLE_SCHEMA=DATABASE() AND t.TABLE_TYPE='BASE TABLE'
              AND (c.COLUMN_NAME='supplier_id' OR c.COLUMN_NAME='shared_carton_contents'
                   OR (c.TABLE_NAME='design_attachments' AND c.COLUMN_NAME='entity_id'))
            UNION SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA=DATABASE()
              AND REFERENCED_TABLE_NAME='suppliers' AND REFERENCED_COLUMN_NAME='id'
            ORDER BY TABLE_NAME, COLUMN_NAME")->fetchAll(PDO::FETCH_ASSOC);
        $labels = [
            'orders'=>'orders', 'order_items'=>'order items', 'products'=>'products',
            'order_template_items'=>'saved item sets', 'procurement_drafts'=>'procurement drafts',
            'supplier_payments'=>'payment history', 'supplier_interactions'=>'visits or notes',
            'expenses'=>'expenses', 'draft_order_costs'=>'order costs',
            'item_number_reservations'=>'item number reservations',
        ];
        foreach ($columns as $column) {
            $table = '`' . str_replace('`', '``', $column['TABLE_NAME']) . '`';
            $field = '`' . str_replace('`', '``', $column['COLUMN_NAME']) . '`';
            if ($column['TABLE_NAME'] === 'design_attachments' && $column['COLUMN_NAME'] === 'entity_id') {
                $stmt = $pdo->prepare("SELECT 1 FROM design_attachments WHERE entity_type='supplier' AND entity_id=? LIMIT 1 FOR UPDATE");
                $stmt->execute([$id]);
                if ($stmt->fetchColumn()) return 'supplier attachments';
            } elseif ($column['COLUMN_NAME'] === 'shared_carton_contents') {
                $rows = $pdo->query("SELECT $field FROM $table WHERE $field IS NOT NULL AND $field <> '' AND $field <> '[]' FOR UPDATE");
                while (($raw = $rows->fetchColumn()) !== false) {
                    $contents = json_decode($raw, true);
                    if (!is_array($contents)) continue;
                    foreach ($contents as $content) {
                        if (is_array($content) && (string)($content['supplier_id'] ?? '') === (string)$id) {
                            return 'shared-carton items';
                        }
                    }
                }
            } else {
                $stmt = $pdo->prepare("SELECT 1 FROM $table WHERE $field=? LIMIT 1 FOR UPDATE");
                $stmt->execute([$id]);
                if ($stmt->fetchColumn()) return $labels[$column['TABLE_NAME']] ?? 'related business records';
            }
        }
        return null;
    }
}
