<?php
define('CLMS_ASSIGNMENT_FIXTURES_ONLY',true);require __DIR__.'/assignment_domain_test.php';
require_once dirname(__DIR__).'/backend/services/TrackingPushService.php';
assignmentAssert($pdo->query('SELECT DATABASE()')->fetchColumn()==='clms_hardening_20260919','Tracking fixtures require isolated database');
$config=require dirname(__DIR__).'/backend/config/config.php';assignmentAssert(empty($config['tracking_push_enabled']),'Real transport must be disabled');
class TrackingTransportFake extends TrackingPushService{
    public array $calls=[];public array $responses=[];
    public function __construct(PDO $pdo,array $overrides=[]){parent::__construct($pdo);$property=new ReflectionProperty(TrackingPushService::class,'config');$property->setValue($this,array_replace($property->getValue($this),['tracking_push_enabled'=>1,'tracking_push_dry_run'=>0,'tracking_api_base_url'=>'https://tracking.example.invalid','tracking_api_token'=>'disposable','tracking_api_retry_count'=>2,'tracking_api_retry_backoff_ms'=>0],$overrides));}
    protected function sendRequest(string $url,string $body,string $token,string $key,int $timeout):array{$this->calls[]=compact('url','body','key','timeout');if(!$this->responses)throw new LogicException('Unplanned fake transport call');return array_shift($this->responses);}
    protected function waitBeforeRetry(int $milliseconds):void{}
    protected function appendFileLog(int $draftId,array $payload,string $result,int $code=0,$extra=''):void{}
}
assignmentAssert((new TrackingTransportFake($pdo,['tracking_push_enabled'=>0]))->mode()==='disabled','Disabled mode projection incorrect');
assignmentAssert((new TrackingTransportFake($pdo,['tracking_push_dry_run'=>1]))->mode()==='dry_run','Dry-run mode projection incorrect');
assignmentAssert((new TrackingTransportFake($pdo))->mode()==='live','Live mode projection incorrect');
function trackingFixture(PDO $pdo):array{$f=assignmentFixture($pdo);$pdo->prepare("UPDATE shipment_drafts SET status='finalized',booking_number='BEY-OCT-2026' WHERE id=?")->execute([$f['drafts'][0]]);$pdo->prepare("UPDATE orders SET status='FinalizedAndPushedToTracking' WHERE id=?")->execute([$f['orders'][0]]);$pdo->prepare('UPDATE order_items SET unit_price=8,sell_price=10,total_amount=100 WHERE id=?')->execute([$f['items'][0]]);return $f;}
function trackingResponse(int $code,string $body,string $error=''):array{return compact('code','body','error');}
if(defined('CLMS_TRACKING_FIXTURES_ONLY'))return;
$f=trackingFixture($pdo);$draft=$f['drafts'][0];$svc=new TrackingTransportFake($pdo);$pdo->beginTransaction();$snapshot=$svc->prepare($draft);assignmentAssert($snapshot['status']==='pending'&&(int)$snapshot['attempt_count']===0&&!$svc->calls,'Snapshot sent transport');$pdo->rollBack();assignmentAssert(!$svc->getPushStatus($draft),'Rolled back snapshot leaked');
$snapshot=$svc->prepare($draft);$payload=json_decode($snapshot['request_payload'],true);assignmentAssert($payload['header']['booking_number']==='BEY-OCT-2026'&&$payload['items'][0]['unit_price']==10&&$payload['items'][0]['total_amount']==100&&!empty($payload['items'][0]['customer_id']),'Snapshot omitted canonical shipping relationships or price');
$pdo->prepare("UPDATE order_items SET description_en='Mutable legacy data changed after snapshot' WHERE id=?")->execute([$f['items'][0]]);
$svc->responses=[trackingResponse(503,'Unavailable'),trackingResponse(202,'{"accepted":true,"external_shipment_id":"BEY-2609-01"}')];$result=$svc->push($draft);assignmentAssert($result['success']===true&&count($svc->calls)===2,'Transient retry did not recover');assignmentAssert($svc->calls[0]['body']===$snapshot['request_payload']&&$svc->calls[1]['body']===$snapshot['request_payload']&&$svc->calls[0]['key']===$svc->calls[1]['key'],'Retry changed immutable payload/key');
$again=$svc->push($draft);assignmentAssert($again['success']===true&&count($svc->calls)===2,'Successful delivery was repeated');
echo "PASS: rollback-safe snapshot, canonical payload, immutable retry bytes/key and terminal success\n";
foreach(['rejected','html','empty','bad-reference','bad-request','timeout','rate-limit'] as $case){
    $f=trackingFixture($pdo);$svc=new TrackingTransportFake($pdo);$response=match($case){'rejected'=>trackingResponse(200,'{"success":false,"message":"Rejected"}'),'html'=>trackingResponse(200,'<html>login</html>'),'empty'=>trackingResponse(204,''),'bad-reference'=>trackingResponse(200,'{"id":[]}'),'bad-request'=>trackingResponse(422,'{"error":"Invalid"}'),'timeout'=>trackingResponse(0,'','Timed out'),default=>trackingResponse(429,'Busy')};
    $svc->responses=array_fill(0,3,$response);$failed=false;try{$svc->push($f['drafts'][0]);}catch(RuntimeException $e){$failed=true;}assignmentAssert($failed&&$svc->getPushStatus($f['drafts'][0])['status']==='failed','Unverified tracking response marked success: '.$case);$expected=in_array($case,['timeout','rate-limit'],true)?3:1;assignmentAssert(count($svc->calls)===$expected,'Wrong retry policy for '.$case);echo "PASS: fake transport $case\n";
}
$f=trackingFixture($pdo);$svc=new TrackingTransportFake($pdo,['tracking_api_base_url'=>'file:///etc/passwd']);$failed=false;try{$svc->push($f['drafts'][0]);}catch(RuntimeException $e){$failed=true;}assignmentAssert($failed&&!$svc->calls,'Unsafe protocol reached transport');
$f=trackingFixture($pdo);$svc=new TrackingTransportFake($pdo,['tracking_api_timeout_sec'=>9999,'tracking_api_retry_count'=>9999]);$svc->responses=array_fill(0,4,trackingResponse(503,'Unavailable'));try{$svc->push($f['drafts'][0]);}catch(RuntimeException $e){}assignmentAssert(count($svc->calls)===4&&$svc->calls[0]['timeout']===30,'Transport limits not bounded');
echo "PASS: endpoint protocol and bounded retry/timeout policy; no sockets, files or real sends\n";
