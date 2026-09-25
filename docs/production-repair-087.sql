-- Production repair: migration 087 pinned to clms, including every DDL target.
-- Generated from backend/migrations/087_recycle_bin.sql; no cargo/history is deleted.
-- Back up clms first; run the entire file during a brief pause in staff writes.
-- ALTER TABLE can lock these tables on MySQL 5.5. Requires ALTER/INDEX, not SUPER.
-- Recoverable draft headers. MySQL 5.5 compatible, rerunnable, no triggers or SUPER privilege.
SET @rb_exists=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='clms' AND TABLE_NAME='procurement_drafts' AND COLUMN_NAME='delete_generation');
SET @rb_sql=IF(@rb_exists=0,'ALTER TABLE clms.procurement_drafts ADD COLUMN delete_generation INT UNSIGNED NOT NULL DEFAULT 0','SELECT 1');
PREPARE rb_stmt FROM @rb_sql;
EXECUTE rb_stmt;
DEALLOCATE PREPARE rb_stmt;
SET @rb_exists=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='clms' AND TABLE_NAME='shipment_drafts' AND COLUMN_NAME='delete_generation');
SET @rb_sql=IF(@rb_exists=0,'ALTER TABLE clms.shipment_drafts ADD COLUMN delete_generation INT UNSIGNED NOT NULL DEFAULT 0','SELECT 1');
PREPARE rb_stmt FROM @rb_sql;
EXECUTE rb_stmt;
DEALLOCATE PREPARE rb_stmt;
SET @rb_exists=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='clms' AND TABLE_NAME='procurement_drafts' AND COLUMN_NAME='deleted_at');
SET @rb_sql=IF(@rb_exists=0,'ALTER TABLE clms.procurement_drafts ADD COLUMN deleted_at DATETIME NULL','SELECT 1');
PREPARE rb_stmt FROM @rb_sql;
EXECUTE rb_stmt;
DEALLOCATE PREPARE rb_stmt;
SET @rb_exists=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='clms' AND TABLE_NAME='procurement_drafts' AND COLUMN_NAME='deleted_by');
SET @rb_sql=IF(@rb_exists=0,'ALTER TABLE clms.procurement_drafts ADD COLUMN deleted_by INT UNSIGNED NULL','SELECT 1');
PREPARE rb_stmt FROM @rb_sql;
EXECUTE rb_stmt;
DEALLOCATE PREPARE rb_stmt;
SET @rb_exists=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='clms' AND TABLE_NAME='procurement_drafts' AND COLUMN_NAME='delete_reason');
SET @rb_sql=IF(@rb_exists=0,'ALTER TABLE clms.procurement_drafts ADD COLUMN delete_reason VARCHAR(500) NULL','SELECT 1');
PREPARE rb_stmt FROM @rb_sql;
EXECUTE rb_stmt;
DEALLOCATE PREPARE rb_stmt;
SET @rb_exists=(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='clms' AND TABLE_NAME='procurement_drafts' AND INDEX_NAME='idx_recycle_deleted');
SET @rb_sql=IF(@rb_exists=0,'CREATE INDEX idx_recycle_deleted ON clms.procurement_drafts(deleted_at,id)','SELECT 1');
PREPARE rb_stmt FROM @rb_sql;
EXECUTE rb_stmt;
DEALLOCATE PREPARE rb_stmt;
SET @rb_exists=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='clms' AND TABLE_NAME='shipment_drafts' AND COLUMN_NAME='deleted_at');
SET @rb_sql=IF(@rb_exists=0,'ALTER TABLE clms.shipment_drafts ADD COLUMN deleted_at DATETIME NULL','SELECT 1');
PREPARE rb_stmt FROM @rb_sql;
EXECUTE rb_stmt;
DEALLOCATE PREPARE rb_stmt;
SET @rb_exists=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='clms' AND TABLE_NAME='shipment_drafts' AND COLUMN_NAME='deleted_by');
SET @rb_sql=IF(@rb_exists=0,'ALTER TABLE clms.shipment_drafts ADD COLUMN deleted_by INT UNSIGNED NULL','SELECT 1');
PREPARE rb_stmt FROM @rb_sql;
EXECUTE rb_stmt;
DEALLOCATE PREPARE rb_stmt;
SET @rb_exists=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='clms' AND TABLE_NAME='shipment_drafts' AND COLUMN_NAME='delete_reason');
SET @rb_sql=IF(@rb_exists=0,'ALTER TABLE clms.shipment_drafts ADD COLUMN delete_reason VARCHAR(500) NULL','SELECT 1');
PREPARE rb_stmt FROM @rb_sql;
EXECUTE rb_stmt;
DEALLOCATE PREPARE rb_stmt;
SET @rb_exists=(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='clms' AND TABLE_NAME='shipment_drafts' AND INDEX_NAME='idx_recycle_deleted');
SET @rb_sql=IF(@rb_exists=0,'CREATE INDEX idx_recycle_deleted ON clms.shipment_drafts(deleted_at,id)','SELECT 1');
PREPARE rb_stmt FROM @rb_sql;
EXECUTE rb_stmt;
DEALLOCATE PREPARE rb_stmt;

SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='clms' AND TABLE_NAME IN ('procurement_drafts','shipment_drafts') AND COLUMN_NAME IN ('deleted_at','deleted_by','delete_reason','delete_generation') ORDER BY TABLE_NAME,COLUMN_NAME;
