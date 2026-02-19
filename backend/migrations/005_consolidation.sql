-- CLMS Migration 005: Consolidation & Containers
-- Rollback: DROP TABLE IF EXISTS shipment_draft_orders, shipment_drafts, containers;

CREATE TABLE
IF NOT EXISTS containers
(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR
(50) NOT NULL UNIQUE,
    max_cbm DECIMAL
(10,4) NOT NULL,
    max_weight DECIMAL
(10,4) NOT NULL,
    status VARCHAR
(50) NOT NULL DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE
IF NOT EXISTS shipment_drafts
(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    container_id INT UNSIGNED NULL,
    status VARCHAR
(50) NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY
(container_id) REFERENCES containers
(id) ON
DELETE
SET NULL
,
    CONSTRAINT chk_sd_status CHECK
(status IN
('draft', 'finalized'))
);

CREATE TABLE
IF NOT EXISTS shipment_draft_orders
(
    shipment_draft_id INT UNSIGNED NOT NULL,
    order_id INT UNSIGNED NOT NULL,
    PRIMARY KEY
(shipment_draft_id, order_id),
    FOREIGN KEY
(shipment_draft_id) REFERENCES shipment_drafts
(id) ON
DELETE CASCADE,
    FOREIGN KEY (order_id)
REFERENCES orders
(id) ON
DELETE CASCADE
);
