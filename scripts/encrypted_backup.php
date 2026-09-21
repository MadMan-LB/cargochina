<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/backend/services/EncryptedBackupService.php';
require_once dirname(__DIR__).'/backend/services/CredentialEscrowService.php';
// All paths are supplied by protected deployment configuration, never URL/API parameters.
$mode=$argv[1]??'';$file=getenv('CLMS_BACKUP_FILE')?:'';$keyFile=getenv('CLMS_BACKUP_KEY_FILE')?:'';
$db=getenv('CLMS_BACKUP_DB')?:'';$options=getenv('CLMS_MYSQL_OPTIONS_FILE')?:'';$bin=getenv('CLMS_MYSQL_BIN')?:'C:/xampp/mysql/bin';
function externalFile(string $path):string{$real=realpath($path);$web=strtolower(str_replace('\\','/',realpath(dirname(__DIR__,2))));if(!$real||str_starts_with(strtolower(str_replace('\\','/',$real)),$web.'/'))throw new RuntimeException('Protected file must exist outside the web root');return $real;}
try{
 if(!in_array($mode,['backup','backup-uploads','verify','restore'],true)||!preg_match('/^[a-zA-Z0-9_]+$/',$db))throw new RuntimeException('Invalid backup operation');
 $key=base64_decode(trim(file_get_contents(externalFile($keyFile))),true);if($key===false)throw new RuntimeException('Invalid recovery key');
 if(in_array($mode,['backup','backup-uploads'],true)){
  $dir=externalFile(dirname($file));if(file_exists($file))throw new RuntimeException('Refusing to overwrite a backup');
  $command=$mode==='backup-uploads'?['C:/Windows/System32/tar.exe','-C',dirname(__DIR__).'/backend/uploads','-cf','-','.']:[$bin.'/mysqldump.exe','--defaults-extra-file='.externalFile($options),'--single-transaction','--quick','--skip-lock-tables','--hex-blob','--routines','--triggers',$db];
  $out=fopen($file,'xb');$error=tmpfile();$proc=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>$error],$pipes);
  if(!is_resource($proc))throw new RuntimeException('Backup process unavailable');fclose($pipes[0]);
  try{EncryptedBackupService::encrypt($pipes[1],$out,$key);fclose($pipes[1]);if(proc_close($proc)!==0)throw new RuntimeException('Database backup failed');fflush($out);fclose($out);fclose($error);}
  catch(Throwable $e){if(is_resource($out))fclose($out);if(is_resource($proc))proc_terminate($proc);if(is_file($file))unlink($file);throw $e;}
  $manifest=['format'=>'CLMSBK01','content'=>$mode==='backup'?'database_sql':'uploads_tar','database'=>$db,'created_at'=>gmdate('c'),'ciphertext_sha256'=>hash_file('sha256',$file),'bytes'=>filesize($file),'off_host_verified'=>false];
  if(file_put_contents($file.'.json',json_encode($manifest,JSON_PRETTY_PRINT),LOCK_EX)===false)throw new RuntimeException('Backup manifest write failed');echo "Encrypted backup created; off-host verification still required.\n";
 }else{
  $in=fopen(externalFile($file),'rb');if(!flock($in,LOCK_SH))throw new RuntimeException('Backup lock failed');EncryptedBackupService::decrypt($in,null,$key);rewind($in);
  if($mode==='verify'){echo "Authenticated archive verified; this is not a database restore proof.\n";}
  else{
   if(!preg_match('/^clms_restore_[a-z0-9_]+$/',$db))throw new RuntimeException('Restore requires a separately created disposable clms_restore_* database');
   // A recipient public key can encrypt arbitrary SQL. Authentication of the archive
   // does not authorize that SQL to touch other databases: enforce DB isolation too.
   if(getenv('APP_ENV')!=='testing'){
    $check=proc_open([$bin.'/mysql.exe','--defaults-extra-file='.externalFile($options),'-N','-B','-e','SHOW GRANTS FOR CURRENT_USER()'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$cp);if(!is_resource($check))throw new RuntimeException('Cannot verify restore identity');fclose($cp[0]);$grants=stream_get_contents($cp[1]);stream_get_contents($cp[2]);fclose($cp[1]);fclose($cp[2]);$exit=proc_close($check);
    if($exit!==0||!CredentialEscrowService::safeDatabaseGrants(array_filter(explode("\n",trim($grants))),$db))throw new RuntimeException('Restore credentials must be restricted to this disposable database');
   }
   $error=tmpfile();$proc=proc_open([$bin.'/mysql.exe','--defaults-extra-file='.externalFile($options),$db],[0=>['pipe','r'],1=>$error,2=>$error],$pipes);
   if(!is_resource($proc))throw new RuntimeException('Restore process unavailable');
   try{EncryptedBackupService::decrypt($in,$pipes[0],$key);fclose($pipes[0]);if(proc_close($proc)!==0)throw new RuntimeException('Isolated restore failed; discard disposable database');fclose($error);}catch(Throwable $e){if(is_resource($proc))proc_terminate($proc);throw $e;}
   echo "Isolated database restored. Compare application records before accepting recovery.\n";
  }flock($in,LOCK_UN);fclose($in);
 }
}catch(Throwable $e){fwrite(STDERR,$e instanceof SodiumException?'Backup cryptographic validation failed.\n':$e->getMessage()."\n");exit(1);}
