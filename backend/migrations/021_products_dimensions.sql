-- CLMS Migration 021: Products optional L/H/W for CBM calculation
-- Rollback: ALTER TABLE products DROP COLUMN length_cm, DROP COLUMN width_cm, DROP COLUMN height_cm;

SET @m021 = (SELECT COUNT(*)
FROM information_schema.COLUMNS
WHERE table_schema=DATABASE
() AND table_name='products' AND column_name='length_cm');
SET @sql =
IF(@m021=0, 'ALTER TABLE products ADD COLUMN length_cm DECIMAL(10,4) NULL AFTER weight, ADD COLUMN width_cm DECIMAL(10,4) NULL AFTER length_cm, ADD COLUMN height_cm DECIMAL(10,4) NULL AFTER width_cm', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
