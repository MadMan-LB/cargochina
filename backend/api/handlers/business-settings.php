<?php

/**
 * Business Settings API - ETA offsets, notification thresholds, etc.
 * SuperAdmin only
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__,2).'/services/AuditService.php';
require_once dirname(__DIR__,2).'/services/SettingsWriteService.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    require_once __DIR__ . '/../authorization.php';
    clmsAuthorizeApiRequest('business-settings', $method, $id, $action);
    $pdo = getDb();
    if (!getAuthUserId()) jsonError('Unauthorized', 401);
    if (!hasAnyRole(['SuperAdmin'])) jsonError('Forbidden', 403);

    switch ($method) {
        case 'GET':
            $stmt = $pdo->query("SELECT key_name, key_value FROM business_settings");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $data = [];
            foreach ($rows as $r) {
                $data[$r['key_name']] = $r['key_value'];
            }
            jsonResponse(['data' => $data, 'revision'=>SettingsWriteService::revision($data)]);

        case 'PUT':
        case 'POST':
            $config = $input['config'] ?? $input;
            if (!is_array($config)||empty($config)) jsonError('No config provided', 400);
            $allowed = ['ETA_OFFSETS_JSON', 'ARRIVAL_NOTIFY_DAYS', 'CONTAINER_20HQ_CBM', 'CONTAINER_40HQ_CBM', 'CONTAINER_45HQ_CBM', 'SHIPPING_CODE_DUPLICATE_ACTION'];
            foreach($config as $key=>$value){
                if(!in_array($key,$allowed,true))jsonError('Unknown business setting',422);
                if(str_starts_with($key,'CONTAINER_')){if(!is_scalar($value)||is_bool($value)||!is_numeric($value)||!is_finite((float)$value)||(float)$value<=0||(float)$value>1000)jsonError('Invalid container capacity preset',422);}
                if($key==='ARRIVAL_NOTIFY_DAYS') {try{$config[$key]=implode(',',SettingsWriteService::arrivalDays($value));}catch(InvalidArgumentException $e){jsonError($e->getMessage(),422);}}
                if($key==='ETA_OFFSETS_JSON'){
                    $offsets=is_string($value)?json_decode($value,true):$value;if(!is_array($offsets)||!$offsets)jsonError('Invalid ETA offsets',422);
                    foreach($offsets as $country=>$modes){if(!preg_match('/^(?:[A-Z]{2}|DEFAULT)$/',(string)$country)||!is_array($modes))jsonError('Invalid ETA country',422);foreach($modes as $mode=>$days)if(!in_array($mode,['groupage','full_container','special'],true)||filter_var($days,FILTER_VALIDATE_INT)===false||abs((int)$days)>365)jsonError('Invalid ETA offset',422);}
                    $config[$key]=json_encode($offsets,JSON_THROW_ON_ERROR);
                }
            }
            if (isset($config['SHIPPING_CODE_DUPLICATE_ACTION']) && !in_array($config['SHIPPING_CODE_DUPLICATE_ACTION'], ['warn', 'block'], true)) {
                jsonError('SHIPPING_CODE_DUPLICATE_ACTION must be warn or block', 400);
            }
            AuditService::begin($pdo);
            $before=[];foreach($pdo->query('SELECT key_name,key_value FROM business_settings FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC) as $r)$before[$r['key_name']]=$r['key_value'];
            SettingsWriteService::assertCurrent($before,$input);
            foreach ($config as $k => $v) {
                if (!in_array($k, $allowed, true)) continue;
                $pdo->prepare("INSERT INTO business_settings (key_name, key_value) VALUES (?,?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)")
                    ->execute([$k, is_string($v) ? $v : json_encode($v)]);
            }
            $stmt = $pdo->query("SELECT key_name, key_value FROM business_settings");
            $data = [];
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $data[$r['key_name']] = $r['key_value'];
            AuditService::record($pdo,'business_settings',0,'update',$before,$data,getAuthUserId());$pdo->commit();
            jsonResponse(['data' => $data, 'revision'=>SettingsWriteService::revision($data)]);

        default:
            jsonError('Method not allowed', 405);
    }
};
