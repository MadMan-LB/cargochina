<?php
require_once dirname(__DIR__).'/backend/services/RetentionPolicyService.php';
require_once dirname(__DIR__).'/backend/services/LogRetentionService.php';
$now=time();$expired='clms_app|s:10:"cargochina";clms_expired_at|i:'.($now-91*86400).';';
if(!RetentionPolicyService::sessionPurgeEligible($expired,$now-91*86400,$now))throw new RuntimeException('Expired owned session not eligible');
foreach([$expired.'user_id|i:1;','clms_expired_at|i:1;','other_app|s:3:"abc";'] as $s)if(RetentionPolicyService::sessionPurgeEligible($s,$now-91*86400,$now))throw new RuntimeException('Active/unowned session eligible');
if(RetentionPolicyService::sessionPurgeEligible($expired,$now-1,$now))throw new RuntimeException('Recently touched session eligible');
$g=[];for($i=0;$i<50;$i++)$g[]=['id'=>$i,'timestamp'=>strtotime("-$i months",$now),'held'=>$i===49];$keep=RetentionPolicyService::keepBackups($g,$now);if(!in_array(49,$keep,true)||!in_array(0,$keep,true)||!in_array(11,$keep,true))throw new RuntimeException('Backup hold/monthly protection lost');
echo "PASS: 90-day marked session expiry, active/unowned protection, backup generations and holds\n";
$old=$now-100*86400;$filename='php_errors-'.gmdate('Y-m-d',$old).'.log';if(!LogRetentionService::expired($filename,$old,$now)||LogRetentionService::expired('clms-'.gmdate('Y-m-d',$old).'.log',$old,$now)||LogRetentionService::expired('php_errors.log',$old,$now)||LogRetentionService::expired($filename,$now,$now))throw new RuntimeException('Diagnostic/security/legacy log retention mismatch');
echo "PASS: dated log retention separates diagnostic/security categories and preserves unknown-age or newly modified logs\n";
