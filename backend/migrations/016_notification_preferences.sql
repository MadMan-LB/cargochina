-- CLMS Migration 016: Notification preferences (per user + channel toggles)
-- Rollback: DROP TABLE user_notification_preferences; DELETE FROM system_config WHERE key_name IN ('EMAIL_FROM_ADDRESS','EMAIL_FROM_NAME','WHATSAPP_API_URL','WHATSAPP_API_TOKEN');

CREATE TABLE user_notification_preferences
(
  id INT
  UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  channel VARCHAR
  (20) NOT NULL,
  event_type VARCHAR
  (50) NOT NULL,
  enabled TINYINT
  (1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON
  UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_channel_event (user_id, channel, event_type
  ),
  FOREIGN KEY
  (user_id) REFERENCES users
  (id) ON
  DELETE CASCADE,
  CONSTRAINT chk_pref_channel CHECK
  (channel IN
  ('dashboard','email','whatsapp')),
  CONSTRAINT chk_pref_event CHECK
  (event_type IN
  ('order_submitted','order_approved','order_received','variance_confirmation','shipment_finalized'))
);

  INSERT IGNORE
  INTO system_config
  (key_name, key_value) VALUES
  ('EMAIL_FROM_ADDRESS', 'noreply@example.com'),
  ('EMAIL_FROM_NAME', 'CLMS'),
  ('WHATSAPP_API_URL', ''),
  ('WHATSAPP_API_TOKEN', '');
