<?php
require_once __DIR__.'/../helpers.php';

function notificationPreferenceState(PDO $pdo,int $userId): array
{
    $state=[];
    foreach(['dashboard','email','whatsapp'] as $channel)foreach(['order_submitted','order_approved','order_received','variance_confirmation','shipment_finalized'] as $event)$state[$channel.':'.$event]=['channel'=>$channel,'event_type'=>$event,'enabled'=>1];
    $s=$pdo->prepare('SELECT channel,event_type,enabled FROM user_notification_preferences WHERE user_id=?');$s->execute([$userId]);
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row){$key=$row['channel'].':'.$row['event_type'];if(isset($state[$key])&&$row['channel']!=='dashboard')$state[$key]['enabled']=(int)(bool)$row['enabled'];}
    ksort($state);return $state;
}
function notificationPreferenceRevision(array $state): string {return hash('sha256',json_encode(array_values($state)));}

return function(string $method,?string $id,?string $action,array $input){
    require_once __DIR__.'/../authorization.php';clmsAuthorizeApiRequest('notification-preferences',$method,$id,$action);
    $userId=requireAuth();$pdo=getDb();
    if($method==='GET'){$state=notificationPreferenceState($pdo,$userId);jsonResponse(['data'=>array_values($state),'revision'=>notificationPreferenceRevision($state)]);}
    if($method!=='PUT')jsonError('Method not allowed',405);
    if(!is_array($input['preferences']??null)||!array_is_list($input['preferences'])||count($input['preferences'])>15)jsonError('Preferences must be a bounded list',422);
    if(!is_string($input['revision']??null)||!preg_match('/^[a-f0-9]{64}$/',$input['revision']))jsonError('Reload preferences before saving',409);
    $pdo->beginTransaction();register_shutdown_function(static function()use($pdo){if($pdo->inTransaction())$pdo->rollBack();});
    $s=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$s->execute([$userId]);
    $old=notificationPreferenceState($pdo,$userId);$new=$old;$seen=[];
    foreach($input['preferences'] as $p){
        if(!is_array($p)||!is_string($p['channel']??null)||!is_string($p['event_type']??null)||!in_array($p['enabled']??null,[true,false,0,1,'0','1'],true))jsonError('Invalid preference value',422);
        $key=$p['channel'].':'.$p['event_type'];if(!isset($new[$key])||isset($seen[$key]))jsonError('Unknown or duplicate preference',422);$seen[$key]=true;
        if($p['channel']==='dashboard'&&!$p['enabled'])jsonError('Dashboard notifications must remain enabled',422);
        $new[$key]['enabled']=(int)(bool)$p['enabled'];
    }
    if(!hash_equals(notificationPreferenceRevision($old),$input['revision'])&&$new!==$old)jsonError('Preferences changed in another request; reload before saving',409);
    if($new!==$old){
        $s=$pdo->prepare('INSERT INTO user_notification_preferences(user_id,channel,event_type,enabled) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled)');
        foreach($new as $p)$s->execute([$userId,$p['channel'],$p['event_type'],$p['enabled']]);
        $pdo->prepare("INSERT INTO audit_log(entity_type,entity_id,action,old_value,new_value,user_id) VALUES ('notification_preferences',?,'update',?,?,?)")->execute([$userId,json_encode(array_values($old)),json_encode(array_values($new)),$userId]);
    }
    $pdo->commit();jsonResponse(['data'=>array_values($new),'revision'=>notificationPreferenceRevision($new)]);
};
