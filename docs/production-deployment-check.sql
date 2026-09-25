-- READ ONLY: run all in phpMyAdmin. USE selects the connection's database only.
-- No business records, credentials or configuration are changed or returned.
USE `clms`;
SELECT DATABASE() AS selected_database, 'clms' AS checked_database, VERSION() AS database_version,
       @@sql_mode AS sql_mode, @@character_set_connection AS connection_charset,
       @@collation_connection AS connection_collation;

-- Exactly eight rows expected. Any MISSING row means migration 087 is incomplete.
SELECT required.table_name, required.column_name,
       IF(actual.COLUMN_NAME IS NULL, 'MISSING', 'PRESENT') AS migration_087_status,
       actual.COLUMN_TYPE, actual.IS_NULLABLE, actual.COLUMN_DEFAULT
FROM (
    SELECT 'shipment_drafts' AS table_name, 'deleted_at' AS column_name
    UNION ALL SELECT 'shipment_drafts', 'deleted_by'
    UNION ALL SELECT 'shipment_drafts', 'delete_reason'
    UNION ALL SELECT 'shipment_drafts', 'delete_generation'
    UNION ALL SELECT 'procurement_drafts', 'deleted_at'
    UNION ALL SELECT 'procurement_drafts', 'deleted_by'
    UNION ALL SELECT 'procurement_drafts', 'delete_reason'
    UNION ALL SELECT 'procurement_drafts', 'delete_generation'
) required
LEFT JOIN information_schema.COLUMNS actual
  ON actual.TABLE_SCHEMA='clms'
 AND actual.TABLE_NAME=required.table_name
 AND actual.COLUMN_NAME=required.column_name
ORDER BY required.table_name, required.column_name;

-- Table existence and engine only; this does not inspect cargo/customer data.
SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA='clms'
  AND TABLE_NAME IN ('orders','order_items','shipment_drafts','shipment_draft_orders',
                    'procurement_drafts','containers','warehouse_receipts','audit_log')
ORDER BY TABLE_NAME;
