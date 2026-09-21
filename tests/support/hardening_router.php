<?php
// CLI-server-only routing for the disposable QA database.
if (PHP_SAPI !== 'cli-server' || getenv('DB_NAME') !== 'clms_hardening_20260919') { http_response_code(404); exit; }
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if(preg_match('#^/cargochina/backend/uploads/(.+)$#',$path,$match)){$_GET['path']='uploads/'.rawurldecode($match[1]);require dirname(__DIR__,2).'/backend/media.php';return true;}
if (preg_match('#^/cargochina/api/v1/(.*)$#',$path,$match)) {
    $_GET['path']=$match[1]; require dirname(__DIR__,2).'/backend/api/index.php'; return true;
}
if (preg_match('#^/cargochina/(?:backend/config|tests|\.env)#',$path)) {http_response_code(404);return true;}
return false;
