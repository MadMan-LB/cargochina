-- CLMS Migration 030: Customer confirmation tokens + stale-order config key
-- Rollback: ALTER TABLE orders DROP COLUMN confirmation_token;
--   DELETE FROM system_config WHERE key_name IN ('STALE_ORDER_THRESHOLD_DAYS', 'STALE_ORDER_NOTIFY_ADMIN');

SET @m030 = (SELECT COUNT(*)
FROM information_schema.COLUMNS
WHERE table_schema=DATABASE
() AND table_name='orders' AND column_name='confirmation_token');
SET @sql =
IF(@m030=0, 'ALTER TABLE orders ADD COLUMN confirmation_token VARCHAR(64) NULL UNIQUE AFTER status', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO system_config
  (key_name, key_value)
VALUES
  ('STALE_ORDER_THRESHOLD_DAYS', '3'),
  ('STALE_ORDER_NOTIFY_ADMIN', '1')
ON DUPLICATE KEY
UPDATE key_value = VALUES
(key_value);
