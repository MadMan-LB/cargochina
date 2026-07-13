-- CLMS Migration 077: Approved shipment accounting and canonical item-number reservations.
-- Additive only. Existing operational costs, receipt fees, orders, and item numbers
-- are not posted, renumbered, reset, or otherwise rewritten.
-- Rollback (only before matching code is active and after exporting any new data):
--   DROP TABLE IF EXISTS item_number_references;
--   DROP TABLE IF EXISTS item_number_reservations;
--   DROP TABLE IF EXISTS shipment_financial_entries;
--   ALTER TABLE draft_order_costs DROP INDEX uq_draft_cost_creation_key,
--     DROP COLUMN creation_idempotency_key, DROP COLUMN lock_version,
--     DROP COLUMN posting_status, DROP COLUMN finalized_at, DROP COLUMN rate_locked_at;
--   ALTER TABLE warehouse_receipt_fees DROP COLUMN posting_status;
--   ALTER TABLE orders DROP INDEX uq_orders_creation_idempotency,
--     DROP COLUMN creation_idempotency_key, DROP COLUMN lock_version;

CREATE TABLE IF NOT EXISTS shipment_financial_entries
(
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  source_type VARCHAR(40) NOT NULL,
  source_id BIGINT UNSIGNED NOT NULL,
  entry_role ENUM('customer_charge','shipment_expense') NOT NULL,
  generation INT UNSIGNED NOT NULL DEFAULT 1,
  entry_kind ENUM('primary','reversal','restoration') NOT NULL DEFAULT 'primary',
  posting_state ENUM('provisional','finalized','archived') NOT NULL DEFAULT 'provisional',
  amount DECIMAL(18,4) NOT NULL,
  currency VARCHAR(10) NOT NULL,
  exchange_rate DECIMAL(18,8) NOT NULL DEFAULT 1,
  base_currency VARCHAR(10) NOT NULL,
  base_amount DECIMAL(18,4) NOT NULL,
  description VARCHAR(500) NULL,
  idempotency_key VARCHAR(190) NOT NULL,
  reverses_entry_id BIGINT UNSIGNED NULL,
  archived_at DATETIME NULL,
  finalized_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_shipment_financial_idempotency (idempotency_key),
  UNIQUE KEY uq_shipment_financial_source_generation (source_type, source_id, entry_role, generation, entry_kind),
  INDEX idx_shipment_financial_order_state (order_id, posting_state, entry_role),
  INDEX idx_shipment_financial_customer_state (customer_id, posting_state, entry_role, currency),
  INDEX idx_shipment_financial_reversal (reverses_entry_id),
  CONSTRAINT fk_shipment_financial_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_shipment_financial_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_shipment_financial_reverses FOREIGN KEY (reverses_entry_id) REFERENCES shipment_financial_entries(id) ON DELETE RESTRICT,
  CONSTRAINT fk_shipment_financial_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_shipment_financial_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS item_number_reservations
(
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  display_item_no VARCHAR(150) NOT NULL,
  normalized_item_no VARCHAR(150) NOT NULL,
  shipping_prefix VARCHAR(100) NULL,
  supplier_id INT UNSIGNED NULL,
  supplier_sequence INT UNSIGNED NULL,
  item_sequence INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_item_number_customer_normalized (customer_id, normalized_item_no),
  INDEX idx_item_number_sequence (customer_id, shipping_prefix, supplier_id, supplier_sequence, item_sequence),
  CONSTRAINT fk_item_number_reservation_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_item_number_reservation_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
  CONSTRAINT fk_item_number_reservation_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS item_number_references
(
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reservation_id BIGINT UNSIGNED NOT NULL,
  order_id INT UNSIGNED NOT NULL,
  order_item_id INT UNSIGNED NULL,
  reference_type VARCHAR(30) NOT NULL,
  reference_key VARCHAR(190) NOT NULL,
  is_legacy TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_item_number_reference (reservation_id, reference_key),
  INDEX idx_item_number_reference_order (order_id, order_item_id),
  CONSTRAINT fk_item_number_reference_reservation FOREIGN KEY (reservation_id) REFERENCES item_number_reservations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_item_number_reference_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_item_number_reference_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE SET NULL
);

SET @m077_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders' AND COLUMN_NAME='creation_idempotency_key');
SET @m077_sql := IF(@m077_col=0, 'ALTER TABLE orders ADD COLUMN creation_idempotency_key VARCHAR(64) NULL', 'SELECT 1');
PREPARE m077_stmt FROM @m077_sql; EXECUTE m077_stmt; DEALLOCATE PREPARE m077_stmt;

SET @m077_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders' AND INDEX_NAME='uq_orders_creation_idempotency');
SET @m077_sql := IF(@m077_idx=0, 'CREATE UNIQUE INDEX uq_orders_creation_idempotency ON orders (creation_idempotency_key)', 'SELECT 1');
PREPARE m077_stmt FROM @m077_sql; EXECUTE m077_stmt; DEALLOCATE PREPARE m077_stmt;

SET @m077_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders' AND COLUMN_NAME='lock_version');
SET @m077_sql := IF(@m077_col=0, 'ALTER TABLE orders ADD COLUMN lock_version INT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE m077_stmt FROM @m077_sql; EXECUTE m077_stmt; DEALLOCATE PREPARE m077_stmt;

SET @m077_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='draft_order_costs' AND COLUMN_NAME='creation_idempotency_key');
SET @m077_sql := IF(@m077_col=0, 'ALTER TABLE draft_order_costs ADD COLUMN creation_idempotency_key VARCHAR(64) NULL', 'SELECT 1');
PREPARE m077_stmt FROM @m077_sql; EXECUTE m077_stmt; DEALLOCATE PREPARE m077_stmt;

SET @m077_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='draft_order_costs' AND INDEX_NAME='uq_draft_cost_creation_key');
SET @m077_sql := IF(@m077_idx=0, 'CREATE UNIQUE INDEX uq_draft_cost_creation_key ON draft_order_costs (creation_idempotency_key)', 'SELECT 1');
PREPARE m077_stmt FROM @m077_sql; EXECUTE m077_stmt; DEALLOCATE PREPARE m077_stmt;

SET @m077_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='draft_order_costs' AND COLUMN_NAME='lock_version');
SET @m077_sql := IF(@m077_col=0, 'ALTER TABLE draft_order_costs ADD COLUMN lock_version INT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE m077_stmt FROM @m077_sql; EXECUTE m077_stmt; DEALLOCATE PREPARE m077_stmt;

SET @m077_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='draft_order_costs' AND COLUMN_NAME='posting_status');
SET @m077_sql := IF(@m077_col=0, 'ALTER TABLE draft_order_costs ADD COLUMN posting_status VARCHAR(20) NOT NULL DEFAULT ''legacy_unposted''', 'SELECT 1');
PREPARE m077_stmt FROM @m077_sql; EXECUTE m077_stmt; DEALLOCATE PREPARE m077_stmt;

SET @m077_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='draft_order_costs' AND COLUMN_NAME='finalized_at');
SET @m077_sql := IF(@m077_col=0, 'ALTER TABLE draft_order_costs ADD COLUMN finalized_at DATETIME NULL', 'SELECT 1');
PREPARE m077_stmt FROM @m077_sql; EXECUTE m077_stmt; DEALLOCATE PREPARE m077_stmt;

SET @m077_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='draft_order_costs' AND COLUMN_NAME='rate_locked_at');
SET @m077_sql := IF(@m077_col=0, 'ALTER TABLE draft_order_costs ADD COLUMN rate_locked_at DATETIME NULL', 'SELECT 1');
PREPARE m077_stmt FROM @m077_sql; EXECUTE m077_stmt; DEALLOCATE PREPARE m077_stmt;

SET @m077_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='warehouse_receipt_fees' AND COLUMN_NAME='posting_status');
SET @m077_sql := IF(@m077_col=0, 'ALTER TABLE warehouse_receipt_fees ADD COLUMN posting_status VARCHAR(20) NOT NULL DEFAULT ''legacy_unposted''', 'SELECT 1');
PREPARE m077_stmt FROM @m077_sql; EXECUTE m077_stmt; DEALLOCATE PREPARE m077_stmt;
