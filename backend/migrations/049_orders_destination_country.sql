-- Migration 049: Add destination_country_id to orders (which country this order ships to)
-- Rollback: ALTER TABLE orders DROP FOREIGN KEY fk_orders_destination_country; ALTER TABLE orders DROP COLUMN destination_country_id;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE() AND table_name = 'orders' AND column_name = 'destination_country_id');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE orders ADD COLUMN destination_country_id INT UNSIGNED NULL AFTER currency,
     ADD CONSTRAINT fk_orders_destination_country FOREIGN KEY (destination_country_id) REFERENCES countries(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
