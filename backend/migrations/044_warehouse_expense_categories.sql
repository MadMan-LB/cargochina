-- Migration 044: Warehouse expense categories (pallet fees, delivery, etc.)
-- Rollback: DELETE FROM expense_categories WHERE code IN ('PALLET','DELIVERY','LOADING','UNLOADING','STACKING','DOCUMENTATION');

INSERT IGNORE INTO expense_categories (code, name, category_type) VALUES
('PALLET', 'Pallet Fees', 'warehouse'),
('DELIVERY', 'Delivery Fees', 'warehouse'),
('LOADING', 'Loading Fees', 'warehouse'),
('UNLOADING', 'Unloading Fees', 'warehouse'),
('STACKING', 'Stacking / Storage', 'warehouse'),
('DOCUMENTATION', 'Documentation Fees', 'warehouse');
