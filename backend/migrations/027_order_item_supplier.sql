-- CLMS Migration 027: Item-level supplier (multi-source orders)
-- Rollback: ALTER TABLE order_items DROP FOREIGN KEY fk_order_items_supplier; ALTER TABLE order_items DROP COLUMN supplier_id;

SET @m027 = (SELECT COUNT(*)
FROM information_schema.COLUMNS
WHERE table_schema=DATABASE
() AND table_name='order_items' AND column_name='supplier_id');
SET @sql =
IF(@m027=0, 'ALTER TABLE order_items ADD COLUMN supplier_id INT UNSIGNED NULL AFTER product_id, ADD CONSTRAINT fk_order_items_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
