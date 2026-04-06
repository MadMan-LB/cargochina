-- Add structured supplier payment account snapshot fields for recorded payments.
-- Idempotent for mixed environments.

SET @m057_label = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'supplier_payments'
      AND COLUMN_NAME = 'payment_account_label'
);
SET @m057_sql = IF(
    @m057_label = 0,
    'ALTER TABLE supplier_payments ADD COLUMN payment_account_label VARCHAR(150) NULL AFTER payment_channel',
    'SELECT 1'
);
PREPARE m057_stmt FROM @m057_sql;
EXECUTE m057_stmt;
DEALLOCATE PREPARE m057_stmt;

SET @m057_value = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'supplier_payments'
      AND COLUMN_NAME = 'payment_account_value'
);
SET @m057_sql = IF(
    @m057_value = 0,
    'ALTER TABLE supplier_payments ADD COLUMN payment_account_value VARCHAR(255) NULL AFTER payment_account_label',
    'SELECT 1'
);
PREPARE m057_stmt FROM @m057_sql;
EXECUTE m057_stmt;
DEALLOCATE PREPARE m057_stmt;

SET @m057_qr = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'supplier_payments'
      AND COLUMN_NAME = 'payment_account_qr_path'
);
SET @m057_sql = IF(
    @m057_qr = 0,
    'ALTER TABLE supplier_payments ADD COLUMN payment_account_qr_path VARCHAR(255) NULL AFTER payment_account_value',
    'SELECT 1'
);
PREPARE m057_stmt FROM @m057_sql;
EXECUTE m057_stmt;
DEALLOCATE PREPARE m057_stmt;
