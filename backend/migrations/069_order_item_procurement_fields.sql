-- CLMS Migration 069: Procurement draft/order item optional commercial fields.
-- Adds nullable item-level fields used by draft procurement import/export and order templates.

SET @t := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE table_schema = DATABASE()
      AND table_name = 'order_items'
);

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'order_items'
      AND column_name = 'materials'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE order_items ADD COLUMN materials TEXT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'order_items'
      AND column_name = 'height'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE order_items ADD COLUMN height DECIMAL(12,4) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'order_items'
      AND column_name = 'width'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE order_items ADD COLUMN width DECIMAL(12,4) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'order_items'
      AND column_name = 'length'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE order_items ADD COLUMN length DECIMAL(12,4) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'order_items'
      AND column_name = 'brand'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE order_items ADD COLUMN brand VARCHAR(150) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'order_items'
      AND column_name = 'express_number'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE order_items ADD COLUMN express_number VARCHAR(150) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE table_schema = DATABASE()
      AND table_name = 'order_template_items'
);

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'order_template_items'
      AND column_name = 'materials'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE order_template_items ADD COLUMN materials TEXT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'order_template_items'
      AND column_name = 'height'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE order_template_items ADD COLUMN height DECIMAL(12,4) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'order_template_items'
      AND column_name = 'width'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE order_template_items ADD COLUMN width DECIMAL(12,4) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'order_template_items'
      AND column_name = 'length'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE order_template_items ADD COLUMN length DECIMAL(12,4) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'order_template_items'
      AND column_name = 'brand'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE order_template_items ADD COLUMN brand VARCHAR(150) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'order_template_items'
      AND column_name = 'what_brand'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE order_template_items ADD COLUMN what_brand VARCHAR(150) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'order_template_items'
      AND column_name = 'copy_normal_goods'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE order_template_items ADD COLUMN copy_normal_goods VARCHAR(60) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'order_template_items'
      AND column_name = 'code'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE order_template_items ADD COLUMN code VARCHAR(100) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'order_template_items'
      AND column_name = 'express_number'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE order_template_items ADD COLUMN express_number VARCHAR(150) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'order_template_items'
      AND column_name = 'size'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE order_template_items ADD COLUMN size VARCHAR(150) NULL', 'SELECT 1');
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
      AND column_name = 'materials'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE procurement_draft_items ADD COLUMN materials TEXT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'procurement_draft_items'
      AND column_name = 'height'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE procurement_draft_items ADD COLUMN height DECIMAL(12,4) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'procurement_draft_items'
      AND column_name = 'width'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE procurement_draft_items ADD COLUMN width DECIMAL(12,4) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'procurement_draft_items'
      AND column_name = 'length'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE procurement_draft_items ADD COLUMN length DECIMAL(12,4) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'procurement_draft_items'
      AND column_name = 'brand'
);
SET @s := IF(@t > 0 AND @c = 0, 'ALTER TABLE procurement_draft_items ADD COLUMN brand VARCHAR(150) NULL', 'SELECT 1');
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
