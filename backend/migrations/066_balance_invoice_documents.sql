-- Migration 066: Allow invoice ledger transactions for printable balance documents.
-- Rollback: ALTER TABLE balance_transactions DROP CONSTRAINT chk_balance_tx_type;
--   ALTER TABLE balance_transactions ADD CONSTRAINT chk_balance_tx_type
--   CHECK (transaction_type IN ('payment_received', 'payment_sent', 'deposit', 'adjustment', 'refund', 'other'));

SET @m066_has_balance_transactions := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'balance_transactions'
);

SET @m066_has_type_check := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'balance_transactions'
    AND CONSTRAINT_NAME = 'chk_balance_tx_type'
    AND CONSTRAINT_TYPE = 'CHECK'
);

SET @m066_sql := IF(
  @m066_has_balance_transactions > 0 AND @m066_has_type_check > 0,
  'ALTER TABLE balance_transactions DROP CONSTRAINT chk_balance_tx_type',
  'SELECT 1'
);
PREPARE m066_stmt FROM @m066_sql;
EXECUTE m066_stmt;
DEALLOCATE PREPARE m066_stmt;

SET @m066_sql := IF(
  @m066_has_balance_transactions > 0,
  'ALTER TABLE balance_transactions ADD CONSTRAINT chk_balance_tx_type CHECK (transaction_type IN (''payment_received'', ''payment_sent'', ''deposit'', ''invoice'', ''adjustment'', ''refund'', ''other''))',
  'SELECT 1'
);
PREPARE m066_stmt FROM @m066_sql;
EXECUTE m066_stmt;
DEALLOCATE PREPARE m066_stmt;
