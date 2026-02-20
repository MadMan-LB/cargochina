-- CLMS Migration 015: Item-level receiving
-- Per-item actuals, photos, condition, variance_detected
-- Rollback: DROP TABLE warehouse_receipt_item_photos, warehouse_receipt_items;

CREATE TABLE warehouse_receipt_items
(
  id INT
  UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_id INT UNSIGNED NOT NULL,
  order_item_id INT UNSIGNED NOT NULL,
  actual_cartons INT UNSIGNED NULL,
  actual_cbm DECIMAL
  (10,4) NULL,
  actual_weight DECIMAL
  (10,4) NULL,
  receipt_condition VARCHAR
  (20) NOT NULL DEFAULT 'good',
  variance_detected TINYINT
  (1) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY
  (receipt_id) REFERENCES warehouse_receipts
  (id) ON
  DELETE CASCADE,
  FOREIGN KEY (order_item_id)
  REFERENCES order_items
  (id) ON
  DELETE CASCADE,
  CONSTRAINT chk_receipt_item_condition CHECK
  (receipt_condition IN
  ('good','damaged','partial'))
);

  CREATE TABLE warehouse_receipt_item_photos
  (
    id INT
    UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_item_id INT UNSIGNED NOT NULL,
  file_path VARCHAR
    (500) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY
    (receipt_item_id) REFERENCES warehouse_receipt_items
    (id) ON
    DELETE CASCADE
);

    CREATE INDEX idx_receipt_items_receipt ON warehouse_receipt_items(receipt_id);
