-- Migration 048: Customers email, customer_country_shipping (country + shipping code per country)
-- Rollback: DROP TABLE IF EXISTS customer_country_shipping; ALTER TABLE customers DROP COLUMN email;

-- Add email to customers
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'email');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE customers ADD COLUMN email VARCHAR(255) NULL AFTER phone',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- customer_country_shipping: which countries a customer ships to, with optional shipping code per country
CREATE TABLE IF NOT EXISTS customer_country_shipping (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    country_id INT UNSIGNED NOT NULL,
    shipping_code VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_customer_country (customer_id, country_id),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE CASCADE,
    INDEX idx_customer_country_shipping_customer (customer_id),
    INDEX idx_customer_country_shipping_country (country_id)
);
