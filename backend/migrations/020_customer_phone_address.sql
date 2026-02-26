-- CLMS Migration 020: Customer phone and address
-- Rollback: ALTER TABLE customers DROP COLUMN phone, DROP COLUMN address;
--   DROP INDEX idx_customers_phone ON customers;

ALTER TABLE customers
ADD COLUMN phone VARCHAR
(50) NULL AFTER name,
ADD COLUMN address TEXT NULL AFTER phone;

CREATE INDEX idx_customers_phone ON customers (phone);
