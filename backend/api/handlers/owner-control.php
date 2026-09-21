<?php
require_once __DIR__.'/../helpers.php';
require_once dirname(__DIR__,2).'/services/OwnerAccessService.php';
require_once dirname(__DIR__,2).'/services/CredentialEscrowService.php';
return function(string $method,?string $id,?string $action,array $input):void{
 require_once __DIR__.'/../authorization.php';clmsAuthorizeApiRequest('owner-control',$method,$id,$action);
 $p=getDb();$actor=(int)getAuthUserId();
 try{OwnerAccessService::requireOwner($p,$actor);}catch(DomainException $e){jsonError($e->getMessage(),403);}
 header('Cache-Control: no-store, private, max-age=0');header('Pragma: no-cache');
 if($method==='GET'&&$id==='summary'){
  $counts=$p->query("SELECT severity,COUNT(*) n FROM owner_incidents WHERE status IN ('New','Investigating','System Bug') GROUP BY severity")->fetchAll();
  $recent=$p->query('SELECT id,email,full_name,last_login_at FROM users WHERE last_login_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) ORDER BY last_login_at DESC LIMIT 30')->fetchAll();
  $security=$p->query("SELECT id,user_id,entity_id,action,created_at FROM audit_log WHERE entity_type='user' AND action IN ('credential_reveal','credential_reveal_attempt','password_reset','offline_credential_rotation','update') ORDER BY id DESC LIMIT 30")->fetchAll();
  $failed=(int)$p->query("SELECT COUNT(*) FROM owner_incident_events e JOIN owner_incidents i ON i.id=e.incident_id WHERE i.workflow='auth' AND i.error_code IN ('HTTP_401','HTTP_429') AND e.created_at>=DATE_SUB(NOW(),INTERVAL 1 DAY)")->fetchColumn();
  $integration=['tracking_failed'=>(int)$p->query("SELECT COUNT(*) FROM tracking_push_log WHERE status='failed'")->fetchColumn(),'notifications_failed'=>(int)$p->query("SELECT COUNT(*) FROM notification_delivery_log WHERE status='failed'")->fetchColumn()];
  jsonResponse(['data'=>['open_incidents'=>$counts,'recent_users'=>$recent,'security_events'=>$security,'integration_failures'=>$integration,'failed_login_attempts_24h'=>$failed,'active_users'=>(int)$p->query('SELECT COUNT(*) FROM users WHERE is_active=1')->fetchColumn()]]);
 }
 if($method==='GET'&&$id==='users'){
  $cfg=OwnerAccessService::config();$recoveryReady=CredentialEscrowService::ready($p,$cfg,$actor);$users=$p->query("SELECT u.id,u.email,u.full_name,u.is_active,u.last_login_at,(SELECT GROUP_CONCAT(r.code ORDER BY r.code) FROM roles r JOIN user_roles ur ON ur.role_id=r.id WHERE ur.user_id=u.id) roles,EXISTS(SELECT 1 FROM credential_escrow e WHERE e.user_id=u.id AND e.credential_fingerprint=SHA2(u.password_hash,256)) current_escrow FROM users u ORDER BY u.full_name,u.id")->fetchAll();
  foreach($users as &$u){$eligible=in_array((int)$u['id'],$cfg['escrow_user_ids']??[],true)&&!in_array((int)$u['id'],$cfg['owner_ids']??[],true);$u['recovery_status']=!$eligible?'Not eligible':(!(int)$u['current_escrow']?'Not enrolled':(!$recoveryReady?'Disabled: key/factor/audit verification required':'Available'));$u['can_reveal']=$u['recovery_status']==='Available'&&(int)$u['is_active']===1;unset($u['current_escrow']);}unset($u);
  jsonResponse(['data'=>$users]);
 }
 if($method==='POST'&&ctype_digit($id??'')&&$action==='reveal'){
  if(getenv('APP_ENV')!=='testing'&&(!isset($_SERVER['HTTPS'])||$_SERVER['HTTPS']==='off'))jsonError('Credential recovery requires HTTPS',403);
  requireRecentAuthentication();
  if(!is_string($input['reauth_password']??null)||strlen($input['reauth_password'])>4096||!is_string($input['reason']??null)||!is_string($input['verification_code']??null))jsonError('Invalid recovery request',422);
  try{$plain=CredentialEscrowService::reveal($p,$actor,(int)$id,$input['reauth_password'],$_SESSION,$input['reason'],$input['verification_code']);}
  catch(DomainException $e){jsonError($e->getMessage(),in_array($e->getCode(),[400,403,409,422,429,503],true)?$e->getCode():403);}
  catch(Throwable $e){jsonError('Credential recovery unavailable; nothing was revealed',503);}
  jsonResponse(['data'=>['credential'=>$plain,'clear_after_seconds'=>20]]);
 }
 if($method==='GET'&&$id==='incidents'){
  foreach(['q','status','severity','workflow','page'] as $key)if(isset($_GET[$key])&&!is_string($_GET[$key]))jsonError('Invalid owner filter format',422);
  if(isset($_GET['page'])&&(!ctype_digit($_GET['page'])||(int)$_GET['page']<1))jsonError('Invalid owner page',422);
  $where=[];$params=[];
  foreach(['status','severity','workflow'] as $f)if(isset($_GET[$f])&&is_string($_GET[$f])&&$_GET[$f]!==''){$where[]="$f=?";$params[]=substr($_GET[$f],0,80);}
  if(($q=trim((string)($_GET['q']??'')))!==''){$where[]='(safe_message LIKE ? OR workflow LIKE ? OR error_code LIKE ?)';$like='%'.addcslashes(substr($q,0,100),'%_\\').'%';array_push($params,$like,$like,$like);}
  $sql=$where?' WHERE '.implode(' AND ',$where):'';$page=max(1,(int)($_GET['page']??1));$offset=min(100000,($page-1)*25);
  $s=$p->prepare('SELECT COUNT(*) FROM owner_incidents'.$sql);$s->execute($params);$total=(int)$s->fetchColumn();
  $s=$p->prepare('SELECT i.*,(SELECT COUNT(DISTINCT e.user_id) FROM owner_incident_events e WHERE e.incident_id=i.id) affected_users FROM owner_incidents i'.$sql." ORDER BY FIELD(severity,'CRITICAL','HIGH','MEDIUM','LOW'),last_seen DESC,id DESC LIMIT 25 OFFSET $offset");$s->execute($params);jsonResponse(['data'=>$s->fetchAll(),'total'=>$total,'page'=>$page]);
 }
 if(ctype_digit($id??'')&&$action==='incident'){
  if($method==='GET'){$s=$p->prepare('SELECT * FROM owner_incidents WHERE id=?');$s->execute([$id]);$row=$s->fetch();if(!$row)jsonError('Incident not found',404);$s=$p->prepare('SELECT * FROM owner_incident_events WHERE incident_id=? ORDER BY id DESC LIMIT 100');$s->execute([$id]);jsonResponse(['data'=>['incident'=>$row,'events'=>$s->fetchAll()]]);}
  if($method==='PUT'){
   requireRecentAuthentication();$state=$input['status']??'';$notes=$input['notes']??'';
   if(!isset($input['revision'])||!(is_int($input['revision'])||(is_string($input['revision'])&&ctype_digit($input['revision'])))||((int)$input['revision'])<1)jsonError('Valid incident revision required',422);
   if(!in_array($state,['New','Investigating','User Error','System Bug','Resolved'],true)||!is_string($notes)||strlen($notes)>2000)jsonError('Invalid incident resolution',422);
   // Owner notes are plain operational text; reject obvious credential material.
   if(preg_match('/(?:password|secret|bearer|token|api[_ -]?key)\s*[:=]/i',$notes))jsonError('Do not include credentials in incident notes',422);
   AuditService::begin($p);$s=$p->prepare('SELECT status,owner_notes,revision FROM owner_incidents WHERE id=? FOR UPDATE');$s->execute([$id]);$old=$s->fetch();
   if(!$old){$p->rollBack();jsonError('Incident not found',404);}if((int)($input['revision']??0)!==(int)$old['revision']){$p->rollBack();jsonError('Incident changed; reopen before saving',409);}
   $p->prepare('UPDATE owner_incidents SET status=?,owner_notes=?,revision=revision+1 WHERE id=?')->execute([$state,$notes,$id]);AuditService::record($p,'owner_incident',(int)$id,'resolution',$old,['status'=>$state,'owner_notes'=>$notes],$actor);$p->commit();jsonResponse(['data'=>['saved'=>true]]);
  }
 }
 jsonError('Unsupported owner action',405);
};
