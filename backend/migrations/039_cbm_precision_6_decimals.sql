-- Migration 039: Increase CBM precision to 6 decimals (0.000001) for shipping accuracy
-- Rollback: MODIFY back to DECIMAL(10,4) if needed

ALTER TABLE order_items MODIFY COLUMN declared_cbm DECIMAL
(12,6) NOT NULL;
ALTER TABLE order_items MODIFY COLUMN declared_weight DECIMAL
(12,4) NOT NULL;

ALTER TABLE order_template_items MODIFY COLUMN declared_cbm DECIMAL
(12,6) NULL;
ALTER TABLE order_template_items MODIFY COLUMN declared_weight DECIMAL
(12,4) NULL;

ALTER TABLE products MODIFY COLUMN cbm DECIMAL
(12,6) NOT NULL DEFAULT 0;
ALTER TABLE products MODIFY COLUMN weight DECIMAL
(12,4) NOT NULL DEFAULT 0;

ALTER TABLE warehouse_receipts MODIFY COLUMN actual_cbm DECIMAL
(12,6) NOT NULL DEFAULT 0;
ALTER TABLE warehouse_receipts MODIFY COLUMN actual_weight DECIMAL
(12,4) NOT NULL DEFAULT 0;

ALTER TABLE warehouse_receipt_items MODIFY COLUMN actual_cbm DECIMAL
(12,6) NULL;
ALTER TABLE warehouse_receipt_items MODIFY COLUMN actual_weight DECIMAL
(12,4) NULL;
