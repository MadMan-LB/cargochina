-- Migration 063: Snapshot selected account details on balance transactions.
-- Rollback: ALTER TABLE balance_transactions DROP COLUMN payment_account_qr_path, DROP COLUMN payment_account_value, DROP COLUMN payment_account_label;

SET @m063_label = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'balance_transactions'
    AND COLUMN_NAME = 'payment_account_label'
);
SET @m063_sql = IF(
  @m063_label = 0,
  'ALTER TABLE balance_transactions ADD COLUMN payment_account_label VARCHAR(150) NULL AFTER payment_method',
  'SELECT 1'
);
PREPARE m063_stmt FROM @m063_sql;
EXECUTE m063_stmt;
DEALLOCATE PREPARE m063_stmt;

SET @m063_value = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'balance_transactions'
    AND COLUMN_NAME = 'payment_account_value'
);
SET @m063_sql = IF(
  @m063_value = 0,
  'ALTER TABLE balance_transactions ADD COLUMN payment_account_value VARCHAR(255) NULL AFTER payment_account_label',
  'SELECT 1'
);
PREPARE m063_stmt FROM @m063_sql;
EXECUTE m063_stmt;
DEALLOCATE PREPARE m063_stmt;

SET @m063_qr = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'balance_transactions'
    AND COLUMN_NAME = 'payment_account_qr_path'
);
SET @m063_sql = IF(
  @m063_qr = 0,
  'ALTER TABLE balance_transactions ADD COLUMN payment_account_qr_path VARCHAR(255) NULL AFTER payment_account_value',
  'SELECT 1'
);
PREPARE m063_stmt FROM @m063_sql;
EXECUTE m063_stmt;
DEALLOCATE PREPARE m063_stmt;
