-- CLMS Migration 075: Receiving retry/double-submit idempotency.
-- Existing receipts remain unchanged because the key is nullable.
-- Precheck before rollback: ensure no workflow relies on receiving_operation_id.
-- Rollback: ALTER TABLE warehouse_receipts DROP INDEX uq_receiving_operation_id, DROP COLUMN receiving_operation_id;

SET @m075_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='warehouse_receipts' AND COLUMN_NAME='receiving_operation_id');
SET @m075_sql := IF(@m075_col=0, 'ALTER TABLE warehouse_receipts ADD COLUMN receiving_operation_id VARCHAR(64) NULL', 'SELECT 1');
PREPARE m075_stmt FROM @m075_sql; EXECUTE m075_stmt; DEALLOCATE PREPARE m075_stmt;

SET @m075_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='warehouse_receipts' AND INDEX_NAME='uq_receiving_operation_id');
SET @m075_sql := IF(@m075_idx=0, 'CREATE UNIQUE INDEX uq_receiving_operation_id ON warehouse_receipts (receiving_operation_id)', 'SELECT 1');
PREPARE m075_stmt FROM @m075_sql; EXECUTE m075_stmt; DEALLOCATE PREPARE m075_stmt;
