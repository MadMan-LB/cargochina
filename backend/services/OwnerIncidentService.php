<?php
require_once __DIR__.'/AuditService.php';
/** Allowlisted metadata only. Never accepts request bodies, headers, exception text or stacks. */
final class OwnerIncidentService
{
    public static function visibleError(string $message,array $secrets):string
    {
        usort($secrets,fn($a,$b)=>strlen($b)<=>strlen($a));foreach($secrets as $secret)if(is_string($secret)&&$secret!=='')$message=str_replace($secret,'[redacted]',$message);
        $message=preg_replace('/(?:bearer\s+\S+|(?:password|secret|token|api[_ -]?key|verification_code)\s*[:=]\s*[^\s,;]+)/i','[redacted]',$message)??'';
        return mb_substr(preg_replace('/[\x00-\x1f]/',' ',$message)??'',0,1000);
    }
    public static function classification(int $status,string $code=''): array
    {
        if(in_array($code,['ROLLBACK_FAILED','TRANSACTION_INTEGRITY','DATABASE_UNAVAILABLE'],true))return ['CRITICAL','Infrastructure / integrity'];
        if($status>=500)return ['HIGH','System error'];
        if(in_array($status,[401,403,429],true))return ['MEDIUM','Permission / authentication'];
        return ['LOW','Workflow / validation'];
    }
    public static function record(PDO $pdo,array $event): int
    {
        $status=(int)($event['status']??500);$code=preg_replace('/[^A-Z0-9_]/','',strtoupper($event['code']??'HTTP_'.$status));
        [$severity,$category]=self::classification($status,$code);
        $workflow=preg_replace('/[^a-z0-9_-]/i','',substr($event['workflow']??'unknown',0,80));
        $action=preg_replace('/[^a-z0-9_\/-]/i','',substr($event['action']??'unknown',0,120));
        // Messages are controlled summaries, never arbitrary input/error text.
        $message=$status>=500?'Unexpected request failure; use request ID to investigate.':($status===403?'Access denied; verify permissions and configuration.':($status===401?'Sign-in required or failed.':($status===429?'Too many attempts; wait before retrying.':'Workflow validation rejected the operation; review required fields and record state.')));
        if(!empty($event['visible_error']))$message=self::visibleError($event['visible_error'],$event['secrets']??[]);
        $fingerprint=hash('sha256',implode('|',[$workflow,$action,$code,$category]));
        $request=$event['request_id']??bin2hex(random_bytes(16));if(!preg_match('/^[a-f0-9]{16,32}$/',$request))$request=bin2hex(random_bytes(16));
        $pdo->beginTransaction();
        try{
            $s=$pdo->prepare('SELECT incident_id FROM owner_incident_events WHERE request_id=?');$s->execute([$request]);if($existing=$s->fetchColumn()){$pdo->rollBack();return (int)$existing;}
            $pdo->prepare("INSERT INTO owner_incidents(fingerprint,severity,category,workflow,action_name,error_code,safe_message) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),occurrences=occurrences+1,last_seen=CURRENT_TIMESTAMP,revision=revision+1,safe_message=VALUES(safe_message),status=IF(status='Resolved','New',status)")->execute([$fingerprint,$severity,$category,$workflow,$action,$code,$message]);$id=(int)$pdo->lastInsertId();
            $user=null;$uid=(int)($event['user_id']??0);$roles='';
            if($uid){$s=$pdo->prepare('SELECT email,full_name FROM users WHERE id=?');$s->execute([$uid]);$user=$s->fetch();$s=$pdo->prepare('SELECT r.code FROM roles r JOIN user_roles ur ON ur.role_id=r.id WHERE ur.user_id=?');$s->execute([$uid]);$roles=implode(',',$s->fetchAll(PDO::FETCH_COLUMN));}
            $rollback=in_array($event['rollback']??'', ['not_needed','rolled_back','failed','unknown','committed'],true)?$event['rollback']:'unknown';
            $summary='HTTP '.$status;foreach(($event['entities']??[]) as $field=>$value)if(in_array($field,['order_id','container_id','shipment_draft_id','receipt_id','customer_id'],true)&&is_int($value))$summary.='; '.$field.'='.$value;
            $pdo->prepare('INSERT INTO owner_incident_events(incident_id,request_id,user_id,username,display_name,roles,entity_type,entity_id,rollback_state,context_summary) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$id,$request,$user?$uid:null,substr($user['email']??'',0,255),substr($user['full_name']??'',0,255),substr($roles,0,255),$workflow,ctype_digit((string)($event['entity_id']??''))?(int)$event['entity_id']:null,$rollback,substr($summary,0,500)]);
            $pdo->commit();return $id;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($e instanceof PDOException&&$e->getCode()==='23000'){$s=$pdo->prepare('SELECT incident_id FROM owner_incident_events WHERE request_id=?');$s->execute([$request]);if($existing=$s->fetchColumn())return (int)$existing;}throw $e;}
    }
    public static function capture(int $status,string $request,string $visibleError=''): void
    {
        if(!empty($GLOBALS['clms_incident_captured']))return;$GLOBALS['clms_incident_captured']=true;
        $path=explode('/',trim($_GET['path']??'','/'));
        $event=['status'=>$status,'request_id'=>$request,'workflow'=>$path[0]??'unknown','entity_id'=>$path[1]??null,'action'=>($_SERVER['REQUEST_METHOD']??'GET').'/'.($path[2]??''),'user_id'=>$_SESSION['user_id']??($GLOBALS['clms_auth_failed_user_id']??null),'rollback'=>'unknown','code'=>$GLOBALS['clms_incident_code']??'HTTP_'.$status];
        if($status>=400&&$status<500&&!in_array($status,[401,403,429],true)){
            foreach(['STALE_RECORD'=>'/stale|changed.*reopen|revision/i','CARGO_NOT_ELIGIBLE'=>'/incomplete|not fully|eligible/i','CAPACITY_VALIDATION'=>'/capacity|cbm|weight/i','RECEIVING_VALIDATION'=>'/receiv|carton|quantity/i','INPUT_VALIDATION'=>'/required|invalid|format/i'] as $code=>$pattern)if(preg_match($pattern,$visibleError)){$event['code']=$code;break;}
        }
        $event['entities']=$GLOBALS['clms_incident_entities']??[];
        $event['visible_error']=self::visibleError($visibleError,$GLOBALS['clms_incident_secrets']??[]);
        if(!ctype_digit((string)($event['entity_id']??''))&&$event['entities'])$event['entity_id']=reset($event['entities']);
        // Runs only after the failed response. Uses a separate connection; never commits a business transaction.
        register_shutdown_function(static function()use($event){
            try{
                $business=getDb();
                if($business->inTransaction()){try{$business->rollBack();$event['rollback']='rolled_back';}catch(Throwable $e){$event['rollback']='failed';$event['code']='ROLLBACK_FAILED';}}
                elseif(isset($GLOBALS['clms_rollback_state']))$event['rollback']=$GLOBALS['clms_rollback_state'];
                $p=clmsNewDbConnection();self::record($p,$event);
            }catch(Throwable $e){error_log('CLMS incident persistence unavailable; request='.$event['request_id']);}
        });
    }
}
