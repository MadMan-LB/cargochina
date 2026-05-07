-- CLMS Migration 060: Item shipment metadata and receiving quantity/pricing fields.
-- Rollback note:
--   ALTER TABLE order_items DROP COLUMN what_brand, DROP COLUMN copy_normal_goods,
--     DROP COLUMN code, DROP COLUMN express_number, DROP COLUMN size;
--   ALTER TABLE procurement_draft_items DROP COLUMN what_brand, DROP COLUMN copy_normal_goods,
--     DROP COLUMN code, DROP COLUMN express_number, DROP COLUMN size;
--   ALTER TABLE warehouse_receipt_items DROP COLUMN actual_pieces_per_carton,
--     DROP COLUMN actual_quantity, DROP COLUMN unit_price, DROP COLUMN total_amount;
--   ALTER TABLE suppliers DROP COLUMN address;

SET @t := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE table_schema = DATABASE()
      AND table_name = 'suppliers'
);
SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'suppliers'
      AND column_name = 'address'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE suppliers ADD COLUMN address TEXT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'order_items'
      AND column_name = 'what_brand'
);
SET @s := IF(@c = 0, 'ALTER TABLE order_items ADD COLUMN what_brand VARCHAR(150) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'order_items'
      AND column_name = 'copy_normal_goods'
);
SET @s := IF(@c = 0, 'ALTER TABLE order_items ADD COLUMN copy_normal_goods VARCHAR(60) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'order_items'
      AND column_name = 'code'
);
SET @s := IF(@c = 0, 'ALTER TABLE order_items ADD COLUMN code VARCHAR(100) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'order_items'
      AND column_name = 'express_number'
);
SET @s := IF(@c = 0, 'ALTER TABLE order_items ADD COLUMN express_number VARCHAR(150) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'order_items'
      AND column_name = 'size'
);
SET @s := IF(@c = 0, 'ALTER TABLE order_items ADD COLUMN size VARCHAR(150) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE table_schema = DATABASE()
      AND table_name = 'procurement_draft_items'
);

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'procurement_draft_items'
      AND column_name = 'what_brand'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE procurement_draft_items ADD COLUMN what_brand VARCHAR(150) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'procurement_draft_items'
      AND column_name = 'copy_normal_goods'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE procurement_draft_items ADD COLUMN copy_normal_goods VARCHAR(60) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'procurement_draft_items'
      AND column_name = 'code'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE procurement_draft_items ADD COLUMN code VARCHAR(100) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'procurement_draft_items'
      AND column_name = 'express_number'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE procurement_draft_items ADD COLUMN express_number VARCHAR(150) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'procurement_draft_items'
      AND column_name = 'size'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE procurement_draft_items ADD COLUMN size VARCHAR(150) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'warehouse_receipt_items'
      AND column_name = 'actual_pieces_per_carton'
);
SET @s := IF(@c = 0, 'ALTER TABLE warehouse_receipt_items ADD COLUMN actual_pieces_per_carton DECIMAL(12,4) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'warehouse_receipt_items'
      AND column_name = 'actual_quantity'
);
SET @s := IF(@c = 0, 'ALTER TABLE warehouse_receipt_items ADD COLUMN actual_quantity DECIMAL(12,4) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'warehouse_receipt_items'
      AND column_name = 'unit_price'
);
SET @s := IF(@c = 0, 'ALTER TABLE warehouse_receipt_items ADD COLUMN unit_price DECIMAL(12,4) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'warehouse_receipt_items'
      AND column_name = 'total_amount'
);
SET @s := IF(@c = 0, 'ALTER TABLE warehouse_receipt_items ADD COLUMN total_amount DECIMAL(12,4) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
