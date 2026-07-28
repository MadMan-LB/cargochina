-- CLMS Migration 080: Searchable English and Chinese HS tariff names.
-- Keeps the imported source name unchanged and adds nullable translated values.
-- Safe for fresh and upgraded installations; existing catalog rows are preserved.

SET @m080_table_count := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hs_code_tariff_catalog'
);

SET @m080_column_count := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hs_code_tariff_catalog' AND COLUMN_NAME = 'name_en'
);
SET @m080_sql := IF(@m080_table_count > 0 AND @m080_column_count = 0,
  'ALTER TABLE hs_code_tariff_catalog ADD COLUMN name_en VARCHAR(500) NULL AFTER name',
  'SELECT 1');
PREPARE m080_stmt FROM @m080_sql; EXECUTE m080_stmt; DEALLOCATE PREPARE m080_stmt;

SET @m080_column_count := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hs_code_tariff_catalog' AND COLUMN_NAME = 'name_zh'
);
SET @m080_sql := IF(@m080_table_count > 0 AND @m080_column_count = 0,
  'ALTER TABLE hs_code_tariff_catalog ADD COLUMN name_zh VARCHAR(500) NULL AFTER name_en',
  'SELECT 1');
PREPARE m080_stmt FROM @m080_sql; EXECUTE m080_stmt; DEALLOCATE PREPARE m080_stmt;

SET @m080_column_count := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hs_code_tariff_catalog' AND COLUMN_NAME = 'translated_at'
);
SET @m080_sql := IF(@m080_table_count > 0 AND @m080_column_count = 0,
  'ALTER TABLE hs_code_tariff_catalog ADD COLUMN translated_at DATETIME NULL AFTER imported_at',
  'SELECT 1');
PREPARE m080_stmt FROM @m080_sql; EXECUTE m080_stmt; DEALLOCATE PREPARE m080_stmt;
