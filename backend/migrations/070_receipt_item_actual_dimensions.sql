-- CLMS Migration 070: Receipt item actual dimensions
-- Stores warehouse-measured item dimensions captured during receiving.
-- Rollback: ALTER TABLE warehouse_receipt_items DROP COLUMN actual_height, DROP COLUMN actual_width, DROP COLUMN actual_length;

SET @t := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE table_schema = DATABASE()
      AND table_name = 'warehouse_receipt_items'
);

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'warehouse_receipt_items'
      AND column_name = 'actual_height'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE warehouse_receipt_items ADD COLUMN actual_height DECIMAL(12,4) NULL AFTER actual_weight', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'warehouse_receipt_items'
      AND column_name = 'actual_width'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE warehouse_receipt_items ADD COLUMN actual_width DECIMAL(12,4) NULL AFTER actual_height', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'warehouse_receipt_items'
      AND column_name = 'actual_length'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE warehouse_receipt_items ADD COLUMN actual_length DECIMAL(12,4) NULL AFTER actual_width', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
