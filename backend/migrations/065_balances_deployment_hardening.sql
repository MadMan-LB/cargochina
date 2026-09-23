-- Migration 065: Harden live balances/deposit deployment.
-- Safe to run repeatedly. It repairs the balances ledger schema and ensures
-- existing custom sidebar settings include the Balances page for finance roles.

CREATE TABLE IF NOT EXISTS balance_transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  party_type ENUM('customer', 'supplier') NOT NULL,
  party_id INT UNSIGNED NOT NULL,
  order_id INT UNSIGNED NULL,
  order_reference VARCHAR(100) NULL,
  transaction_type ENUM('payment_received', 'payment_sent', 'deposit', 'invoice', 'adjustment', 'refund', 'other') NOT NULL,
  direction ENUM('increase_balance', 'reduce_balance') NOT NULL DEFAULT 'reduce_balance',
  amount DECIMAL(12,4) NOT NULL,
  currency ENUM('USD', 'RMB') NOT NULL DEFAULT 'USD',
  payment_method VARCHAR(50) NULL,
  payment_account_label VARCHAR(150) NULL,
  payment_account_value VARCHAR(255) NULL,
  payment_account_qr_path VARCHAR(255) NULL,
  reference_number VARCHAR(100) NULL,
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  transaction_date DATE NOT NULL,
  source_table VARCHAR(64) NULL,
  source_id INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_balance_tx_party (party_type, party_id),
  INDEX idx_balance_tx_order (order_id),
  INDEX idx_balance_tx_date (transaction_date),
  INDEX idx_balance_tx_currency (currency),
  INDEX idx_balance_tx_reference (reference_number),
  INDEX idx_balance_tx_source (source_table, source_id)
);

SET @m065_col_count := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'balance_transactions' AND COLUMN_NAME = 'order_id'
);
SET @m065_sql := IF(@m065_col_count = 0, 'ALTER TABLE balance_transactions ADD COLUMN order_id INT UNSIGNED NULL AFTER party_id', 'SELECT 1');
PREPARE m065_stmt FROM @m065_sql;
EXECUTE m065_stmt;
DEALLOCATE PREPARE m065_stmt;

SET @m065_col_count := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'balance_transactions' AND COLUMN_NAME = 'order_reference'
);
SET @m065_sql := IF(@m065_col_count = 0, 'ALTER TABLE balance_transactions ADD COLUMN order_reference VARCHAR(100) NULL AFTER order_id', 'SELECT 1');
PREPARE m065_stmt FROM @m065_sql;
EXECUTE m065_stmt;
DEALLOCATE PREPARE m065_stmt;

SET @m065_col_count := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'balance_transactions' AND COLUMN_NAME = 'payment_account_label'
);
SET @m065_sql := IF(@m065_col_count = 0, 'ALTER TABLE balance_transactions ADD COLUMN payment_account_label VARCHAR(150) NULL AFTER payment_method', 'SELECT 1');
PREPARE m065_stmt FROM @m065_sql;
EXECUTE m065_stmt;
DEALLOCATE PREPARE m065_stmt;

SET @m065_col_count := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'balance_transactions' AND COLUMN_NAME = 'payment_account_value'
);
SET @m065_sql := IF(@m065_col_count = 0, 'ALTER TABLE balance_transactions ADD COLUMN payment_account_value VARCHAR(255) NULL AFTER payment_account_label', 'SELECT 1');
PREPARE m065_stmt FROM @m065_sql;
EXECUTE m065_stmt;
DEALLOCATE PREPARE m065_stmt;

SET @m065_col_count := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'balance_transactions' AND COLUMN_NAME = 'payment_account_qr_path'
);
SET @m065_sql := IF(@m065_col_count = 0, 'ALTER TABLE balance_transactions ADD COLUMN payment_account_qr_path VARCHAR(255) NULL AFTER payment_account_value', 'SELECT 1');
PREPARE m065_stmt FROM @m065_sql;
EXECUTE m065_stmt;
DEALLOCATE PREPARE m065_stmt;

SET @m065_idx_count := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'balance_transactions' AND INDEX_NAME = 'idx_balance_tx_order'
);
SET @m065_sql := IF(@m065_idx_count = 0, 'ALTER TABLE balance_transactions ADD INDEX idx_balance_tx_order (order_id)', 'SELECT 1');
PREPARE m065_stmt FROM @m065_sql;
EXECUTE m065_stmt;
DEALLOCATE PREPARE m065_stmt;

SET @m065_type_check_count := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'balance_transactions'
    AND CONSTRAINT_NAME = 'chk_balance_tx_type'
    AND CONSTRAINT_TYPE = 'CHECK'
);
SET @m065_sql := IF(
  @m065_type_check_count > 0,
  IF(
    LOCATE('MariaDB', VERSION()) > 0,
    'ALTER TABLE balance_transactions DROP CONSTRAINT chk_balance_tx_type',
    'ALTER TABLE balance_transactions DROP CHECK chk_balance_tx_type'
  ),
  'SELECT 1'
);
PREPARE m065_stmt FROM @m065_sql;
EXECUTE m065_stmt;
DEALLOCATE PREPARE m065_stmt;

ALTER TABLE balance_transactions
  MODIFY COLUMN party_type ENUM('customer', 'supplier') NOT NULL,
  MODIFY COLUMN transaction_type ENUM('payment_received', 'payment_sent', 'deposit', 'invoice', 'adjustment', 'refund', 'other') NOT NULL,
  MODIFY COLUMN direction ENUM('increase_balance', 'reduce_balance') NOT NULL DEFAULT 'reduce_balance',
  MODIFY COLUMN currency ENUM('USD', 'RMB') NOT NULL DEFAULT 'USD';

-- Sidebar JSON is updated by the PHP migration runner after SQL completes.
-- Raw phpMyAdmin execution cannot apply the sidebar change by itself.
SELECT 1;
