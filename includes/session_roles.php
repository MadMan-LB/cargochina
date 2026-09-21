<?php

function clmsRefreshSessionRolesFromDb(?PDO $pdo = null): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    if ($userId <= 0) {
        return;
    }

    static $refreshed = [];
    if (isset($refreshed[$userId])) {
        return;
    }
    $refreshed[$userId] = true;

    try {
        if (!$pdo) {
            require_once __DIR__ . '/../backend/config/database.php';
            $pdo = getDb();
        }
        $nameStmt = $pdo->prepare("SELECT full_name,is_active,password_hash,session_version FROM users WHERE id = ? LIMIT 1");
        $nameStmt->execute([$userId]);
        $user = $nameStmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !(int)$user['is_active']) {
            unset($_SESSION['user_id'], $_SESSION['user_roles'], $_SESSION['user_name']);
            return;
        }
        $stmt = $pdo->prepare("SELECT r.code FROM roles r JOIN user_roles ur ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.code");
        $stmt->execute([$userId]);
        $_SESSION['user_roles'] = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'code');
        require_once __DIR__.'/../backend/services/SessionPolicyService.php';
        $sessionDatabase=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        // Existing CLI suites intentionally construct sessions without a web login.
        // This fixture bootstrap is unavailable to HTTP/cli-server and production.
        if(PHP_SAPI==='cli' && getenv('APP_ENV')==='testing' && !isset($_SESSION['clms_session'])) {
            SessionPolicyService::establish($_SESSION,(int)$user['session_version'],hash('sha256',$user['password_hash']),null,$sessionDatabase);
        }
        // Activation is a deployment decision: do not silently revoke legacy sessions.
        // New logins carry metadata; explicit version/hash revocation still applies to them.
        $enabled=getenv('CLMS_SESSION_POLICY_ENABLED')==='1'||getenv('APP_ENV')==='testing';
        $requestPath=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)??'';
        $background=str_contains($requestPath,'/notifications/unread-count')||(str_ends_with($requestPath,'/owner-control/summary')&&($_GET['background']??'')==='1');
        if(($enabled||isset($_SESSION['clms_session']))&&!SessionPolicyService::validate($_SESSION,$user,$_SESSION['user_roles'],null,!$background,$sessionDatabase))return;

        if (trim((string) $user['full_name']) !== '') {
            $_SESSION['user_name'] = (string) $user['full_name'];
        }
    } catch (Throwable $e) {
        // Fail closed for this request; a temporary outage does not destroy the login cookie.
        $GLOBALS['clms_auth_verification_failed'] = true;
        $_SESSION['user_roles'] = [];
    }
}
