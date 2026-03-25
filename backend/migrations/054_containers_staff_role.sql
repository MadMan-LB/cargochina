-- CLMS Migration 054: Containers staff role
-- Rollback: DELETE FROM roles WHERE code = 'ContainersStaff';

INSERT IGNORE
INTO roles
(code, name) VALUES
('ContainersStaff', 'Containers Staff');
