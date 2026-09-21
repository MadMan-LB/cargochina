CREATE TABLE IF NOT EXISTS credential_escrow (
 user_id INT NOT NULL PRIMARY KEY,
 envelope LONGTEXT NOT NULL CHECK(JSON_VALID(envelope)),
 credential_fingerprint CHAR(64) NOT NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS owner_incidents (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 fingerprint CHAR(64) NOT NULL UNIQUE,
 severity VARCHAR(10) NOT NULL,
 category VARCHAR(40) NOT NULL,
 workflow VARCHAR(80) NOT NULL,
 action_name VARCHAR(120) NOT NULL,
 error_code VARCHAR(80) NOT NULL,
 safe_message VARCHAR(1000) NOT NULL,
 first_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 -- MySQL 5.5 allows only one automatic CURRENT_TIMESTAMP column per table.
 last_seen TIMESTAMP NULL DEFAULT NULL,
 occurrences BIGINT UNSIGNED NOT NULL DEFAULT 1,
 status VARCHAR(20) NOT NULL DEFAULT 'New',
 owner_notes VARCHAR(2000) NOT NULL DEFAULT '',
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 INDEX incident_status_severity(status,severity,last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS owner_incident_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 incident_id BIGINT UNSIGNED NOT NULL,
 request_id CHAR(32) NOT NULL UNIQUE,
 user_id INT NULL,
 username VARCHAR(255) NOT NULL DEFAULT '',
 display_name VARCHAR(255) NOT NULL DEFAULT '',
 roles VARCHAR(255) NOT NULL DEFAULT '',
 entity_type VARCHAR(80) NOT NULL DEFAULT '',
 entity_id BIGINT UNSIGNED NULL,
 rollback_state VARCHAR(30) NOT NULL DEFAULT 'unknown',
 retry_result VARCHAR(30) NOT NULL DEFAULT 'not_observed',
 context_summary VARCHAR(500) NOT NULL DEFAULT '',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX incident_events(incident_id,created_at),
 INDEX incident_user(user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- MySQL-compatible column guard; preserve existing values on reruns.
SET @clms_085_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
              AND COLUMN_NAME = 'last_login_at'),
    'SELECT 1',
    'ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL'
);
PREPARE clms_085_stmt FROM @clms_085_sql;
EXECUTE clms_085_stmt;
DEALLOCATE PREPARE clms_085_stmt;

-- Initialize last_seen without a second automatic timestamp declaration.
DROP TRIGGER IF EXISTS clms_owner_incidents_first_seen;
CREATE TRIGGER clms_owner_incidents_first_seen BEFORE INSERT ON owner_incidents
FOR EACH ROW SET NEW.last_seen=COALESCE(NEW.last_seen,CURRENT_TIMESTAMP);
