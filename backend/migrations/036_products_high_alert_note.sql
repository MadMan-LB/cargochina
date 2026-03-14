-- Migration 036: Product-level high alert notes
-- Rollback: ALTER TABLE products DROP COLUMN high_alert_note;

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'products'
      AND column_name = 'high_alert_note'
);

SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE products ADD COLUMN high_alert_note TEXT NULL AFTER packaging',
    'DO 0'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
