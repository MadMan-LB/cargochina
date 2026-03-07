-- CLMS Migration 023: Order templates (save/reuse item sets)
-- Rollback: DROP TABLE order_template_items; DROP TABLE order_templates;

CREATE TABLE IF NOT EXISTS order_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_order_templates_created_by (created_by)
);

CREATE TABLE IF NOT EXISTS order_template_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id INT UNSIGNED NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    item_no VARCHAR(100) NULL,
    shipping_code VARCHAR(100) NULL,
    product_id INT UNSIGNED NULL,
    description_cn VARCHAR(500) NULL,
    description_en VARCHAR(500) NULL,
    cartons INT UNSIGNED NULL,
    qty_per_carton DECIMAL(12,4) NULL,
    quantity DECIMAL(12,4) NULL,
    unit VARCHAR(20) NOT NULL DEFAULT 'cartons',
    declared_cbm DECIMAL(10,4) NULL,
    declared_weight DECIMAL(10,4) NULL,
    item_length DECIMAL(10,4) NULL,
    item_width DECIMAL(10,4) NULL,
    item_height DECIMAL(10,4) NULL,
    unit_price DECIMAL(12,4) NULL,
    total_amount DECIMAL(12,4) NULL,
    notes TEXT NULL,
    FOREIGN KEY (template_id) REFERENCES order_templates(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    CONSTRAINT chk_template_item_unit CHECK (unit IN ('cartons', 'pieces'))
);
