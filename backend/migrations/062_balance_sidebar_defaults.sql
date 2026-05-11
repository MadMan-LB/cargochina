-- Migration 062: Add Balances to existing role sidebar settings.
-- Rollback: manage ROLE_SIDEBAR_PAGES_JSON from Admin > Users if needed.

SET @sidebar_cfg := (
  SELECT key_value
  FROM system_config
  WHERE key_name = 'ROLE_SIDEBAR_PAGES_JSON'
  LIMIT 1
);

SET @china_admin_pages := COALESCE(JSON_EXTRACT(@sidebar_cfg, '$.ChinaAdmin'), JSON_ARRAY());
SET @china_admin_pages := IF(
  JSON_CONTAINS(@china_admin_pages, JSON_QUOTE('balances')),
  @china_admin_pages,
  JSON_ARRAY_APPEND(@china_admin_pages, '$', 'balances')
);

SET @china_employee_pages := COALESCE(JSON_EXTRACT(@sidebar_cfg, '$.ChinaEmployee'), JSON_ARRAY());
SET @china_employee_pages := IF(
  JSON_CONTAINS(@china_employee_pages, JSON_QUOTE('balances')),
  @china_employee_pages,
  JSON_ARRAY_APPEND(@china_employee_pages, '$', 'balances')
);

SET @lebanon_admin_pages := COALESCE(JSON_EXTRACT(@sidebar_cfg, '$.LebanonAdmin'), JSON_ARRAY());
SET @lebanon_admin_pages := IF(
  JSON_CONTAINS(@lebanon_admin_pages, JSON_QUOTE('balances')),
  @lebanon_admin_pages,
  JSON_ARRAY_APPEND(@lebanon_admin_pages, '$', 'balances')
);

UPDATE system_config
SET key_value = JSON_SET(
  key_value,
  '$.ChinaAdmin', JSON_EXTRACT(@china_admin_pages, '$'),
  '$.ChinaEmployee', JSON_EXTRACT(@china_employee_pages, '$'),
  '$.LebanonAdmin', JSON_EXTRACT(@lebanon_admin_pages, '$')
)
WHERE key_name = 'ROLE_SIDEBAR_PAGES_JSON'
  AND @sidebar_cfg IS NOT NULL
  AND JSON_VALID(key_value);
