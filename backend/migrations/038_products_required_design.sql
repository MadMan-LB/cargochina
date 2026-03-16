-- Migration 038: Product required design flag (surfaces as high alert in all processes)
-- Rollback: ALTER TABLE products DROP COLUMN required_design;

SET @c = (SELECT COUNT(*)
FROM information_schema.COLUMNS
WHERE table_schema=DATABASE
() AND table_name='products' AND column_name='required_design');

SET @s =
IF(@c=0,
    'ALTER TABLE products ADD COLUMN required_design TINYINT(1) NOT NULL DEFAULT 0 AFTER high_alert_note',
    'DO 0');

PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
