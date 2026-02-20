-- CLMS Migration 017: Notification delivery log (channel, payload_hash, status, attempts, last_error)
-- Idempotency via payload_hash to avoid duplicate deliveries
-- Rollback: DROP TABLE notification_delivery_log; ALTER TABLE notifications DROP COLUMN channel;

ALTER TABLE notifications ADD COLUMN channel VARCHAR
(20) NOT NULL DEFAULT 'dashboard' AFTER type;

CREATE TABLE notification_delivery_log
(
  id INT
  UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  notification_id INT UNSIGNED NOT NULL,
  channel VARCHAR
  (20) NOT NULL,
  payload_hash VARCHAR
  (64) NULL,
  status VARCHAR
  (20) NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 1,
  last_error TEXT NULL,
  external_id VARCHAR
  (255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON
  UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY
  (notification_id) REFERENCES notifications
  (id) ON
  DELETE CASCADE,
  INDEX idx_ndl_notification (notification_id),
  INDEX idx_ndl_status
  (status)
);

  INSERT IGNORE
  INTO system_config
  (key_name, key_value) VALUES
  ('ITEM_LEVEL_RECEIVING_ENABLED', '0'),
  ('PHOTO_EVIDENCE_PER_ITEM', '0');
