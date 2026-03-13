-- CLMS Migration 025: Shipment documents and carrier refs
-- Rollback: DROP TABLE shipment_draft_documents; ALTER TABLE shipment_drafts DROP COLUMN container_number, DROP COLUMN booking_number, DROP COLUMN tracking_url;

SET @m025 = (SELECT COUNT(*)
FROM information_schema.COLUMNS
WHERE table_schema=DATABASE
() AND table_name='shipment_drafts' AND column_name='container_number');
SET @sql =
IF(@m025=0, 'ALTER TABLE shipment_drafts ADD COLUMN container_number VARCHAR(50) NULL AFTER container_id, ADD COLUMN booking_number VARCHAR(100) NULL AFTER container_number, ADD COLUMN tracking_url VARCHAR(500) NULL AFTER booking_number', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE
IF NOT EXISTS shipment_draft_documents
(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shipment_draft_id INT UNSIGNED NOT NULL,
    file_path VARCHAR
(500) NOT NULL,
    doc_type VARCHAR
(50) NOT NULL DEFAULT 'other',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY
(shipment_draft_id) REFERENCES shipment_drafts
(id) ON
DELETE CASCADE,
    CONSTRAINT chk_sdd_type CHECK
(doc_type IN
('bol', 'booking_confirmation', 'invoice', 'other'))
);
