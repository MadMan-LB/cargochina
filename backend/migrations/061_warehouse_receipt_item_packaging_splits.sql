-- CLMS Migration 061: Receiving item packaging split lines
-- Keeps existing warehouse_receipt_items totals as aggregates while preserving
-- per-item carton/pieces-per-carton split lines.
-- Rollback:
--   DROP TABLE warehouse_receipt_item_splits;

CREATE TABLE IF NOT EXISTS warehouse_receipt_item_splits
(
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_item_id INT UNSIGNED NOT NULL,
  line_no INT UNSIGNED NOT NULL DEFAULT 1,
  cartons DECIMAL(12,4) NULL,
  pieces_per_carton DECIMAL(12,4) NULL,
  quantity DECIMAL(12,4) NULL,
  unit_price DECIMAL(12,4) NULL,
  total_amount DECIMAL(12,4) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_receipt_item_splits_item
    FOREIGN KEY (receipt_item_id) REFERENCES warehouse_receipt_items(id)
    ON DELETE CASCADE,
  INDEX idx_receipt_item_splits_item (receipt_item_id),
  INDEX idx_receipt_item_splits_line (receipt_item_id, line_no)
);
