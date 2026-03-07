-- CLMS Migration 022: Departments for multi-department scaling (~40 employees)
-- Rollback: DROP TABLE user_departments; DROP TABLE departments;

CREATE TABLE IF NOT EXISTS departments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_departments (
    user_id INT UNSIGNED NOT NULL,
    department_id INT UNSIGNED NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, department_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
);

INSERT IGNORE INTO departments (code, name, description) VALUES
('warehouse', 'Warehouse', 'Receiving, inventory, physical operations'),
('buyers', 'Buyers', 'Order intake, customer/supplier liaison'),
('consolidation', 'Consolidation', 'Shipment drafts, container assignment'),
('admin', 'Administration', 'Config, users, system management'),
('tracking', 'Tracking & Push', 'Finalization, push to Lebanon tracking');
