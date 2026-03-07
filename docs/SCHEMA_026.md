# Migration 026 — Products & Customers Enhancements

## Schema Changes

### product_description_entries (new table)
- `id` INT UNSIGNED PK
- `product_id` INT UNSIGNED FK → products(id) ON DELETE CASCADE
- `description_text` VARCHAR(500) NOT NULL
- `description_translated` VARCHAR(500) NULL
- `sort_order` INT UNSIGNED DEFAULT 0
- `created_at` TIMESTAMP

Multiple descriptions per product; merged for display, tracked separately.

### products (new columns)
- `pieces_per_carton` INT UNSIGNED NULL
- `unit_price` DECIMAL(12,4) NULL

### customers (new column)
- `payment_links` JSON NULL — array of `{name, value}` e.g. weeecha, xxx xx xxxx xx

## Rollback
```sql
DROP TABLE IF EXISTS product_description_entries;
ALTER TABLE products DROP COLUMN pieces_per_carton, DROP COLUMN unit_price;
ALTER TABLE customers DROP COLUMN payment_links;
```
