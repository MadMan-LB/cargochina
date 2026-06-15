-- CLMS Migration 068: Customer creator tracking and visibility exceptions
-- Rollback:
--   DROP TABLE IF EXISTS customer_visibility_allowed_creators;
--   DROP TABLE IF EXISTS customer_visibility_exceptions;
--   ALTER TABLE customers DROP FOREIGN KEY fk_customers_created_by;
--   ALTER TABLE customers DROP INDEX idx_customers_created_by;
--   ALTER TABLE customers DROP COLUMN created_by;

SET @m068_col_count := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'created_by'
);
SET @m068_sql := IF(@m068_col_count = 0, 'ALTER TABLE customers ADD COLUMN created_by INT UNSIGNED NULL AFTER payment_terms', 'SELECT 1');
PREPARE m068_stmt FROM @m068_sql;
EXECUTE m068_stmt;
DEALLOCATE PREPARE m068_stmt;

UPDATE customers c
JOIN (
  SELECT entity_id, MIN(user_id) AS user_id
  FROM audit_log
  WHERE entity_type = 'customer'
    AND action = 'create'
    AND user_id IS NOT NULL
  GROUP BY entity_id
) a ON a.entity_id = c.id
SET c.created_by = a.user_id
WHERE c.created_by IS NULL;

SET @m068_idx_count := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND INDEX_NAME = 'idx_customers_created_by'
);
SET @m068_sql := IF(@m068_idx_count = 0, 'ALTER TABLE customers ADD INDEX idx_customers_created_by (created_by)', 'SELECT 1');
PREPARE m068_stmt FROM @m068_sql;
EXECUTE m068_stmt;
DEALLOCATE PREPARE m068_stmt;

SET @m068_fk_count := (
  SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND CONSTRAINT_NAME = 'fk_customers_created_by'
);
SET @m068_sql := IF(@m068_fk_count = 0, 'ALTER TABLE customers ADD CONSTRAINT fk_customers_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE m068_stmt FROM @m068_sql;
EXECUTE m068_stmt;
DEALLOCATE PREPARE m068_stmt;

CREATE TABLE IF NOT EXISTS customer_visibility_exceptions
(
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  can_see_all_customers TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_customer_visibility_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_customer_visibility_created_by
    FOREIGN KEY (created_by) REFERENCES users(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_customer_visibility_updated_by
    FOREIGN KEY (updated_by) REFERENCES users(id)
    ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS customer_visibility_allowed_creators
(
  user_id INT UNSIGNED NOT NULL,
  allowed_creator_user_id INT UNSIGNED NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, allowed_creator_user_id),
  INDEX idx_customer_visibility_allowed_creator (allowed_creator_user_id),
  CONSTRAINT fk_customer_visibility_allowed_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_customer_visibility_allowed_creator_user
    FOREIGN KEY (allowed_creator_user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_customer_visibility_allowed_created_by
    FOREIGN KEY (created_by) REFERENCES users(id)
    ON DELETE SET NULL
);
