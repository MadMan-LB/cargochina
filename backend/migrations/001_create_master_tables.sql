-- CLMS Migration 001: Master Data Foundation
-- Tables: users, roles, user_roles, customers, suppliers, products, translations
-- Rollback: DROP TABLE IF EXISTS translations, products, suppliers, customers, user_roles, roles, users;

CREATE TABLE
IF NOT EXISTS roles
(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR
(50) NOT NULL UNIQUE,
    name VARCHAR
(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE
IF NOT EXISTS users
(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR
(255) NOT NULL UNIQUE,
    password_hash VARCHAR
(255) NOT NULL,
    full_name VARCHAR
(255) NOT NULL,
    is_active TINYINT
(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON
UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE
IF NOT EXISTS user_roles
(
    user_id INT UNSIGNED NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    PRIMARY KEY
(user_id, role_id),
    FOREIGN KEY
(user_id) REFERENCES users
(id) ON
DELETE CASCADE,
    FOREIGN KEY (role_id)
REFERENCES roles
(id) ON
DELETE CASCADE
);

CREATE TABLE
IF NOT EXISTS customers
(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR
(50) NOT NULL UNIQUE,
    name VARCHAR
(255) NOT NULL,
    contacts JSON,
    addresses JSON,
    payment_terms VARCHAR
(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON
UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE
IF NOT EXISTS suppliers
(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR
(50) NOT NULL UNIQUE,
    name VARCHAR
(255) NOT NULL,
    contacts JSON,
    factory_location VARCHAR
(255),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON
UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE
IF NOT EXISTS products
(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NULL,
    cbm DECIMAL
(10,4) NOT NULL DEFAULT 0,
    weight DECIMAL
(10,4) NOT NULL DEFAULT 0,
    packaging VARCHAR
(100),
    hs_code VARCHAR
(50),
    description_cn VARCHAR
(500),
    description_en VARCHAR
(500),
    image_paths JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON
UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY
(supplier_id) REFERENCES suppliers
(id) ON
DELETE
SET NULL
);

CREATE TABLE
IF NOT EXISTS translations
(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    original_hash VARCHAR
(64) NOT NULL,
    original_text TEXT NOT NULL,
    translated_text TEXT NOT NULL,
    source_lang VARCHAR
(10) NOT NULL DEFAULT 'zh',
    target_lang VARCHAR
(10) NOT NULL DEFAULT 'en',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_original_hash
(original_hash)
);

-- Seed default roles
INSERT IGNORE
INTO roles
(code, name) VALUES
('ChinaEmployee', 'China Employee'),
('ChinaAdmin', 'China Admin'),
('WarehouseStaff', 'Warehouse Staff'),
('LebanonAdmin', 'Lebanon Admin'),
('SuperAdmin', 'Super Admin');
