-- Migration 040: Container expected ship date (launch/upload to cargo ship) and vessel info
-- Rollback: ALTER TABLE containers DROP COLUMN expected_ship_date, DROP COLUMN actual_departure_date, DROP COLUMN vessel_name;

SET @c = (SELECT COUNT(*)
FROM information_schema.COLUMNS
WHERE table_schema=DATABASE
() AND table_name='containers' AND column_name='expected_ship_date');
SET @s =
IF(@c=0, 'ALTER TABLE containers ADD COLUMN expected_ship_date DATE NULL AFTER actual_arrival_date, ADD COLUMN actual_departure_date DATE NULL AFTER expected_ship_date, ADD COLUMN vessel_name VARCHAR(100) NULL AFTER actual_departure_date', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
