<?php

// Router for isolated PHP built-in-server verification. Apache uses .htaccess.
$uriPath=(string)(parse_url($_SERVER['REQUEST_URI'] ?? '/',PHP_URL_PATH) ?: '/');
$relative=preg_replace('#^/cargochina/?#','',$uriPath);
if (preg_match('#(^|/)(?:\.|node_modules(?:/|$)|vendor(?:/|$)|tests(?:/|$)|scripts(?:/|$)|output(?:/|$)|logs(?:/|$)|tmp(?:/|$)|includes(?:/|$)|docs(?:/|$)|backend/(?:config|migrations|services|cache|templates|cron|logs)(?:/|$))#i',(string)$relative)) {
    http_response_code(403); echo 'Forbidden'; return true;
}
if (preg_match('#^/cargochina/api/v1/(.*)$#',$uriPath,$match)) {
    $_GET['path']=$match[1];
    require dirname(__DIR__) . '/backend/api/index.php';
    return true;
}
$documentRoot=rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__,2)),'/\\');
$candidate=realpath($documentRoot . str_replace('/',DIRECTORY_SEPARATOR,$uriPath));
if ($candidate && str_starts_with(str_replace('\\','/',$candidate),str_replace('\\','/',$documentRoot).'/') && is_file($candidate)) return false;
http_response_code(404);
echo 'Not Found';
return true;
