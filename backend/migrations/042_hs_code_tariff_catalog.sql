-- HS Code Tariff Catalog (Lebanon customs reference data)
-- Used for products page HS code autocomplete and tariff lookup.
-- Import via admin config or from hs codes/ folder (CSV).
-- Rollback: DROP TABLE IF EXISTS hs_code_tariff_catalog;

CREATE TABLE
IF NOT EXISTS hs_code_tariff_catalog
(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hs_code VARCHAR
(20) NOT NULL,
    name VARCHAR
(500) NULL,
    category VARCHAR
(255) NULL,
    tariff_rate VARCHAR
(50) NULL,
    vat VARCHAR
(50) NULL,
    parent_directory_code VARCHAR
(20) NULL,
    parent_directory_name VARCHAR
(255) NULL,
    section_code VARCHAR
(20) NULL,
    section_name VARCHAR
(255) NULL,
    source_file VARCHAR
(255) NULL,
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_hs_code
(hs_code),
    INDEX idx_hs_code_like
(hs_code
(10))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
