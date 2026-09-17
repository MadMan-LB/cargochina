-- Separate employee-entered packing-list references from reserved internal I.I.N.
-- Nullable and deliberately NOT unique. No historical backfill.
SET @m081_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='order_items' AND COLUMN_NAME='item_number')=0,
  'ALTER TABLE order_items ADD COLUMN item_number VARCHAR(150) NULL AFTER item_no', 'SELECT 1');
PREPARE m081_stmt FROM @m081_sql;
EXECUTE m081_stmt;
DEALLOCATE PREPARE m081_stmt;
SET @m081_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='order_template_items' AND COLUMN_NAME='item_number')=0,
  'ALTER TABLE order_template_items ADD COLUMN item_number VARCHAR(150) NULL AFTER item_no', 'SELECT 1');
PREPARE m081_stmt FROM @m081_sql;
EXECUTE m081_stmt;
DEALLOCATE PREPARE m081_stmt;
