-- CLMS Migration 002: Orders & Audit
-- Tables: orders, order_items, order_attachments, audit_log
-- Rollback: DROP TABLE IF EXISTS order_attachments, order_items, orders, audit_log;

CREATE TABLE
IF NOT EXISTS orders
(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    expected_ready_date DATE NOT NULL,
    status VARCHAR
(50) NOT NULL DEFAULT 'Draft',
    created_by INT UNSIGNED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON
UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY
(customer_id) REFERENCES customers
(id) ON
DELETE RESTRICT,
    FOREIGN KEY (supplier_id)
REFERENCES suppliers
(id) ON
DELETE RESTRICT,
    FOREIGN KEY (created_by)
REFERENCES users
(id) ON
DELETE
SET NULL
,
    INDEX idx_orders_status
(status),
    INDEX idx_orders_customer
(customer_id),
    INDEX idx_orders_expected_date
(expected_ready_date)
);

CREATE TABLE
IF NOT EXISTS order_items
(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NULL,
    quantity DECIMAL
(12,4) NOT NULL,
    unit VARCHAR
(20) NOT NULL,
    declared_cbm DECIMAL
(10,4) NOT NULL,
    declared_weight DECIMAL
(10,4) NOT NULL,
    description_cn VARCHAR
(500),
    description_en VARCHAR
(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY
(order_id) REFERENCES orders
(id) ON
DELETE CASCADE,
    FOREIGN KEY (product_id)
REFERENCES products
(id) ON
DELETE
SET NULL
,
    CONSTRAINT chk_unit CHECK
(unit IN
('cartons', 'pieces'))
);

CREATE TABLE
IF NOT EXISTS order_attachments
(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    file_path VARCHAR
(500) NOT NULL,
    type VARCHAR
(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY
(order_id) REFERENCES orders
(id) ON
DELETE CASCADE,
    CONSTRAINT chk_attachment_type CHECK
(type IN
('invoice', 'packing_list', 'photo'))
);

CREATE TABLE
IF NOT EXISTS audit_log
(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR
(50) NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    action VARCHAR
(50) NOT NULL,
    old_value JSON,
    new_value JSON,
    user_id INT UNSIGNED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY
(user_id) REFERENCES users
(id) ON
DELETE
SET NULL
,
    INDEX idx_audit_entity
(entity_type, entity_id)
);
