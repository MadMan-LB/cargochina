-- CLMS Migration 008: Supplier contact enhancements
-- Add phone and additional_ids for external identifiers (tax ID, VAT, etc.)
-- Rollback: ALTER TABLE suppliers DROP COLUMN phone, DROP COLUMN additional_ids;

ALTER TABLE suppliers
ADD COLUMN phone VARCHAR
(50) NULL AFTER notes,
ADD COLUMN additional_ids JSON NULL AFTER phone;
