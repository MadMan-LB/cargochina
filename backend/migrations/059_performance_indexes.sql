SET @idx_exists := (
    SELECT COUNT(1)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'notifications'
      AND index_name = 'idx_notifications_user_created'
);
SET @sql := IF(
    @idx_exists = 0,
    'CREATE INDEX idx_notifications_user_created ON notifications (user_id, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(1)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'shipment_drafts'
      AND index_name = 'idx_shipment_drafts_status'
);
SET @sql := IF(
    @idx_exists = 0,
    'CREATE INDEX idx_shipment_drafts_status ON shipment_drafts (status)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(1)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'orders'
      AND index_name = 'idx_orders_status_expected_ready'
);
SET @sql := IF(
    @idx_exists = 0,
    'CREATE INDEX idx_orders_status_expected_ready ON orders (status, expected_ready_date)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
