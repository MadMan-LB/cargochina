-- CLMS Migration 073: Structured, localized item classification.
-- Existing order item IDs and legacy copy_normal_goods values are unchanged.
-- Rollback:
--   DROP TABLE IF EXISTS item_classification_history;
--   DROP TABLE IF EXISTS item_classifications;
--   DROP TABLE IF EXISTS item_types;

CREATE TABLE IF NOT EXISTS item_types
(
  code VARCHAR(30) PRIMARY KEY,
  label_en VARCHAR(100) NOT NULL,
  label_zh VARCHAR(100) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1
);

INSERT INTO item_types (code, label_en, label_zh, sort_order, is_active) VALUES
('normal', 'Normal goods', '普通货物', 10, 1),
('replica', 'Copy / replica goods', '仿牌 / 复制品', 20, 1),
('cosmetics', 'Cosmetics', '化妆品', 30, 1),
('branded', 'Branded goods', '品牌货物', 40, 1),
('food', 'Food', '食品', 50, 1),
('dangerous', 'Dangerous goods', '危险品', 60, 1),
('other', 'Other', '其他', 90, 1),
('unclassified', 'Needs classification', '待分类', 100, 1)
ON DUPLICATE KEY UPDATE label_en=VALUES(label_en), label_zh=VALUES(label_zh), sort_order=VALUES(sort_order);

CREATE TABLE IF NOT EXISTS item_classifications
(
  entity_type VARCHAR(40) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  item_type_code VARCHAR(30) NOT NULL DEFAULT 'unclassified',
  confidence DECIMAL(5,4) NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'suggested',
  is_confirmed TINYINT(1) NOT NULL DEFAULT 0,
  confirmed_by INT UNSIGNED NULL,
  confirmed_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (entity_type, entity_id),
  INDEX idx_item_classification_type (item_type_code, entity_type),
  CONSTRAINT fk_item_classification_type FOREIGN KEY (item_type_code) REFERENCES item_types(code),
  CONSTRAINT fk_item_classification_user FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS item_classification_history
(
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(40) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  old_type_code VARCHAR(30) NULL,
  new_type_code VARCHAR(30) NOT NULL,
  confidence DECIMAL(5,4) NULL,
  source VARCHAR(20) NOT NULL,
  changed_by INT UNSIGNED NULL,
  changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_item_classification_history_entity (entity_type, entity_id, changed_at),
  CONSTRAINT fk_item_classification_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Safe legacy mapping. A previously selected legacy value is treated as a
-- confirmed user classification; blank/unknown rows remain explicitly absent.
INSERT IGNORE INTO item_classifications
  (entity_type, entity_id, item_type_code, confidence, source, is_confirmed)
SELECT 'order_item', id,
  CASE LOWER(TRIM(copy_normal_goods))
    WHEN 'normal' THEN 'normal'
    WHEN 'copy' THEN 'replica'
    WHEN 'dangerous' THEN 'dangerous'
    ELSE 'unclassified'
  END,
  CASE WHEN LOWER(TRIM(copy_normal_goods)) IN ('normal','copy','dangerous') THEN 1.0000 ELSE NULL END,
  'migrated',
  CASE WHEN LOWER(TRIM(copy_normal_goods)) IN ('normal','copy','dangerous') THEN 1 ELSE 0 END
FROM order_items
WHERE copy_normal_goods IS NOT NULL AND TRIM(copy_normal_goods) <> '';
