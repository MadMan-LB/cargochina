-- Migration 065: Harden live balances/deposit deployment.
-- Safe to run repeatedly. It repairs the balances ledger schema and ensures
-- existing custom sidebar settings include the Balances page for finance roles.

CREATE TABLE IF NOT EXISTS balance_transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  party_type VARCHAR(20) NOT NULL,
  party_id INT UNSIGNED NOT NULL,
  order_id INT UNSIGNED NULL,
  order_reference VARCHAR(100) NULL,
  transaction_type VARCHAR(40) NOT NULL,
  direction VARCHAR(30) NOT NULL DEFAULT 'reduce_balance',
  amount DECIMAL(12,4) NOT NULL,
  currency VARCHAR(10) NOT NULL DEFAULT 'USD',
  payment_method VARCHAR(50) NULL,
  payment_account_label VARCHAR(150) NULL,
  payment_account_value VARCHAR(255) NULL,
  payment_account_qr_path VARCHAR(255) NULL,
  reference_number VARCHAR(100) NULL,
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  transaction_date DATE NOT NULL,
  source_table VARCHAR(64) NULL,
  source_id INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_balance_tx_party (party_type, party_id),
  INDEX idx_balance_tx_order (order_id),
  INDEX idx_balance_tx_date (transaction_date),
  INDEX idx_balance_tx_currency (currency),
  INDEX idx_balance_tx_reference (reference_number),
  INDEX idx_balance_tx_source (source_table, source_id)
);

SET @m065_col_count := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'balance_transactions' AND COLUMN_NAME = 'order_id'
);
SET @m065_sql := IF(@m065_col_count = 0, 'ALTER TABLE balance_transactions ADD COLUMN order_id INT UNSIGNED NULL AFTER party_id', 'SELECT 1');
PREPARE m065_stmt FROM @m065_sql;
EXECUTE m065_stmt;
DEALLOCATE PREPARE m065_stmt;

SET @m065_col_count := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'balance_transactions' AND COLUMN_NAME = 'order_reference'
);
SET @m065_sql := IF(@m065_col_count = 0, 'ALTER TABLE balance_transactions ADD COLUMN order_reference VARCHAR(100) NULL AFTER order_id', 'SELECT 1');
PREPARE m065_stmt FROM @m065_sql;
EXECUTE m065_stmt;
DEALLOCATE PREPARE m065_stmt;

SET @m065_col_count := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'balance_transactions' AND COLUMN_NAME = 'payment_account_label'
);
SET @m065_sql := IF(@m065_col_count = 0, 'ALTER TABLE balance_transactions ADD COLUMN payment_account_label VARCHAR(150) NULL AFTER payment_method', 'SELECT 1');
PREPARE m065_stmt FROM @m065_sql;
EXECUTE m065_stmt;
DEALLOCATE PREPARE m065_stmt;

SET @m065_col_count := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'balance_transactions' AND COLUMN_NAME = 'payment_account_value'
);
SET @m065_sql := IF(@m065_col_count = 0, 'ALTER TABLE balance_transactions ADD COLUMN payment_account_value VARCHAR(255) NULL AFTER payment_account_label', 'SELECT 1');
PREPARE m065_stmt FROM @m065_sql;
EXECUTE m065_stmt;
DEALLOCATE PREPARE m065_stmt;

SET @m065_col_count := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'balance_transactions' AND COLUMN_NAME = 'payment_account_qr_path'
);
SET @m065_sql := IF(@m065_col_count = 0, 'ALTER TABLE balance_transactions ADD COLUMN payment_account_qr_path VARCHAR(255) NULL AFTER payment_account_value', 'SELECT 1');
PREPARE m065_stmt FROM @m065_sql;
EXECUTE m065_stmt;
DEALLOCATE PREPARE m065_stmt;

SET @m065_idx_count := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'balance_transactions' AND INDEX_NAME = 'idx_balance_tx_order'
);
SET @m065_sql := IF(@m065_idx_count = 0, 'ALTER TABLE balance_transactions ADD INDEX idx_balance_tx_order (order_id)', 'SELECT 1');
PREPARE m065_stmt FROM @m065_sql;
EXECUTE m065_stmt;
DEALLOCATE PREPARE m065_stmt;

SET @m065_type_check_count := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'balance_transactions'
    AND CONSTRAINT_NAME = 'chk_balance_tx_type'
    AND CONSTRAINT_TYPE = 'CHECK'
);
SET @m065_sql := IF(@m065_type_check_count > 0, 'ALTER TABLE balance_transactions DROP CONSTRAINT chk_balance_tx_type', 'SELECT 1');
PREPARE m065_stmt FROM @m065_sql;
EXECUTE m065_stmt;
DEALLOCATE PREPARE m065_stmt;

SET @m065_sidebar_defaults := JSON_OBJECT(
  'ChinaAdmin', JSON_ARRAY('dashboard', 'orders', 'pipeline', 'consolidation', 'containers', 'assign_container', 'expenses', 'financials', 'balances', 'hs_code_tax', 'calendar', 'warehouse_stock', 'procurement_drafts', 'downloads', 'suppliers', 'customers', 'products', 'notifications', 'notification_preferences'),
  'ChinaEmployee', JSON_ARRAY('dashboard', 'orders', 'balances', 'procurement_drafts', 'downloads', 'suppliers', 'products', 'notifications'),
  'LebanonAdmin', JSON_ARRAY('dashboard', 'pipeline', 'consolidation', 'containers', 'assign_container', 'expenses', 'financials', 'balances', 'hs_code_tax', 'calendar', 'warehouse_stock', 'downloads', 'notifications', 'notification_preferences'),
  'WarehouseStaff', JSON_ARRAY('dashboard', 'receiving', 'expenses', 'warehouse_stock', 'downloads', 'notifications'),
  'ContainersStaff', JSON_ARRAY('consolidation', 'containers', 'assign_container', 'warehouse_stock'),
  'FieldStaff', JSON_ARRAY('dashboard', 'suppliers', 'notifications')
);

INSERT INTO system_config (key_name, key_value)
SELECT 'ROLE_SIDEBAR_PAGES_JSON', @m065_sidebar_defaults
WHERE NOT EXISTS (
  SELECT 1 FROM system_config WHERE key_name = 'ROLE_SIDEBAR_PAGES_JSON'
);

UPDATE system_config
SET key_value = @m065_sidebar_defaults
WHERE key_name = 'ROLE_SIDEBAR_PAGES_JSON'
  AND NOT JSON_VALID(key_value);

SET @m065_sidebar_cfg := (
  SELECT key_value FROM system_config WHERE key_name = 'ROLE_SIDEBAR_PAGES_JSON' LIMIT 1
);

SET @m065_china_admin_pages := COALESCE(JSON_EXTRACT(@m065_sidebar_cfg, '$.ChinaAdmin'), JSON_EXTRACT(@m065_sidebar_defaults, '$.ChinaAdmin'));
SET @m065_china_admin_pages := IF(JSON_CONTAINS(@m065_china_admin_pages, JSON_QUOTE('balances')), @m065_china_admin_pages, JSON_ARRAY_APPEND(@m065_china_admin_pages, '$', 'balances'));

SET @m065_china_employee_pages := COALESCE(JSON_EXTRACT(@m065_sidebar_cfg, '$.ChinaEmployee'), JSON_EXTRACT(@m065_sidebar_defaults, '$.ChinaEmployee'));
SET @m065_china_employee_pages := IF(JSON_CONTAINS(@m065_china_employee_pages, JSON_QUOTE('balances')), @m065_china_employee_pages, JSON_ARRAY_APPEND(@m065_china_employee_pages, '$', 'balances'));

SET @m065_lebanon_admin_pages := COALESCE(JSON_EXTRACT(@m065_sidebar_cfg, '$.LebanonAdmin'), JSON_EXTRACT(@m065_sidebar_defaults, '$.LebanonAdmin'));
SET @m065_lebanon_admin_pages := IF(JSON_CONTAINS(@m065_lebanon_admin_pages, JSON_QUOTE('balances')), @m065_lebanon_admin_pages, JSON_ARRAY_APPEND(@m065_lebanon_admin_pages, '$', 'balances'));

UPDATE system_config
SET key_value = JSON_SET(
  key_value,
  '$.ChinaAdmin', JSON_EXTRACT(@m065_china_admin_pages, '$'),
  '$.ChinaEmployee', JSON_EXTRACT(@m065_china_employee_pages, '$'),
  '$.LebanonAdmin', JSON_EXTRACT(@m065_lebanon_admin_pages, '$')
)
WHERE key_name = 'ROLE_SIDEBAR_PAGES_JSON'
  AND JSON_VALID(key_value);
