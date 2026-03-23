-- Migration 046: Add optional order_id to customer_deposits for linking deposits to orders
-- Rollback: ALTER TABLE customer_deposits DROP FOREIGN KEY fk_deposits_order; ALTER TABLE customer_deposits DROP COLUMN order_id;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE() AND table_name = 'customer_deposits' AND column_name = 'order_id');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE customer_deposits ADD COLUMN order_id INT UNSIGNED NULL AFTER customer_id,
     ADD CONSTRAINT fk_deposits_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
