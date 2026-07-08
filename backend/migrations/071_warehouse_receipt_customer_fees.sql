-- CLMS Migration 071: Customer-facing warehouse receipt fees
-- Stores fees added during receiving, such as pallet fees, that should appear on customer exports.
-- Rollback: DROP TABLE warehouse_receipt_fees;

CREATE TABLE IF NOT EXISTS warehouse_receipt_fees
(
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_id INT UNSIGNED NOT NULL,
  order_id INT UNSIGNED NOT NULL,
  fee_label VARCHAR(160) NOT NULL,
  amount DECIMAL(12,4) NOT NULL DEFAULT 0,
  currency VARCHAR(10) NOT NULL DEFAULT 'USD',
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  CONSTRAINT fk_receipt_fees_receipt
    FOREIGN KEY (receipt_id) REFERENCES warehouse_receipts(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_receipt_fees_order
    FOREIGN KEY (order_id) REFERENCES orders(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_receipt_fees_user
    FOREIGN KEY (created_by) REFERENCES users(id)
    ON DELETE SET NULL,
  INDEX idx_receipt_fees_receipt (receipt_id),
  INDEX idx_receipt_fees_order (order_id)
);
