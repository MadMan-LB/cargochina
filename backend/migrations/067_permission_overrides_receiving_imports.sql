-- CLMS Migration 067: User permission overrides and receiving Excel import history
-- Rollback:
--   DROP TABLE IF EXISTS receiving_excel_imports;
--   DROP TABLE IF EXISTS user_permission_overrides;

CREATE TABLE IF NOT EXISTS user_permission_overrides
(
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  permission_key VARCHAR(120) NOT NULL,
  is_allowed TINYINT(1) NOT NULL DEFAULT 1,
  notes VARCHAR(500) NULL,
  granted_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_permission_override (user_id, permission_key),
  INDEX idx_user_permission_override_user (user_id),
  INDEX idx_user_permission_override_permission (permission_key),
  CONSTRAINT fk_user_permission_override_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_user_permission_override_granted_by
    FOREIGN KEY (granted_by) REFERENCES users(id)
    ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS receiving_excel_imports
(
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  preview_token VARCHAR(64) NOT NULL UNIQUE,
  original_filename VARCHAR(255) NOT NULL,
  file_hash CHAR(64) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'previewed',
  row_count INT UNSIGNED NOT NULL DEFAULT 0,
  valid_count INT UNSIGNED NOT NULL DEFAULT 0,
  error_count INT UNSIGNED NOT NULL DEFAULT 0,
  preview_json LONGTEXT NULL,
  result_json LONGTEXT NULL,
  created_by INT UNSIGNED NULL,
  committed_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  committed_at TIMESTAMP NULL DEFAULT NULL,
  INDEX idx_receiving_excel_imports_created_by (created_by),
  INDEX idx_receiving_excel_imports_status (status),
  CONSTRAINT fk_receiving_excel_imports_created_by
    FOREIGN KEY (created_by) REFERENCES users(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_receiving_excel_imports_committed_by
    FOREIGN KEY (committed_by) REFERENCES users(id)
    ON DELETE SET NULL
);
