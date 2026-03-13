-- CLMS Migration 028: Per-item supplier in order templates (multi-source)
-- Rollback: ALTER TABLE order_template_items DROP FOREIGN KEY fk_order_template_items_supplier; ALTER TABLE order_template_items DROP COLUMN supplier_id;

SET @m028 = (SELECT COUNT(*)
FROM information_schema.COLUMNS
WHERE table_schema=DATABASE
() AND table_name='order_template_items' AND column_name='supplier_id');
SET @sql =
IF(@m028=0, 'ALTER TABLE order_template_items ADD COLUMN supplier_id INT UNSIGNED NULL AFTER product_id, ADD CONSTRAINT fk_order_template_items_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
