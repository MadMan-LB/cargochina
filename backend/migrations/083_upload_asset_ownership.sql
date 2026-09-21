-- New uploads retain ownership before they are attached to a business record.
CREATE TABLE IF NOT EXISTS upload_assets (
    -- Full Unicode path is retained; the 32-byte digest fits the 767-byte key limit.
    path VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    path_sha256 BINARY(32) NOT NULL PRIMARY KEY,
    uploader_user_id INT UNSIGNED NOT NULL,
    byte_size BIGINT UNSIGNED NOT NULL,
    content_sha256 CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_upload_assets_path (path(128)),
    INDEX idx_upload_assets_owner (uploader_user_id),
    INDEX idx_upload_assets_created (created_at),
    CONSTRAINT fk_upload_assets_user FOREIGN KEY (uploader_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Upgrade an already-installed version without shortening or replacing paths.
SET @clms_083_upgrade = NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='upload_assets' AND COLUMN_NAME='path_sha256'
);
SET @clms_083_sql = IF(@clms_083_upgrade,
    'ALTER TABLE upload_assets ADD COLUMN path_sha256 BINARY(32) NULL', 'SELECT 1');
PREPARE clms_083_stmt FROM @clms_083_sql;
EXECUTE clms_083_stmt;
DEALLOCATE PREPARE clms_083_stmt;

-- This also resumes a deployment interrupted after the ADD COLUMN.
UPDATE upload_assets SET path_sha256=UNHEX(SHA2(path,256)) WHERE path_sha256 IS NULL;
SET @clms_083_upgrade = NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='upload_assets'
      AND INDEX_NAME='PRIMARY' AND COLUMN_NAME='path_sha256'
);
SET @clms_083_sql = IF(@clms_083_upgrade,
    'ALTER TABLE upload_assets DROP PRIMARY KEY, MODIFY path_sha256 BINARY(32) NOT NULL, ADD PRIMARY KEY(path_sha256), ADD INDEX idx_upload_assets_path(path(128))',
    'SELECT 1');
PREPARE clms_083_stmt FROM @clms_083_sql;
EXECUTE clms_083_stmt;
DEALLOCATE PREPARE clms_083_stmt;

-- Single-statement triggers work in phpMyAdmin and the migration runner.
-- Run the entire migration during a maintenance window (TRIGGER privilege required).
DROP TRIGGER IF EXISTS clms_upload_assets_path_insert;
CREATE TRIGGER clms_upload_assets_path_insert BEFORE INSERT ON upload_assets
FOR EACH ROW SET NEW.path_sha256=UNHEX(SHA2(NEW.path,256));
DROP TRIGGER IF EXISTS clms_upload_assets_path_update;
CREATE TRIGGER clms_upload_assets_path_update BEFORE UPDATE ON upload_assets
FOR EACH ROW SET NEW.path_sha256=UNHEX(SHA2(NEW.path,256));
