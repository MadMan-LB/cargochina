-- CLMS Migration 070: Actual warehouse receipt item dimensions.
-- Safe/idempotent: nullable columns only; existing receipt values are unchanged.
-- Rollback (only after confirming no values are needed):
--   ALTER TABLE warehouse_receipt_items
--     DROP COLUMN actual_height,
--     DROP COLUMN actual_width,
--     DROP COLUMN actual_length;

SET @m070_table_count := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warehouse_receipt_items'
);

SET @m070_column_count := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warehouse_receipt_items' AND COLUMN_NAME = 'actual_height'
);
SET @m070_sql := IF(@m070_table_count > 0 AND @m070_column_count = 0,
  'ALTER TABLE warehouse_receipt_items ADD COLUMN actual_height DECIMAL(12,4) NULL',
  'SELECT 1');
PREPARE m070_stmt FROM @m070_sql; EXECUTE m070_stmt; DEALLOCATE PREPARE m070_stmt;

SET @m070_column_count := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warehouse_receipt_items' AND COLUMN_NAME = 'actual_width'
);
SET @m070_sql := IF(@m070_table_count > 0 AND @m070_column_count = 0,
  'ALTER TABLE warehouse_receipt_items ADD COLUMN actual_width DECIMAL(12,4) NULL',
  'SELECT 1');
PREPARE m070_stmt FROM @m070_sql; EXECUTE m070_stmt; DEALLOCATE PREPARE m070_stmt;

SET @m070_column_count := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warehouse_receipt_items' AND COLUMN_NAME = 'actual_length'
);
SET @m070_sql := IF(@m070_table_count > 0 AND @m070_column_count = 0,
  'ALTER TABLE warehouse_receipt_items ADD COLUMN actual_length DECIMAL(12,4) NULL',
  'SELECT 1');
PREPARE m070_stmt FROM @m070_sql; EXECUTE m070_stmt; DEALLOCATE PREPARE m070_stmt;
