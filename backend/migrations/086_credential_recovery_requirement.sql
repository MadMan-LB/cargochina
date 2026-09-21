-- MySQL-compatible column guard; preserve existing values on reruns.
SET @clms_086_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
              AND COLUMN_NAME = 'credential_recovery_required'),
    'SELECT 1',
    'ALTER TABLE users ADD COLUMN credential_recovery_required TINYINT(1) NOT NULL DEFAULT 0'
);
PREPARE clms_086_stmt FROM @clms_086_sql;
EXECUTE clms_086_stmt;
DEALLOCATE PREPARE clms_086_stmt;
