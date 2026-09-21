<?php
require_once __DIR__.'/config/database.php';
require_once __DIR__.'/services/UploadAccessService.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
$path=$_GET['path']??null;$token=$_GET['confirmation_token']??null;
if(!is_string($path)||($token!==null&&!is_string($token)))jsonError('Invalid file request',422);
UploadAccessService::authorize(getDb(),$path,$token);
try{$meta=clmsResolveStoredUploadPathMeta($path,true);}catch(Throwable $e){jsonError('File not found',404);}
$source=$meta['resolved_path']?:$meta['full_path'];$ext=strtolower(pathinfo($source,PATHINFO_EXTENSION));$types=clmsUploadAllowedMimeMap();
if(!isset($types[$ext]))jsonError('Unsupported file type',415);
$mime=(new finfo(FILEINFO_MIME_TYPE))->file($source);if(!in_array($mime,$types[$ext],true))jsonError('File type does not match its content',415);
header('Content-Type: '.$mime);header('Content-Length: '.filesize($source));
$inline=in_array($ext,['jpg','jpeg','png','gif','webp','bmp'],true);
header('Content-Disposition: '.($inline?'inline':'attachment').'; filename="'.basename($source).'"');
header("Content-Security-Policy: sandbox; default-src 'none'");
readfile($source);
