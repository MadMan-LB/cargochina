-- Migration 064: Link balance transactions to orders and support deposit transaction type.
-- Rollback: ALTER TABLE balance_transactions DROP FOREIGN KEY fk_balance_tx_order;
--   ALTER TABLE balance_transactions DROP COLUMN order_reference, DROP COLUMN order_id;

SET @has_order_id := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'balance_transactions'
    AND COLUMN_NAME = 'order_id'
);
SET @sql := IF(
  @has_order_id = 0,
  'ALTER TABLE balance_transactions ADD COLUMN order_id INT UNSIGNED NULL AFTER party_id',
  'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_order_reference := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'balance_transactions'
    AND COLUMN_NAME = 'order_reference'
);
SET @sql := IF(
  @has_order_reference = 0,
  'ALTER TABLE balance_transactions ADD COLUMN order_reference VARCHAR(100) NULL AFTER order_id',
  'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_order_index := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'balance_transactions'
    AND INDEX_NAME = 'idx_balance_tx_order'
);
SET @sql := IF(
  @has_order_index = 0,
  'ALTER TABLE balance_transactions ADD INDEX idx_balance_tx_order (order_id)',
  'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_order_fk := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'balance_transactions'
    AND CONSTRAINT_NAME = 'fk_balance_tx_order'
);
SET @sql := IF(
  @has_order_fk = 0,
  'ALTER TABLE balance_transactions ADD CONSTRAINT fk_balance_tx_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL',
  'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_type_check := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'balance_transactions'
    AND CONSTRAINT_NAME = 'chk_balance_tx_type'
    AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql := IF(
  @has_type_check > 0,
  'ALTER TABLE balance_transactions DROP CONSTRAINT chk_balance_tx_type',
  'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE balance_transactions
  ADD CONSTRAINT chk_balance_tx_type
  CHECK (transaction_type IN ('payment_received', 'payment_sent', 'deposit', 'invoice', 'adjustment', 'refund', 'other'));