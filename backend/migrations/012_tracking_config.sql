-- CLMS Migration 012: Tracking integration config
-- Rollback: DELETE FROM system_config WHERE key_name LIKE 'TRACKING_%';

INSERT IGNORE
INTO system_config
(key_name, key_value) VALUES
('TRACKING_API_BASE_URL', ''),
('TRACKING_API_TOKEN', ''),
('TRACKING_API_TIMEOUT_SEC', '15'),
('TRACKING_API_RETRY_COUNT', '3'),
('TRACKING_API_RETRY_BACKOFF_MS', '800'),
('TRACKING_PUSH_ENABLED', '0'),
('TRACKING_PUSH_DRY_RUN', '1'),
('TRACKING_API_PATH', '/api/import/clms');
