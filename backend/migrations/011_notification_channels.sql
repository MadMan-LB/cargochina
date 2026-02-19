-- CLMS Migration 011: Notification channels config
-- Rollback: DELETE FROM system_config WHERE key_name = 'NOTIFICATION_CHANNELS';

INSERT IGNORE
INTO system_config
(key_name, key_value) VALUES
('NOTIFICATION_CHANNELS', 'dashboard');
