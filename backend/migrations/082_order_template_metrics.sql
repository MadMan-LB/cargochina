-- Preserve the measurement basis and customer price when item sets are reused.
-- MySQL does not support MariaDB's ADD COLUMN IF NOT EXISTS syntax.
-- Check each column independently so reruns and partial deployments are safe.
SET @clms_082_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_template_items'
              AND COLUMN_NAME = 'dimensions_scope'),
    'SELECT 1',
    'ALTER TABLE order_template_items ADD COLUMN dimensions_scope VARCHAR(10) NULL'
);
PREPARE clms_082_stmt FROM @clms_082_sql;
EXECUTE clms_082_stmt;
DEALLOCATE PREPARE clms_082_stmt;

SET @clms_082_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_template_items'
              AND COLUMN_NAME = 'sell_price'),
    'SELECT 1',
    'ALTER TABLE order_template_items ADD COLUMN sell_price DECIMAL(12,4) NULL'
);
PREPARE clms_082_stmt FROM @clms_082_sql;
EXECUTE clms_082_stmt;
DEALLOCATE PREPARE clms_082_stmt;

ALTER TABLE order_template_items
    MODIFY description_cn TEXT NULL,
    MODIFY description_en TEXT NULL;

-- Snapshot new order measurements; NULL preserves the legacy fallback without guessing history.
SET @clms_082_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items'
              AND COLUMN_NAME = 'dimensions_scope'),
    'SELECT 1',
    'ALTER TABLE order_items ADD COLUMN dimensions_scope VARCHAR(10) NULL'
);
PREPARE clms_082_stmt FROM @clms_082_sql;
EXECUTE clms_082_stmt;
DEALLOCATE PREPARE clms_082_stmt;
