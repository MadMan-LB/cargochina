-- CLMS Migration 074: Multiple audited operational cost lines on draft orders.
-- Accounting treatment defaults to informational because no approved rule links
-- these costs to party ledgers, landed cost, or receiving valuation.
-- Rollback:
--   DROP TABLE IF EXISTS draft_order_cost_history;
--   DROP TABLE IF EXISTS draft_order_costs;
--   DROP TABLE IF EXISTS draft_order_cost_types;

CREATE TABLE IF NOT EXISTS draft_order_cost_types
(
  code VARCHAR(40) PRIMARY KEY,
  label_en VARCHAR(120) NOT NULL,
  label_zh VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0
);

INSERT INTO draft_order_cost_types (code, label_en, label_zh, sort_order) VALUES
('pallet', 'Pallet', '托盘费', 10),
('transportation', 'Transportation', '运输费', 20),
('loading_unloading', 'Loading / unloading', '装卸费', 30),
('handling', 'Handling', '操作费', 40),
('storage', 'Storage', '仓储费', 50),
('insurance', 'Insurance', '保险费', 60),
('customs', 'Customs-related', '报关相关费用', 70),
('other', 'Other', '其他', 100)
ON DUPLICATE KEY UPDATE label_en=VALUES(label_en), label_zh=VALUES(label_zh), sort_order=VALUES(sort_order);

CREATE TABLE IF NOT EXISTS draft_order_costs
(
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  cost_type_code VARCHAR(40) NOT NULL,
  description_en VARCHAR(500) NULL,
  description_zh VARCHAR(500) NULL,
  amount DECIMAL(18,4) NOT NULL,
  currency VARCHAR(10) NOT NULL,
  exchange_rate DECIMAL(18,8) NOT NULL DEFAULT 1,
  base_currency VARCHAR(10) NOT NULL,
  base_amount DECIMAL(18,4) NOT NULL,
  supplier_id INT UNSIGNED NULL,
  service_provider VARCHAR(255) NULL,
  responsible_payer VARCHAR(20) NOT NULL DEFAULT 'company',
  allocation_method VARCHAR(20) NOT NULL DEFAULT 'none',
  accounting_treatment VARCHAR(30) NOT NULL DEFAULT 'informational',
  notes TEXT NULL,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  CONSTRAINT fk_draft_cost_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_draft_cost_type FOREIGN KEY (cost_type_code) REFERENCES draft_order_cost_types(code),
  CONSTRAINT fk_draft_cost_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
  CONSTRAINT fk_draft_cost_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_draft_cost_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_draft_cost_order (order_id, is_deleted),
  INDEX idx_draft_cost_supplier (supplier_id)
);

CREATE TABLE IF NOT EXISTS draft_order_cost_history
(
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cost_id BIGINT UNSIGNED NOT NULL,
  order_id INT UNSIGNED NOT NULL,
  action VARCHAR(20) NOT NULL,
  old_value_json LONGTEXT NULL,
  new_value_json LONGTEXT NULL,
  changed_by INT UNSIGNED NULL,
  changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_draft_cost_history_cost (cost_id, changed_at),
  INDEX idx_draft_cost_history_order (order_id, changed_at),
  CONSTRAINT fk_draft_cost_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
);
