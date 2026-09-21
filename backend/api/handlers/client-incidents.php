<?php
require_once __DIR__.'/../helpers.php';require_once dirname(__DIR__,2).'/services/OwnerIncidentService.php';
return function(string $method,?string $id,?string $action,array $input):void{
 require_once __DIR__.'/../authorization.php';clmsAuthorizeApiRequest('client-incidents',$method,$id,$action);
 if($method!=='POST'||$id!==null)jsonError('Method not allowed',405);
 $code=$input['code']??'';$page=$input['page']??'';
 if(!in_array($code,['UI_RUNTIME_FAILURE','UI_UNHANDLED_FAILURE','UI_NETWORK_FAILURE'],true)||!is_string($page)||!preg_match('#^[a-zA-Z0-9_/-]+\.php$#',$page)||strlen($page)>100)jsonError('Invalid client incident metadata',422);
 $key=$code.'|'.$page;$now=time();$seen=$_SESSION['clms_client_incidents']??[];
 if(($seen[$key]??0)>$now-60)jsonResponse(['data'=>['recorded'=>false,'deduplicated'=>true]]);
 $seen=array_filter($seen,fn($t)=>$t>$now-60);if(count($seen)>=10)jsonResponse(['data'=>['recorded'=>false,'rate_limited'=>true]]);$seen[$key]=$now;$_SESSION['clms_client_incidents']=array_slice($seen,-30,null,true);
 $request=bin2hex(random_bytes(16));OwnerIncidentService::record(getDb(),['status'=>500,'code'=>$code,'workflow'=>str_replace(['.php','/'],['','_'],$page),'action'=>'UI','request_id'=>$request,'user_id'=>getAuthUserId(),'rollback'=>'not_needed']);jsonResponse(['data'=>['recorded'=>true,'request_id'=>$request]]);
};
