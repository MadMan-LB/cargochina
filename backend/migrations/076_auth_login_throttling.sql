-- CLMS Migration 076: hashed login-attempt throttling state.
-- Stores no plaintext email address or IP address.
-- Rollback: DROP TABLE IF EXISTS auth_login_attempts;

CREATE TABLE IF NOT EXISTS auth_login_attempts
(
  identity_hash CHAR(64) PRIMARY KEY,
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  first_attempt_at DATETIME NOT NULL,
  last_attempt_at DATETIME NOT NULL,
  blocked_until DATETIME NULL,
  INDEX idx_auth_attempt_blocked (blocked_until),
  INDEX idx_auth_attempt_last (last_attempt_at)
);
