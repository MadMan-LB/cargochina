<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/backend/config/database.php';require_once dirname(__DIR__).'/backend/services/RetentionPolicyService.php';require_once dirname(__DIR__).'/backend/services/LogRetentionService.php';
$root=realpath(dirname(__DIR__).'/logs');$p=getDb();$apply=in_array('--apply',$argv,true);$eligible=[];$held=[];
foreach(new DirectoryIterator($root) as $entry){if(!$entry->isFile()||$entry->isLink()||!LogRetentionService::expired($entry->getFilename(),$entry->getMTime(),time()))continue;$file=$entry->getRealPath();if(dirname($file)!==$root)throw new RuntimeException('Log target escaped directory');if(RetentionPolicyService::held($p,'logs',$entry->getFilename())){$held[]=$entry->getFilename();continue;}$eligible[]=$entry->getFilename();if($apply&&!unlink($file))throw new RuntimeException('Log cleanup failed');}
echo json_encode(['mode'=>$apply?'apply':'dry_run','eligible'=>$eligible,'held'=>$held,'legacy_undated_logs'=>'preserved pending dated inventory']).PHP_EOL;
