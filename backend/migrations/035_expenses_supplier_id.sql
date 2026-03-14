-- Migration 035: Expenses supplier_id (link payee to supplier for filtering/search)
-- Rollback: ALTER TABLE expenses DROP FOREIGN KEY fk_expenses_supplier; ALTER TABLE expenses DROP COLUMN supplier_id;

SET @c = (SELECT COUNT(*)
FROM information_schema.COLUMNS
WHERE table_schema=DATABASE
() AND table_name='expenses' AND column_name='supplier_id');

SET @s =
IF(@c=0,
    'ALTER TABLE expenses ADD COLUMN supplier_id INT UNSIGNED NULL AFTER customer_id,
     ADD CONSTRAINT fk_expenses_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
     ADD INDEX idx_expenses_supplier (supplier_id)',
    'DO 0');

PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
