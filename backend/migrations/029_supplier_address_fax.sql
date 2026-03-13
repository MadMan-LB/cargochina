-- CLMS Migration 029: Supplier address and fax (for Template.xlsx-style export)
-- Rollback: ALTER TABLE suppliers DROP COLUMN address, DROP COLUMN fax;

SET @m029 = (SELECT COUNT(*)
FROM information_schema.COLUMNS
WHERE table_schema=DATABASE
() AND table_name='suppliers' AND column_name='address');
SET @sql =
IF(@m029=0, 'ALTER TABLE suppliers ADD COLUMN address TEXT NULL AFTER factory_location, ADD COLUMN fax VARCHAR(50) NULL AFTER phone', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
