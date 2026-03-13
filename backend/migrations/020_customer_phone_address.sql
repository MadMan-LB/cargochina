-- CLMS Migration 020: Customer phone and address
-- Rollback: ALTER TABLE customers DROP COLUMN phone, DROP COLUMN address;
--   DROP INDEX idx_customers_phone ON customers;

SET @m020 = (SELECT COUNT(*)
FROM information_schema.COLUMNS
WHERE table_schema=DATABASE
() AND table_name='customers' AND column_name='phone');
SET @sql =
IF(@m020=0, 'ALTER TABLE customers ADD COLUMN phone VARCHAR(50) NULL AFTER name, ADD COLUMN address TEXT NULL AFTER phone', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx = (SELECT COUNT(*)
FROM information_schema.STATISTICS
WHERE table_schema=DATABASE
() AND table_name='customers' AND index_name='idx_customers_phone');
SET @sql =
IF(@idx=0, 'CREATE INDEX idx_customers_phone ON customers (phone)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
