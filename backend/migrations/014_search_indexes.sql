-- CLMS Migration 014: Search indexes for type-to-select autocomplete
-- Note: code columns already have UNIQUE indexes. Adding name/phone/descriptions for LIKE search.
-- Rollback: DROP INDEX idx_customers_name ON customers; DROP INDEX idx_suppliers_name ON suppliers;
--   DROP INDEX idx_suppliers_phone ON suppliers; DROP INDEX idx_suppliers_store_id ON suppliers;
--   DROP INDEX idx_products_desc_cn ON products; DROP INDEX idx_products_desc_en ON products; DROP INDEX idx_products_hs_code ON products;

CREATE INDEX idx_customers_name ON customers (name
(100));
CREATE INDEX idx_suppliers_name ON suppliers (name
(100));
CREATE INDEX idx_suppliers_phone ON suppliers (phone
(50));
CREATE INDEX idx_suppliers_store_id ON suppliers (store_id
(50));
CREATE INDEX idx_products_desc_cn ON products (description_cn
(200));
CREATE INDEX idx_products_desc_en ON products (description_en
(200));
CREATE INDEX idx_products_hs_code ON products (hs_code
(50));
