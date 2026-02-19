<?php

/**
 * Auth API - POST /login, POST /logout
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    if ($method !== 'POST') {
        jsonError('Method not allowed', 405);
    }

    if ($id === 'login') {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';
        if (!$email || !$password) {
            jsonError('Email and password required', 400);
        }
        $pdo = getDb();
        $stmt = $pdo->prepare("SELECT u.id, u.password_hash, u.full_name FROM users u WHERE u.email = ? AND u.is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            jsonError('Invalid email or password', 401);
        }
        $roleStmt = $pdo->prepare("SELECT r.code FROM roles r JOIN user_roles ur ON r.id = ur.role_id WHERE ur.user_id = ?");
        $roleStmt->execute([$user['id']]);
        $roles = array_column($roleStmt->fetchAll(PDO::FETCH_ASSOC), 'code');
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_name'] = $user['full_name'];
        $_SESSION['user_roles'] = $roles;
        jsonResponse(['data' => ['user_id' => (int) $user['id'], 'name' => $user['full_name'], 'roles' => $roles]]);
    }

    if ($id === 'logout') {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        jsonResponse(['data' => ['message' => 'Logged out']]);
    }

    jsonError('Invalid action', 400);
};
