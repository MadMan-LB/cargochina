-- CLMS Migration 013: Tracking push log (idempotent, auditable)
-- Rollback: DROP TABLE IF EXISTS tracking_push_log;

CREATE TABLE
IF NOT EXISTS tracking_push_log
(
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR
(50) NOT NULL DEFAULT 'shipment_draft',
  entity_id INT UNSIGNED NOT NULL,
  idempotency_key VARCHAR
(64) NOT NULL,
  status VARCHAR
(20) NOT NULL DEFAULT 'pending',
  request_payload JSON,
  response_code INT NULL,
  response_body TEXT NULL,
  external_id VARCHAR
(255) NULL,
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON
UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_idempotency (idempotency_key),
  INDEX idx_entity
(entity_type, entity_id),
  INDEX idx_status
(status)
);
