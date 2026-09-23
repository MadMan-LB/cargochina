-- Migration 062: balance sidebar updates are decoded and applied by run.php.
-- MySQL 5.5 cannot execute JSON_EXTRACT/JSON_SET. Use the runner, not raw SQL.
SELECT 1;
