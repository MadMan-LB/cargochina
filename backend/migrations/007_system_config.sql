-- CLMS Migration 007: System config (editable by SuperAdmin)
-- Rollback: DROP TABLE IF EXISTS system_config;

CREATE TABLE
IF NOT EXISTS system_config
(
    key_name VARCHAR
(100) PRIMARY KEY,
    key_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON
UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE
INTO system_config
(key_name, key_value) VALUES
('VARIANCE_THRESHOLD_PERCENT', '10'),
('VARIANCE_THRESHOLD_ABS_CBM', '0.1'),
('CONFIRMATION_REQUIRED', 'variance-only'),
('CUSTOMER_PHOTO_VISIBILITY', 'internal-only');
