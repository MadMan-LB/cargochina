-- Migration 045: Add Seketerik Fees to warehouse expense categories
-- Rollback: DELETE FROM expense_categories WHERE code = 'SEKETERIK';

INSERT IGNORE INTO expense_categories (code, name, category_type) VALUES
('SEKETERIK', 'Seketerik Fees', 'warehouse');
