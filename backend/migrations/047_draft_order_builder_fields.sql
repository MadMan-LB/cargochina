-- CLMS Migration 047: Draft Order Builder fields
-- Adds item-level HS code and custom design persistence for real draft orders.
-- Rollback note:
--   ALTER TABLE order_items DROP COLUMN hs_code, DROP COLUMN custom_design_required, DROP COLUMN custom_design_note;

SET @c = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'order_items'
      AND column_name = 'hs_code'
);
SET @s = IF(
    @c = 0,
    'ALTER TABLE order_items ADD COLUMN hs_code VARCHAR(40) NULL AFTER description_en',
    'DO 0'
);
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'order_items'
      AND column_name = 'custom_design_required'
);
SET @s = IF(
    @c = 0,
    'ALTER TABLE order_items ADD COLUMN custom_design_required TINYINT(1) NOT NULL DEFAULT 0 AFTER hs_code',
    'DO 0'
);
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'order_items'
      AND column_name = 'custom_design_note'
);
SET @s = IF(
    @c = 0,
    'ALTER TABLE order_items ADD COLUMN custom_design_note TEXT NULL AFTER custom_design_required',
    'DO 0'
);
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
