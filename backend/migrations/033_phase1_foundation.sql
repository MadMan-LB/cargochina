-- CLMS Migration 033: Phase 1 Foundation
-- Supplier commissions, buy/sell pricing, customer priority, business settings,
-- expenses, design attachments, draft procurement, container destination,
-- customer decline, portal tokens, internal messages, HS code architecture
-- Rollback: See individual sections below

-- 1. Suppliers: Commission
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='suppliers' AND column_name='commission_rate');
SET @s = IF(@c=0, 'ALTER TABLE suppliers ADD COLUMN commission_rate DECIMAL(6,4) NULL AFTER notes, ADD COLUMN commission_type VARCHAR(20) NOT NULL DEFAULT ''percentage'' AFTER commission_rate, ADD COLUMN commission_applied_on VARCHAR(20) NOT NULL DEFAULT ''buy_value'' AFTER commission_type', 'DO 0');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Customers: Priority & Shipping
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='customers' AND column_name='priority_level');
SET @s = IF(@c=0, 'ALTER TABLE customers ADD COLUMN priority_level VARCHAR(20) NOT NULL DEFAULT ''normal'' AFTER address, ADD COLUMN priority_note TEXT NULL AFTER priority_level, ADD COLUMN default_shipping_code VARCHAR(100) NULL AFTER priority_note', 'DO 0');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Products: Buy/Sell
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='products' AND column_name='buy_price');
SET @s = IF(@c=0, 'ALTER TABLE products ADD COLUMN buy_price DECIMAL(12,4) NULL AFTER unit_price, ADD COLUMN sell_price DECIMAL(12,4) NULL AFTER buy_price', 'DO 0');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Order Items: Buy/Sell Override, Packaging Override
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='order_items' AND column_name='buy_price');
SET @s = IF(@c=0, 'ALTER TABLE order_items ADD COLUMN buy_price DECIMAL(12,4) NULL AFTER total_amount, ADD COLUMN sell_price DECIMAL(12,4) NULL AFTER buy_price, ADD COLUMN order_cartons INT NULL AFTER sell_price, ADD COLUMN order_qty_per_carton DECIMAL(12,4) NULL AFTER order_cartons', 'DO 0');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. Orders: High-Alert, Order Type
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='orders' AND column_name='high_alert_notes');
SET @s = IF(@c=0, 'ALTER TABLE orders ADD COLUMN high_alert_notes TEXT NULL AFTER confirmation_token, ADD COLUMN order_type VARCHAR(30) NOT NULL DEFAULT ''standard'' AFTER high_alert_notes', 'DO 0');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6. Containers: Destination
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='containers' AND column_name='destination_country');
SET @s = IF(@c=0, 'ALTER TABLE containers ADD COLUMN destination_country VARCHAR(100) NULL AFTER notes, ADD COLUMN destination VARCHAR(255) NULL AFTER destination_country', 'DO 0');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7. Customer Confirmations: Decline
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='customer_confirmations' AND column_name='declined_at');
SET @s = IF(@c=0, 'ALTER TABLE customer_confirmations ADD COLUMN declined_at TIMESTAMP NULL AFTER confirmed_at, ADD COLUMN decline_reason TEXT NULL AFTER declined_at', 'DO 0');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 8. Business Settings
CREATE TABLE IF NOT EXISTS business_settings (
    key_name VARCHAR(100) NOT NULL PRIMARY KEY,
    key_value TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 9. Expense Categories
CREATE TABLE IF NOT EXISTS expense_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    category_type VARCHAR(30) NOT NULL DEFAULT 'operational',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_exp_cat_type CHECK (category_type IN ('operational','salary','fixed','variable','order','container','customs','warehouse','admin'))
);

-- 10. Expenses
CREATE TABLE IF NOT EXISTS expenses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    amount DECIMAL(14,4) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'USD',
    expense_date DATE NOT NULL,
    payee VARCHAR(255) NULL,
    notes TEXT NULL,
    order_id INT UNSIGNED NULL,
    container_id INT UNSIGNED NULL,
    customer_id INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE RESTRICT,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    FOREIGN KEY (container_id) REFERENCES containers(id) ON DELETE SET NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_expenses_date (expense_date),
    INDEX idx_expenses_order (order_id),
    INDEX idx_expenses_container (container_id)
);

-- 11. Design Attachments
CREATE TABLE IF NOT EXISTS design_attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(30) NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_type VARCHAR(50) NULL,
    uploaded_by INT UNSIGNED NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    internal_note TEXT NULL,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_design_entity (entity_type, entity_id)
);

-- 12. Procurement Drafts
CREATE TABLE IF NOT EXISTS procurement_drafts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    supplier_id INT UNSIGNED NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    converted_order_id INT UNSIGNED NULL,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (converted_order_id) REFERENCES orders(id) ON DELETE SET NULL,
    CONSTRAINT chk_proc_status CHECK (status IN ('draft','pending_review','sent_to_supplier','converted','cancelled'))
);

CREATE TABLE IF NOT EXISTS procurement_draft_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    draft_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NULL,
    quantity DECIMAL(12,4) NOT NULL,
    notes TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    FOREIGN KEY (draft_id) REFERENCES procurement_drafts(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    INDEX idx_proc_draft (draft_id)
);

-- 13. Customer Portal Tokens
CREATE TABLE IF NOT EXISTS customer_portal_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_portal_token (token_hash),
    INDEX idx_portal_customer (customer_id)
);

-- 14. Internal Messages
CREATE TABLE IF NOT EXISTS internal_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    order_id INT UNSIGNED NULL,
    container_id INT UNSIGNED NULL,
    sender_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    FOREIGN KEY (container_id) REFERENCES containers(id) ON DELETE SET NULL,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_messages_customer (customer_id),
    INDEX idx_messages_order (order_id)
);

-- 15. HS Code Tax (Architecture)
CREATE TABLE IF NOT EXISTS hs_code_tax_rates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hs_code VARCHAR(20) NOT NULL,
    country_code VARCHAR(10) NOT NULL DEFAULT 'LB',
    rate_percent DECIMAL(6,4) NOT NULL DEFAULT 0,
    effective_from DATE NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_hs_code (hs_code),
    INDEX idx_hs_country (country_code)
);

-- Seed default expense categories
INSERT IGNORE INTO expense_categories (code, name, category_type) VALUES
('TRANSPORT', 'Transportation', 'operational'),
('FILLING', 'Filling', 'operational'),
('EMPTYING', 'Emptying', 'operational'),
('HANDLING', 'Handling', 'operational'),
('CUSTOMS', 'Customs Fees', 'customs'),
('WAREHOUSE', 'Warehouse Fees', 'warehouse'),
('ADMIN', 'Admin Costs', 'admin'),
('SALARY', 'Salaries', 'salary'),
('FIXED', 'Fixed Expenses', 'fixed'),
('VARIABLE', 'Variable Expenses', 'variable');

-- Seed default business settings (ETA offsets, arrival notification thresholds)
INSERT IGNORE INTO business_settings (key_name, key_value) VALUES
('ETA_OFFSETS_JSON', '{"LB":{"groupage":15,"full_container":0,"special":0},"DEFAULT":{"groupage":0,"full_container":0,"special":0}}'),
('ARRIVAL_NOTIFY_DAYS', '7,3,1'),
('CONTAINER_20HQ_CBM', '28'),
('CONTAINER_40HQ_CBM', '68'),
('CONTAINER_45HQ_CBM', '78');
