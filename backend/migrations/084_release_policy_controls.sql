-- Additive release controls; no historical business value is reconstructed.
-- MySQL-compatible column guard; preserve existing values on reruns.
SET @clms_084_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
              AND COLUMN_NAME = 'session_version'),
    'SELECT 1',
    'ALTER TABLE users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 0'
);
PREPARE clms_084_stmt FROM @clms_084_sql;
EXECUTE clms_084_stmt;
DEALLOCATE PREPARE clms_084_stmt;
CREATE TABLE IF NOT EXISTS historical_reconciliation_records (
 entity_type VARCHAR(30) NOT NULL,
 entity_id BIGINT UNSIGNED NOT NULL,
 issue_code VARCHAR(80) NOT NULL,
 -- MySQL 5.5 parses but does not enforce CHECK; it has no JSON functions.
 original_values LONGTEXT NOT NULL CHECK (JSON_VALID(original_values)),
 review_status VARCHAR(50) NOT NULL DEFAULT 'HISTORICAL_RECONCILIATION_REQUIRED',
 evidence_reference TEXT NULL,
 business_approver VARCHAR(100) NOT NULL DEFAULT 'Houssein',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 resolved_at DATETIME NULL,
 PRIMARY KEY(entity_type,entity_id,issue_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS retention_holds (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 scope_type VARCHAR(40) NOT NULL,
 scope_reference VARCHAR(255) NOT NULL,
 reason TEXT NOT NULL,
 approval_reference TEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 released_at DATETIME NULL,
 release_approval_reference TEXT NULL,
 -- Non-unique lookup prefix only: the complete reference remains stored and compared.
 INDEX idx_retention_hold_scope(scope_type,scope_reference(128),released_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
