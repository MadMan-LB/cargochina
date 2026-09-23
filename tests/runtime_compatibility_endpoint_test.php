<?php
require_once dirname(__DIR__).'/backend/config/database.php';
$pdo=getDb();
if ($pdo->query('SELECT DATABASE()')->fetchColumn()!=='clms_hardening_20260919') throw new RuntimeException('Disposable schema required');
if (($argv[1]??'')==='--worker') {
    session_start();
    $user=(int)$argv[2];$_SESSION=['user_id'=>$user,'user_roles'=>$user===1?['SuperAdmin']:['ContainersStaff']];
    $handler=require dirname(__DIR__).'/backend/api/handlers/diagnostics.php';
    try{$handler('GET','runtime-compatibility',null,[]);}catch(Throwable $e){jsonError($e->getMessage(),500);}
    exit;
}
function runtimeProbe(int $user):array {
    $pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'--worker',(string)$user],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($p)!==0||$err!=='')throw new RuntimeException($err.$out);
    $result=json_decode($out,true);if(!is_array($result))throw new RuntimeException($out);return $result;
}
$owner=runtimeProbe(1);$data=$owner['data']??null;
if (!is_array($data)||!isset($data['php'],$data['pdo_client'],$data['database']['version'],$data['database']['sql_mode'],$data['extensions']['pdo_mysql'])) throw new RuntimeException('Runtime diagnostics missing baseline fields');
if (preg_match('/password|secret|credential|api_key/i',json_encode($data))) throw new RuntimeException('Runtime diagnostics exposed secret-bearing fields');
$staff=runtimeProbe(4);
if (empty($staff['error'])||isset($staff['data'])) throw new RuntimeException('Ordinary staff accessed owner-only runtime diagnostics');
echo "PASS: owner runtime baseline and staff access denial\n";
