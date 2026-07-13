<?php

/** Auth API - POST /login, POST /logout */
require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 2) . '/services/AuthenticationService.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    if ($method !== 'POST') jsonError('Method not allowed',405);

    if ($id === 'login') {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $pdo=getDb();
        $appEnv=strtolower(trim((string)(getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'production'))));
        try {
            $user=(new AuthenticationService($pdo))->login(
                trim((string)($input['email'] ?? '')),
                (string)($input['password'] ?? ''),
                (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                $appEnv
            );
        } catch(AuthenticationException $e) {
            jsonError($e->getMessage(),$e->httpStatus);
        }
        session_regenerate_id(true);
        $_SESSION['user_id']=$user['user_id'];
        $_SESSION['user_name']=$user['name'];
        $_SESSION['user_roles']=$user['roles'];
        jsonResponse(['data'=>$user]);
    }

    if ($id === 'logout') {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION=[];
        if (ini_get('session.use_cookies')) {
            $params=session_get_cookie_params();
            setcookie(session_name(),'',[
                'expires'=>time()-42000,
                'path'=>$params['path'],
                'domain'=>$params['domain'],
                'secure'=>$params['secure'],
                'httponly'=>$params['httponly'],
                'samesite'=>$params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
        jsonResponse(['data'=>['message'=>'Logged out']]);
    }
    jsonError('Invalid action',400);
};
