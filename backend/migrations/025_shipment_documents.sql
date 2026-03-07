-- CLMS Migration 025: Shipment documents and carrier refs
-- Rollback: DROP TABLE shipment_draft_documents; ALTER TABLE shipment_drafts DROP COLUMN container_number, DROP COLUMN booking_number, DROP COLUMN tracking_url;

ALTER TABLE shipment_drafts
ADD COLUMN container_number VARCHAR
(50) NULL AFTER container_id,
ADD COLUMN booking_number VARCHAR
(100) NULL AFTER container_number,
ADD COLUMN tracking_url VARCHAR
(500) NULL AFTER booking_number;

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
