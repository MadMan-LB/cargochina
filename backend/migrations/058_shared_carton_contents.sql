-- Support mixed/shared cartons in draft-order backed order_items rows.

SET @has_shared_carton_enabled := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'order_items'
      AND COLUMN_NAME = 'shared_carton_enabled'
);
SET @has_shared_carton_code := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'order_items'
      AND COLUMN_NAME = 'shared_carton_code'
);
SET @has_shared_carton_contents := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'order_items'
      AND COLUMN_NAME = 'shared_carton_contents'
);

SET @sql := IF(
    @has_shared_carton_enabled = 0,
    'ALTER TABLE order_items ADD COLUMN shared_carton_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER custom_design_note',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @has_shared_carton_code = 0,
    'ALTER TABLE order_items ADD COLUMN shared_carton_code VARCHAR(100) NULL AFTER shared_carton_enabled',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @has_shared_carton_contents = 0,
    'ALTER TABLE order_items ADD COLUMN shared_carton_contents LONGTEXT NULL AFTER shared_carton_code',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
