-- CLMS Migration 009: Item capture enhancements (Excel-aligned)
-- Add item_no, shipping_code, cartons, qty_per_carton, unit_price, total_amount, notes, image_paths
-- Rollback: ALTER TABLE order_items DROP COLUMN item_no, DROP COLUMN shipping_code, DROP COLUMN cartons,
--   DROP COLUMN qty_per_carton, DROP COLUMN unit_price, DROP COLUMN total_amount, DROP COLUMN notes, DROP COLUMN image_paths;

ALTER TABLE order_items
ADD COLUMN item_no VARCHAR
(100) NULL AFTER product_id,
ADD COLUMN shipping_code VARCHAR
(100) NULL AFTER item_no,
ADD COLUMN cartons INT UNSIGNED NULL AFTER shipping_code,
ADD COLUMN qty_per_carton DECIMAL
(12,4) NULL AFTER cartons,
ADD COLUMN unit_price DECIMAL
(12,4) NULL AFTER qty_per_carton,
ADD COLUMN total_amount DECIMAL
(12,4) NULL AFTER unit_price,
ADD COLUMN notes TEXT NULL AFTER total_amount,
ADD COLUMN image_paths JSON NULL AFTER notes;
