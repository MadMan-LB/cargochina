-- CLMS Migration 010: Supplier store ID, payments, interactions
-- Rollback: DROP TABLE supplier_interactions, supplier_payments;
--   ALTER TABLE suppliers DROP COLUMN store_id;
--   DELETE FROM system_config WHERE key_name = 'MIN_PHOTOS_PER_ITEM';

ALTER TABLE suppliers
ADD COLUMN store_id VARCHAR
(100) NULL AFTER code;

CREATE TABLE
IF NOT EXISTS supplier_payments
(
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  order_id INT UNSIGNED NULL,
  amount DECIMAL
(12,4) NOT NULL,
  currency VARCHAR
(10) NOT NULL DEFAULT 'USD',
  payment_type VARCHAR
(20) NOT NULL DEFAULT 'partial',
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY
(supplier_id) REFERENCES suppliers
(id) ON
DELETE CASCADE,
  FOREIGN KEY (order_id)
REFERENCES orders
(id) ON
DELETE
SET NULL
,
  CONSTRAINT chk_payment_type CHECK
(payment_type IN
('partial', 'full'))
);

CREATE TABLE
IF NOT EXISTS supplier_interactions
(
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  interaction_type VARCHAR
(50) NOT NULL DEFAULT 'visit',
  content JSON,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY
(supplier_id) REFERENCES suppliers
(id) ON
DELETE CASCADE,
  FOREIGN KEY (created_by)
REFERENCES users
(id) ON
DELETE
SET NULL
,
  CONSTRAINT chk_interaction_type CHECK
(interaction_type IN
('visit', 'quote', 'note'))
);

INSERT IGNORE
INTO system_config
(key_name, key_value) VALUES
('MIN_PHOTOS_PER_ITEM', '1');
