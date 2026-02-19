-- CLMS Migration 006: Seed default SuperAdmin
-- Rollback: DELETE FROM user_roles WHERE user_id = (SELECT id FROM users WHERE email = 'admin@salameh.com'); DELETE FROM users WHERE email = 'admin@salameh.com';

INSERT IGNORE
INTO users
(email, password_hash, full_name) VALUES
('admin@salameh.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Super Admin');
-- Password: password

INSERT IGNORE
INTO user_roles
(user_id, role_id)
SELECT u.id, r.id
FROM users u, roles r
WHERE u.email = 'admin@salameh.com' AND r.code = 'SuperAdmin'
    AND NOT EXISTS (SELECT 1
    FROM user_roles ur
    WHERE ur.user_id = u.id AND ur.role_id = r.id);
