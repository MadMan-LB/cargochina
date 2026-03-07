-- CLMS Migration 024: Field staff role (people who visit shops/suppliers)
-- Rollback: DELETE FROM roles WHERE code = 'FieldStaff';

INSERT IGNORE
INTO roles
(code, name) VALUES
('FieldStaff', 'Field Staff');
