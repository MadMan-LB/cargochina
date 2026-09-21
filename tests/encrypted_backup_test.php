<?php
if(!extension_loaded('sodium')){$p=proc_open([PHP_BINARY,'-d','extension=sodium',__FILE__],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);exit(proc_close($p));}
require_once dirname(__DIR__).'/backend/services/EncryptedBackupService.php';
$pair=sodium_crypto_box_keypair();$public=sodium_crypto_box_publickey($pair);$payload=random_bytes(2100000);
$input=fopen('php://memory','w+b');fwrite($input,$payload);rewind($input);$archive=fopen('php://memory','w+b');EncryptedBackupService::encrypt($input,$archive,$public);rewind($archive);$encoded=stream_get_contents($archive);$out=fopen('php://memory','w+b');rewind($archive);EncryptedBackupService::decrypt($archive,$out,$pair);rewind($out);if(!hash_equals(hash('sha256',$payload),hash('sha256',stream_get_contents($out))))throw new RuntimeException('Backup round trip failed');
foreach([substr($encoded,0,-1),$encoded.'x',substr_replace($encoded,chr(ord($encoded[200])^1),200,1)] as $bad){$f=fopen('php://memory','w+b');fwrite($f,$bad);rewind($f);$failed=false;try{EncryptedBackupService::decrypt($f,null,$pair);}catch(Throwable $e){$failed=true;}fclose($f);if(!$failed)throw new RuntimeException('Unauthenticated/truncated archive accepted');}
rewind($archive);$failed=false;try{EncryptedBackupService::decrypt($archive,null,sodium_crypto_box_keypair());}catch(Throwable $e){$failed=true;}if(!$failed)throw new RuntimeException('Wrong recipient accepted');
echo "PASS: streaming encrypted backup, isolated recipient, corruption, truncation, trailing-data rejection\n";
