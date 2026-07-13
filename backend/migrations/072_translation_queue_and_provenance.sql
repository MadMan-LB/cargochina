-- CLMS Migration 072: Non-blocking bilingual translation queue and provenance.
-- Existing translated business values are not changed.
-- Rollback:
--   DROP TABLE IF EXISTS bilingual_text_registry;
--   DROP TABLE IF EXISTS translation_manual_corrections;
--   DROP TABLE IF EXISTS translation_jobs;

CREATE TABLE IF NOT EXISTS translation_jobs
(
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_hash CHAR(64) NOT NULL,
  original_text TEXT NOT NULL,
  source_lang VARCHAR(10) NOT NULL,
  target_lang VARCHAR(10) NOT NULL,
  provider VARCHAR(40) NOT NULL DEFAULT 'disabled',
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  translated_text TEXT NULL,
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NULL,
  next_retry_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_translation_job (source_hash, source_lang, target_lang),
  INDEX idx_translation_job_retry (status, next_retry_at)
);

CREATE TABLE IF NOT EXISTS translation_manual_corrections
(
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_hash CHAR(64) NOT NULL,
  original_text TEXT NOT NULL,
  source_lang VARCHAR(10) NOT NULL,
  target_lang VARCHAR(10) NOT NULL,
  translated_text TEXT NOT NULL,
  corrected_by INT UNSIGNED NULL,
  corrected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_translation_manual (source_hash, source_lang, target_lang),
  CONSTRAINT fk_translation_manual_user
    FOREIGN KEY (corrected_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS bilingual_text_registry
(
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(60) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  field_key VARCHAR(80) NOT NULL,
  original_lang VARCHAR(10) NOT NULL,
  original_text TEXT NOT NULL,
  text_en TEXT NULL,
  text_zh TEXT NULL,
  en_state VARCHAR(20) NOT NULL DEFAULT 'pending',
  zh_state VARCHAR(20) NOT NULL DEFAULT 'pending',
  source_hash CHAR(64) NOT NULL,
  last_error VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_bilingual_entity_field (entity_type, entity_id, field_key),
  INDEX idx_bilingual_source_hash (source_hash),
  INDEX idx_bilingual_pending (en_state, zh_state)
);
