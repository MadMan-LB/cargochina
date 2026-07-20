-- CLMS Migration 079: Repair/compatibility guard for receipt-item dimensions.
-- Migration 070 introduced these canonical nullable fields. This migration is
-- intentionally idempotent so installations that skipped or partially applied
-- 070 are repaired without changing existing receipt data.

SET @m079_table_count := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warehouse_receipt_items'
);

SET @m079_column_count := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warehouse_receipt_items' AND COLUMN_NAME = 'actual_height'
);
SET @m079_sql := IF(@m079_table_count > 0 AND @m079_column_count = 0,
  'ALTER TABLE warehouse_receipt_items ADD COLUMN actual_height DECIMAL(12,4) NULL',
  'SELECT 1');
PREPARE m079_stmt FROM @m079_sql; EXECUTE m079_stmt; DEALLOCATE PREPARE m079_stmt;

SET @m079_column_count := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warehouse_receipt_items' AND COLUMN_NAME = 'actual_width'
);
SET @m079_sql := IF(@m079_table_count > 0 AND @m079_column_count = 0,
  'ALTER TABLE warehouse_receipt_items ADD COLUMN actual_width DECIMAL(12,4) NULL',
  'SELECT 1');
PREPARE m079_stmt FROM @m079_sql; EXECUTE m079_stmt; DEALLOCATE PREPARE m079_stmt;

SET @m079_column_count := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warehouse_receipt_items' AND COLUMN_NAME = 'actual_length'
);
SET @m079_sql := IF(@m079_table_count > 0 AND @m079_column_count = 0,
  'ALTER TABLE warehouse_receipt_items ADD COLUMN actual_length DECIMAL(12,4) NULL',
  'SELECT 1');
PREPARE m079_stmt FROM @m079_sql; EXECUTE m079_stmt; DEALLOCATE PREPARE m079_stmt;
