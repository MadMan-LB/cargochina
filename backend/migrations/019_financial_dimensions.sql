-- CLMS Migration 019: Financial dimensions upgrade
-- Supplier payment tolerance, customer deposits, order currency, order item dimensions
-- Rollback: ALTER TABLE supplier_payments DROP COLUMN invoice_amount, DROP COLUMN discount_amount, DROP COLUMN marked_full_payment, DROP COLUMN marked_by;
--   DROP TABLE IF EXISTS customer_deposits;
--   ALTER TABLE orders DROP COLUMN currency;
--   ALTER TABLE order_items DROP COLUMN item_length, DROP COLUMN item_width, DROP COLUMN item_height;

-- Supplier payment tolerance
ALTER TABLE supplier_payments
ADD COLUMN invoice_amount DECIMAL
(12,4) NULL AFTER amount,
ADD COLUMN discount_amount DECIMAL
(12,4) NULL DEFAULT 0 AFTER invoice_amount,
ADD COLUMN marked_full_payment TINYINT
(1) NOT NULL DEFAULT 0 AFTER discount_amount,
ADD COLUMN marked_by INT UNSIGNED NULL AFTER marked_full_payment;

-- Customer deposits
CREATE TABLE
IF NOT EXISTS customer_deposits
(
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  amount DECIMAL
(12,4) NOT NULL,
  currency VARCHAR
(10) NOT NULL DEFAULT 'USD',
  payment_method VARCHAR
(50) NULL,
  reference_no VARCHAR
(100) NULL,
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY
(customer_id) REFERENCES customers
(id) ON
DELETE CASCADE,
  FOREIGN KEY (created_by)
REFERENCES users
(id) ON
DELETE
SET NULL
,
  CONSTRAINT chk_deposit_currency CHECK
(currency IN
('USD', 'RMB')),
  INDEX idx_deposits_customer
(customer_id)
);

-- Order currency
ALTER TABLE orders
ADD COLUMN currency VARCHAR
(10) NOT NULL DEFAULT 'USD' AFTER expected_ready_date;

-- Order item dimensions (L/W/H in cm)
ALTER TABLE order_items
ADD COLUMN item_length DECIMAL
(10,4) NULL AFTER declared_weight,
ADD COLUMN item_width DECIMAL
(10,4) NULL AFTER item_length,
ADD COLUMN item_height DECIMAL
(10,4) NULL AFTER item_width;
