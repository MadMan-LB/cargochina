-- Migration 037: Product dimensions scope (piece vs carton)
-- CBM, L×W×H, weight are stored per piece or per carton based on this field.
-- Rollback: ALTER TABLE products DROP COLUMN dimensions_scope;

SET @c = (SELECT COUNT(*)
FROM information_schema.COLUMNS
WHERE table_schema=DATABASE
() AND table_name='products' AND column_name='dimensions_scope');

SET @s =
IF(@c=0,
    'ALTER TABLE products ADD COLUMN dimensions_scope VARCHAR(10) NOT NULL DEFAULT ''piece'' AFTER height_cm',
    'DO 0');

PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
