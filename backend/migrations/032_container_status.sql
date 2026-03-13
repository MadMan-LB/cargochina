-- Migration 032: Container status lifecycle + notes
-- Rollback: ALTER TABLE containers DROP COLUMN status, DROP COLUMN notes;

SET @m032 = (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='containers' AND column_name='status');

SET @sql = IF(@m032=0,
    'ALTER TABLE containers
        ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT ''planning'' AFTER max_weight,
        ADD COLUMN notes TEXT NULL AFTER status',
    'DO 0');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
