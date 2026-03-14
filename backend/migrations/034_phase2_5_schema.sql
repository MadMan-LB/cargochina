-- CLMS Migration 034: Phase 2-5 schema additions
-- Orders: nullable supplier_id for multi-supplier; containers: eta_date; warehouse_stock view

-- Make order supplier_id nullable (multi-supplier: line-level supplier is source of truth)
SET @c = (SELECT COUNT(*)
FROM information_schema.COLUMNS
WHERE table_schema=DATABASE
() AND table_name='orders' AND column_name='supplier_id' AND is_nullable='YES');
SET @s =
IF(@c=0, 'ALTER TABLE orders MODIFY COLUMN supplier_id INT UNSIGNED NULL', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Containers: ETA and arrival dates for notifications
SET @c = (SELECT COUNT(*)
FROM information_schema.COLUMNS
WHERE table_schema=DATABASE
() AND table_name='containers' AND column_name='eta_date');
SET @s =
IF(@c=0, 'ALTER TABLE containers ADD COLUMN eta_date DATE NULL AFTER destination, ADD COLUMN actual_arrival_date DATE NULL AFTER eta_date', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Container arrival notifications tracking (avoid spam)
CREATE TABLE
IF NOT EXISTS container_arrival_notifications
(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    container_id INT UNSIGNED NOT NULL,
    days_before INT NOT NULL,
    notified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY
(container_id) REFERENCES containers
(id) ON
DELETE CASCADE,
    UNIQUE KEY uk_container_days (container_id, days_before
)
);
