<?php
require_once dirname(__DIR__).'/services/LogRetentionService.php';

/**
 * CLMS API Router - REST v1
 * Routes: /api/v1/{resource} -> backend/api/index.php
 */

require_once dirname(__DIR__, 2) . '/backend/config/runtime.php';

$GLOBALS['__clms_api_start'] = microtime(true);
$GLOBALS['__clms_api_method'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$GLOBALS['__clms_api_path'] = is_string($_GET['path'] ?? '') ? '/' . trim($_GET['path'] ?? '', '/') : '/invalid';
$GLOBALS['__clms_api_slow_threshold_ms'] = 800.0;
$GLOBALS['__clms_api_request_id'] = null;
$GLOBALS['__clms_api_timing_finalized'] = false;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$requestOrigin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
$requestAuthority = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
$originHost = $requestOrigin !== '' ? strtolower((string) parse_url($requestOrigin, PHP_URL_HOST)) : '';
$originPort = $requestOrigin !== '' ? parse_url($requestOrigin, PHP_URL_PORT) : null;
$originScheme = $requestOrigin !== '' ? strtolower((string) parse_url($requestOrigin, PHP_URL_SCHEME)) : '';
$originAuthority = $originHost . ($originPort ? ':' . $originPort : '');
$forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
$requestScheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https') ? 'https' : 'http';
$sameOrigin = $requestOrigin === '' || ($requestAuthority !== ''
    && hash_equals($requestAuthority, $originAuthority)
    && hash_equals($requestScheme, $originScheme));
if ($requestOrigin !== '' && $sameOrigin) {
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CLMS-Debug-Timing, X-CLMS-Retry-Of');

$fetchSite = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
if (!$sameOrigin || $fetchSite === 'cross-site') {
    http_response_code(403);
    echo json_encode(['error' => true, 'message' => 'Cross-site request rejected']);
    exit;
}

@ini_set('session.cookie_httponly', '1');
@ini_set('session.cookie_samesite', 'Lax');
if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    @ini_set('session.cookie_secure', '1');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$path = $_GET['path'] ?? '';
if (!is_string($path) || strlen($path)>500 || !preg_match('#^[A-Za-z0-9_/-]*$#', $path)) {
    http_response_code(400);
    echo json_encode(['error'=>true,'message'=>'Invalid API path']);
    exit;
}
$path = trim($path, '/');
$parts = $path ? explode('/', $path) : [];

$resource = $parts[0] ?? '';
$id = $parts[1] ?? null;
$action = count($parts) > 2 ? implode('/', array_slice($parts, 2)) : null;

$handlerFile = __DIR__ . '/handlers/' . $resource . '.php';
if (!file_exists($handlerFile)) {
    http_response_code(404);
    echo json_encode(['error' => 'Not Found', 'message' => "Resource '$resource' not found"]);
    exit;
}

require_once dirname(__DIR__, 2) . '/backend/config/database.php';
require_once __DIR__ . '/helpers.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar_permissions.php';

$method = $_SERVER['REQUEST_METHOD'];
$input = [];
if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
    $raw = file_get_contents('php://input');
    if (str_contains(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        if (strlen($raw)>32*1024*1024) jsonError('Request body exceeds the supported size',413);
        $decoded = json_decode($raw);
        if (json_last_error() !== JSON_ERROR_NONE || !is_object($decoded)) jsonError('Request body must be a JSON object',400);
        $input = json_decode($raw,true);
    } else {
        $input = $_POST;
    }
}

require_once __DIR__ . '/authorization.php';
$GLOBALS['clms_incident_entities']=[];
$GLOBALS['clms_incident_secrets']=array_values(array_filter($_COOKIE??[],'is_string'));
$collectSecrets=static function(array $values)use(&$collectSecrets):void{foreach($values as $key=>$value){if(is_array($value))$collectSecrets($value);elseif(is_string($value)&&preg_match('/password|secret|token|cookie|authorization|credential|api.?key|verification.code|totp/i',(string)$key))$GLOBALS['clms_incident_secrets'][]=$value;}};
$collectSecrets($input);unset($collectSecrets);
foreach(['order_id','container_id','shipment_draft_id','receipt_id','customer_id'] as $entityKey){$value=$input[$entityKey]??null;if((is_int($value)||is_string($value))&&ctype_digit((string)$value)&&(float)$value>0&&(float)$value<=4294967295)$GLOBALS['clms_incident_entities'][$entityKey]=(int)$value;}
clmsAuthorizeApiRequest($resource, $method, $id, $action);

try {
    $handler = require $handlerFile;
    $handler($method, $id, $action, $input);
} catch (Throwable $e) {
    $requestId = bin2hex(random_bytes(8));
    $GLOBALS['__clms_api_request_id'] = $requestId;
    $logDir = dirname(__DIR__, 2) . '/logs';
    if (is_dir($logDir)) {
        @error_log(date('Y-m-d H:i:s') . " [{$requestId}] " . get_class($e) . " at " . basename($e->getFile()) . ':' . $e->getLine() . "\n", 3, LogRetentionService::path('php_errors'));
    }
    $message = clmsT('An error occurred. Please try again or contact support. (ref: {ref})', ['ref' => $requestId]);
    require_once dirname(__DIR__).'/services/OwnerIncidentService.php';
    if($e instanceof PDOException)$GLOBALS['clms_incident_code']='DATABASE_UNAVAILABLE';
    OwnerIncidentService::capture(500,$requestId);
    http_response_code(500);
    clmsFinalizeApiTiming(500);
    echo json_encode([
        'error' => true,
        'message' => $message,
        'request_id' => $requestId,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
