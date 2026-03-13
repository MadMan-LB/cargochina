-- CLMS Migration 026: Products (description entries, pieces/carton, unit price) + Customers (payment links)
-- Rollback: DROP TABLE IF EXISTS product_description_entries;
--   ALTER TABLE products DROP COLUMN pieces_per_carton, DROP COLUMN unit_price;
--   ALTER TABLE customers DROP COLUMN payment_links;

CREATE TABLE
IF NOT EXISTS product_description_entries
(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    description_text VARCHAR
(500) NOT NULL,
    description_translated VARCHAR
(500) NULL,
    sort_order INT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY
(product_id) REFERENCES products
(id) ON
DELETE CASCADE,
    INDEX idx_product_desc_product (product_id)
);

SET @m026 = (SELECT COUNT(*)
FROM information_schema.COLUMNS
WHERE table_schema=DATABASE
() AND table_name='products' AND column_name='pieces_per_carton');
SET @sql =
IF(@m026=0, 'ALTER TABLE products ADD COLUMN pieces_per_carton INT UNSIGNED NULL AFTER packaging, ADD COLUMN unit_price DECIMAL(12,4) NULL AFTER pieces_per_carton', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @m026b = (SELECT COUNT(*)
FROM information_schema.COLUMNS
WHERE table_schema=DATABASE
() AND table_name='customers' AND column_name='payment_links');
SET @sql =
IF(@m026b=0, 'ALTER TABLE customers ADD COLUMN payment_links JSON NULL AFTER payment_terms', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO product_description_entries
  (product_id, description_text, description_translated, sort_order)
SELECT id, COALESCE(NULLIF(TRIM(description_cn), ''), NULLIF(TRIM(description_en), ''), '—'), NULLIF(TRIM(description_en), ''), 0
FROM products
WHERE (TRIM(COALESCE(description_cn, '')) != '' OR TRIM(COALESCE(description_en, '')) != '')
  AND NOT EXISTS (SELECT 1
  FROM product_description_entries pde
  WHERE pde.product_id = products.id);
