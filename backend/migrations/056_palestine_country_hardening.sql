-- Migration 056: Harden Palestine country canonicalization across environments
-- Ensures PS exists, remaps legacy IL references to PS, and normalizes legacy
-- textual container destination fields that may still store IL/Israel.
-- Rollback: No automatic rollback. Review legacy country references manually if needed.

INSERT INTO countries (code, name)
SELECT 'PS', 'Palestine'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM countries
    WHERE code = 'PS'
);

UPDATE countries
SET name = 'Palestine'
WHERE code = 'PS';

SET @ps_id = (
    SELECT id
    FROM countries
    WHERE code = 'PS'
    ORDER BY id
    LIMIT 1
);

SET @il_id = (
    SELECT id
    FROM countries
    WHERE code = 'IL'
    ORDER BY id
    LIMIT 1
);

-- If both IL and PS exist for the same customer, preserve the PS row and copy
-- the IL shipping code onto it when the PS row does not already have one.
UPDATE customer_country_shipping ccs_ps
JOIN customer_country_shipping ccs_il
  ON ccs_ps.customer_id = ccs_il.customer_id
 AND ccs_ps.country_id = @ps_id
 AND ccs_il.country_id = @il_id
SET ccs_ps.shipping_code = CASE
    WHEN (ccs_ps.shipping_code IS NULL OR TRIM(ccs_ps.shipping_code) = '')
     AND ccs_il.shipping_code IS NOT NULL
     AND TRIM(ccs_il.shipping_code) <> ''
    THEN ccs_il.shipping_code
    ELSE ccs_ps.shipping_code
END
WHERE @ps_id IS NOT NULL
  AND @il_id IS NOT NULL;

-- Remove duplicate IL rows before remapping the remaining references to PS.
DELETE ccs_il
FROM customer_country_shipping ccs_il
JOIN customer_country_shipping ccs_ps
  ON ccs_ps.customer_id = ccs_il.customer_id
 AND ccs_ps.country_id = @ps_id
 AND ccs_il.country_id = @il_id
WHERE @ps_id IS NOT NULL
  AND @il_id IS NOT NULL;

UPDATE customer_country_shipping
SET country_id = @ps_id
WHERE country_id = @il_id
  AND @ps_id IS NOT NULL
  AND @il_id IS NOT NULL;

UPDATE orders
SET destination_country_id = @ps_id
WHERE destination_country_id = @il_id
  AND @ps_id IS NOT NULL
  AND @il_id IS NOT NULL;

-- Normalize legacy text columns used by container destination resolution.
SET @container_dest_country_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'containers'
      AND column_name = 'destination_country'
);

SET @sql = IF(
    @container_dest_country_exists > 0,
    "UPDATE containers
     SET destination_country = CASE
         WHEN UPPER(TRIM(destination_country)) = 'IL' THEN 'PS'
         WHEN LOWER(TRIM(destination_country)) = 'israel' THEN 'Palestine'
         ELSE destination_country
     END
     WHERE destination_country IS NOT NULL
       AND (
           UPPER(TRIM(destination_country)) = 'IL'
           OR LOWER(TRIM(destination_country)) = 'israel'
       )",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @container_dest_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'containers'
      AND column_name = 'destination'
);

SET @sql = IF(
    @container_dest_exists > 0,
    "UPDATE containers
     SET destination = CASE
         WHEN UPPER(TRIM(destination)) = 'IL' THEN 'PS'
         WHEN LOWER(TRIM(destination)) = 'israel' THEN 'Palestine'
         ELSE destination
     END
     WHERE destination IS NOT NULL
       AND (
           UPPER(TRIM(destination)) = 'IL'
           OR LOWER(TRIM(destination)) = 'israel'
       )",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DELETE FROM countries
WHERE id = @il_id
  AND @il_id IS NOT NULL;
