<?php
require_once __DIR__.'/../helpers.php';
require_once dirname(__DIR__,2).'/services/CargoCalendarService.php';
return function(string $method,?string $id,?string $action,array $input):void {
    if(!getAuthUserId())jsonError('Unauthorized',401);
    if($method!=='GET'||$id!==null)jsonError('Method not allowed',405);
    if(!hasPageAccess('calendar'))jsonError('Calendar access required',403);
    // A calendar page grant alone must not grant access to the underlying records.
    $orders=hasPermission('orders.read')&&hasPageAccess('orders','receiving','warehouse_stock','procurement_drafts');
    $containers=hasPermission('containers.read')&&hasPageAccess('containers','consolidation','assign_container');
    try {
        $f=CargoCalendarService::filters($_GET);
        $events=CargoCalendarService::events(getDb(),$f,$orders,$containers);
        foreach($events as &$event){
            if($event['record_type']!=='container'&&!hasPageAccess('orders'))$event['link']=hasPageAccess('receiving')?'/cargochina/receiving.php':null;
            if($event['record_type']==='container'&&!hasPageAccess('containers'))$event['link']=hasPageAccess('consolidation')?'/cargochina/consolidation.php':null;
        }unset($event);
        jsonResponse(['data'=>$events,'meta'=>['from'=>$f['from'],'to_exclusive'=>$f['to'],'total'=>count($events),'labels'=>CargoCalendarService::LABELS,'orders_visible'=>$orders,'containers_visible'=>$containers]]);
    }catch(DomainException $e){jsonError($e->getMessage(),$e->getCode()?:422);}
};
