-- CLMS Migration 003: Warehouse Receiving
-- Tables: warehouse_receipts, warehouse_receipt_photos
-- Rollback: DROP TABLE IF EXISTS warehouse_receipt_photos, warehouse_receipts;

CREATE TABLE
IF NOT EXISTS warehouse_receipts
(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    actual_cartons INT UNSIGNED NOT NULL DEFAULT 0,
    actual_cbm DECIMAL
(10,4) NOT NULL DEFAULT 0,
    actual_weight DECIMAL
(10,4) NOT NULL DEFAULT 0,
    condition VARCHAR
(20) NOT NULL DEFAULT 'good',
    notes TEXT,
    received_by INT UNSIGNED,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY
(order_id) REFERENCES orders
(id) ON
DELETE CASCADE,
    FOREIGN KEY (received_by)
REFERENCES users
(id) ON
DELETE
SET NULL
,
    CONSTRAINT chk_condition CHECK
(condition IN
('good', 'damaged', 'partial'))
);

CREATE TABLE
IF NOT EXISTS warehouse_receipt_photos
(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receipt_id INT UNSIGNED NOT NULL,
    file_path VARCHAR
(500) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY
(receipt_id) REFERENCES warehouse_receipts
(id) ON
DELETE CASCADE
);
