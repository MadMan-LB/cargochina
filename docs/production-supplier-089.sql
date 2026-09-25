-- Additive MySQL 5.5 supplier recovery; rerunnable, no triggers or SUPER required.
-- Apply to the application database before enabling supplier deletion.
SET @rb_exists=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='clms' AND TABLE_NAME='suppliers' AND COLUMN_NAME='delete_generation');
SET @rb_sql=IF(@rb_exists=0,'ALTER TABLE `clms`.`suppliers` ADD COLUMN delete_generation INT UNSIGNED NOT NULL DEFAULT 0','SELECT 1');
PREPARE rb_stmt FROM @rb_sql;
EXECUTE rb_stmt;
DEALLOCATE PREPARE rb_stmt;
SET @rb_exists=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='clms' AND TABLE_NAME='suppliers' AND COLUMN_NAME='deleted_at');
SET @rb_sql=IF(@rb_exists=0,'ALTER TABLE `clms`.`suppliers` ADD COLUMN deleted_at DATETIME NULL','SELECT 1');
PREPARE rb_stmt FROM @rb_sql;
EXECUTE rb_stmt;
DEALLOCATE PREPARE rb_stmt;
SET @rb_exists=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='clms' AND TABLE_NAME='suppliers' AND COLUMN_NAME='deleted_by');
SET @rb_sql=IF(@rb_exists=0,'ALTER TABLE `clms`.`suppliers` ADD COLUMN deleted_by INT UNSIGNED NULL','SELECT 1');
PREPARE rb_stmt FROM @rb_sql;
EXECUTE rb_stmt;
DEALLOCATE PREPARE rb_stmt;
SET @rb_exists=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='clms' AND TABLE_NAME='suppliers' AND COLUMN_NAME='delete_reason');
SET @rb_sql=IF(@rb_exists=0,'ALTER TABLE `clms`.`suppliers` ADD COLUMN delete_reason VARCHAR(500) NULL','SELECT 1');
PREPARE rb_stmt FROM @rb_sql;
EXECUTE rb_stmt;
DEALLOCATE PREPARE rb_stmt;
SET @rb_exists=(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='clms' AND TABLE_NAME='suppliers' AND INDEX_NAME='idx_recycle_deleted');
SET @rb_sql=IF(@rb_exists=0,'CREATE INDEX idx_recycle_deleted ON `clms`.`suppliers`(deleted_at,id)','SELECT 1');
PREPARE rb_stmt FROM @rb_sql;
EXECUTE rb_stmt;
DEALLOCATE PREPARE rb_stmt;
