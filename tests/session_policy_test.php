<?php
require_once dirname(__DIR__).'/backend/services/SessionPolicyService.php';
function policyCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$user=['session_version'=>4,'password_hash'=>password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT)];
$fresh=function(int $issued=100000)use($user){$s=['user_id'=>1];SessionPolicyService::establish($s,4,hash('sha256',$user['password_hash']),$issued);return $s;};
foreach([[['WarehouseStaff'],28800,2592000],[['SuperAdmin'],1800,43200],[['ChinaAdmin'],1800,43200],[['LebanonAdmin'],1800,43200]] as [$roles,$idle,$absolute]){
 $s=$fresh();policyCheck(SessionPolicyService::validate($s,$user,$roles,100000+$idle-1),'Before idle boundary');
 $s=$fresh();policyCheck(!SessionPolicyService::validate($s,$user,$roles,100000+$idle),'Exact idle boundary');
 $s=$fresh();$s['clms_session']['last_activity']=100000+$absolute-1;policyCheck(!SessionPolicyService::validate($s,$user,$roles,100000+$absolute),'Exact absolute boundary');
}
$s=$fresh();policyCheck(SessionPolicyService::validate($s,$user,['SuperAdmin'],100100,false),'Background request still authorized');policyCheck($s['clms_session']['last_activity']===100000,'Poll must not extend idle');
policyCheck(!SessionPolicyService::validate($s,$user,['SuperAdmin'],101800,false),'Polling must not keep session alive');
foreach(['version','credential','issued_at','last_activity'] as $key){$s=$fresh();$s['clms_session'][$key]='invalid';policyCheck(!SessionPolicyService::validate($s,$user,[],100001),'Malformed '.$key);policyCheck(!isset($s['user_id']),'Invalid session cleared');}
$s=$fresh();$changed=$user;$changed['session_version']++;policyCheck(!SessionPolicyService::validate($s,$changed,[],100001),'Version revocation');
$s=$fresh();$changed=$user;$changed['password_hash']='changed';policyCheck(!SessionPolicyService::validate($s,$changed,[],100001),'Credential replacement');
$s=$fresh();policyCheck(!SessionPolicyService::validate($s,$user,['SuperAdmin'],102000),'Promotion applies shorter live limits');
$s=$fresh();policyCheck(!SessionPolicyService::validate($s,$user,[],100001,true,'different_database'),'Session crossed database boundary');
$s=$fresh();policyCheck(SessionPolicyService::recent($s,100899)&&!SessionPolicyService::recent($s,100900),'Recent authentication boundary');
echo "PASS: session idle/absolute, polling, revocation, malformed state, role promotion, recent authentication\n";
