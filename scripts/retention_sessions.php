<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/backend/config/database.php';
require_once dirname(__DIR__).'/backend/services/RetentionPolicyService.php';
$p=getDb();$apply=in_array('--apply',$argv,true);$root=realpath(session_save_path());
if(!$root||!is_dir($root))throw new RuntimeException('Session directory unavailable');
$count=0;$held=0;
foreach(new DirectoryIterator($root) as $entry){
 if(!$entry->isFile()||$entry->isLink()||!preg_match('/^sess_[A-Za-z0-9,-]+$/',$entry->getFilename()))continue;
 $file=$entry->getRealPath();if(dirname($file)!==$root)continue;$f=fopen($file,'r+b');if(!$f)continue;
 if(!flock($f,LOCK_EX|LOCK_NB)){fclose($f);continue;}
 $stat=fstat($f);$contents=stream_get_contents($f,1048576);
 if(RetentionPolicyService::sessionPurgeEligible($contents,$stat['mtime'],time())){
  if(RetentionPolicyService::held($p,'expired_sessions','*'))$held++;else{$count++;if($apply){
    // Purge material under its exclusive lock. Leave an empty file for PHP GC;
    // unlink-after-unlock would race another writer on Windows.
    if(!ftruncate($f,0)||!fflush($f))throw new RuntimeException('Session material purge failed');
  }}
 }
 if(is_resource($f)){flock($f,LOCK_UN);fclose($f);}
}
echo json_encode(['mode'=>$apply?'apply':'dry_run','eligible_owned_expired_sessions'=>$count,'held'=>$held,'unmarked_sessions'=>'preserved']).PHP_EOL;
