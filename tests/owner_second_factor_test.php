<?php
require_once dirname(__DIR__).'/backend/services/OwnerSecondFactorService.php';require_once dirname(__DIR__).'/backend/services/CredentialEscrowService.php';
if(($argv[1]??'')==='--worker'){$file=$argv[2];putenv('CLMS_OWNER_CONFIG_FILE='.$file);$cfg=json_decode(file_get_contents($file),true);try{OwnerSecondFactorService::verify($cfg,1,OwnerSecondFactorService::code(base64_decode($cfg['owner_totp_keys'][1]),$cfg['test_step']));echo 'accepted';}catch(DomainException $e){echo 'rejected';}exit;}
foreach([59=>'287082',1111111109=>'081804',1111111111=>'050471',1234567890=>'005924',2000000000=>'279037',20000000000=>'353130'] as $time=>$expected)if(OwnerSecondFactorService::code('12345678901234567890',intdiv($time,30))!==$expected)throw new RuntimeException('RFC 6238 test vector mismatch');
if(CredentialEscrowService::safeDatabaseGrants(['GRANT ALL PRIVILEGES ON *.* TO user'],'clms'))throw new RuntimeException('Global DB privileges accepted');
if(CredentialEscrowService::safeDatabaseGrants(['GRANT FILE ON *.* TO user'],'clms'))throw new RuntimeException('FILE privilege accepted');
if(!CredentialEscrowService::safeDatabaseGrants(['GRANT USAGE ON *.* TO user','GRANT SELECT, INSERT, UPDATE, DELETE ON `clms`.* TO user'],'clms'))throw new RuntimeException('Restricted DB identity rejected');
$file=tempnam(sys_get_temp_dir(),'clms-factor-');$cfg=['test_step'=>intdiv(time(),30),'owner_totp_keys'=>[1=>base64_encode(random_bytes(20))]];file_put_contents($file,json_encode($cfg));$previous=getenv('CLMS_OWNER_CONFIG_FILE');putenv('CLMS_OWNER_CONFIG_FILE='.$file);
try{
 $workers=[];for($i=0;$i<2;$i++){$proc=proc_open([PHP_BINARY,__FILE__,'--worker',$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$workers[]=[$proc,$pipes];}
 $out=[];foreach($workers as [$proc,$pipes]){$out[]=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($proc)!==0||$err!=='')throw new RuntimeException('Second-factor worker failed');}sort($out);if($out!==['accepted','rejected'])throw new RuntimeException('Concurrent factor replay accepted');
 for($i=0;$i<5;$i++){try{OwnerSecondFactorService::verify($cfg,1,'not-a-code');throw new RuntimeException('Invalid factor accepted');}catch(DomainException $e){}}
 try{OwnerSecondFactorService::verify($cfg,1,OwnerSecondFactorService::code(base64_decode($cfg['owner_totp_keys'][1]),intdiv(time(),30)+1));throw new RuntimeException('Throttled factor accepted');}catch(DomainException $e){if($e->getCode()!==429)throw $e;}
 echo "PASS: RFC 6238 vectors, concurrent one-time-code replay, factor throttling and restricted DB grant boundary\n";
}finally{unlink($file);if(is_file($file.'.mfa-state'))unlink($file.'.mfa-state');putenv($previous===false?'CLMS_OWNER_CONFIG_FILE':'CLMS_OWNER_CONFIG_FILE='.$previous);}
