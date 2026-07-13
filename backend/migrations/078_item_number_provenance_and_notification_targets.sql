-- Migration 078: Preserve item-number provenance and add structured notification targets.
-- Existing item numbers are not rewritten.

SET @m078_has_item_source := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'item_no_source'
);
SET @m078_sql := IF(
  @m078_has_item_source = 0,
  'ALTER TABLE order_items ADD COLUMN item_no_source VARCHAR(20) NOT NULL DEFAULT ''generated'' AFTER item_no',
  'DO 0'
);
PREPARE m078_stmt FROM @m078_sql;
EXECUTE m078_stmt;
DEALLOCATE PREPARE m078_stmt;

SET @m078_has_target_type := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'target_type'
);
SET @m078_sql := IF(
  @m078_has_target_type = 0,
  'ALTER TABLE notifications ADD COLUMN target_type VARCHAR(40) NULL AFTER body',
  'DO 0'
);
PREPARE m078_stmt FROM @m078_sql;
EXECUTE m078_stmt;
DEALLOCATE PREPARE m078_stmt;

SET @m078_has_target_id := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'target_id'
);
SET @m078_sql := IF(
  @m078_has_target_id = 0,
  'ALTER TABLE notifications ADD COLUMN target_id BIGINT UNSIGNED NULL AFTER target_type',
  'DO 0'
);
PREPARE m078_stmt FROM @m078_sql;
EXECUTE m078_stmt;
DEALLOCATE PREPARE m078_stmt;

SET @m078_has_target_index := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND INDEX_NAME = 'idx_notifications_target'
);
SET @m078_sql := IF(
  @m078_has_target_index = 0,
  'CREATE INDEX idx_notifications_target ON notifications (target_type, target_id)',
  'DO 0'
);
PREPARE m078_stmt FROM @m078_sql;
EXECUTE m078_stmt;
DEALLOCATE PREPARE m078_stmt;
