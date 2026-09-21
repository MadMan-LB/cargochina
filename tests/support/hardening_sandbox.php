<?php
/** Explicit disposable DB. Never copies operational cargo, finance or notification records. */
require_once dirname(__DIR__,2) . '/backend/config/database.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$db = 'clms_hardening_20260919';
$pdo = getDb();
$source = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if ($source === $db) throw new RuntimeException('Run sandbox setup against the source connection');
if (($argv[1]??'') === 'drop') {
    $pdo->exec("DROP DATABASE IF EXISTS `$db`"); echo "Disposable hardening database removed\n"; exit;
}
$exists = $pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=".$pdo->quote($db))->fetchColumn();
if (($argv[1]??'') === 'extend-references' && $exists) {
    foreach (['item_types','draft_order_cost_types','customer_country_shipping','hs_code_tariff_catalog'] as $table) $pdo->exec("INSERT IGNORE INTO `$db`.`$table` SELECT * FROM `$source`.`$table`");
    echo "Added missing non-operational reference data to disposable database\n";exit;
}
if ($exists) throw new RuntimeException('Sandbox already exists; reuse it or explicitly drop it');
$pdo->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$tables = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_COLUMN);
$pdo->exec("USE `$db`");
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($tables as $table) {
    $ddl=$pdo->query("SHOW CREATE TABLE `$source`.`$table`")->fetch(PDO::FETCH_NUM)[1];
    $pdo->exec($ddl);
}
$references = ['users','roles','permissions','user_roles','role_permissions','user_permissions','page_permissions','system_config','customers','suppliers','warehouses','departments','countries','hs_codes','hs_code_tariff_catalog','customer_portals','customer_country_shipping','item_types','draft_order_cost_types'];
foreach ($references as $table) if (in_array($table,$tables,true)) $pdo->exec("INSERT INTO `$db`.`$table` SELECT * FROM `$source`.`$table`");
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/backend/migrations/082_order_template_metrics.sql'));
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/backend/migrations/083_upload_asset_ownership.sql'));
$pdo->exec("UPDATE `$db`.system_config SET key_value='dashboard' WHERE key_name='NOTIFICATION_CHANNELS'");
$pdo->exec("UPDATE `$db`.system_config SET key_value='' WHERE key_name LIKE '%TOKEN%' OR key_name LIKE '%SECRET%' OR key_name LIKE '%PASSWORD%' OR key_name LIKE '%API_URL%' OR key_name LIKE '%API_BASE_URL%'");
$pdo->exec("UPDATE `$db`.system_config SET key_value='http://localhost:8099/cargochina' WHERE key_name='APP_URL'");
$pdo->exec("UPDATE `$db`.users SET email=CONCAT('operator-',id,'@example.invalid')");
echo "Disposable hardening schema created; operational tables empty; external delivery disabled\n";
