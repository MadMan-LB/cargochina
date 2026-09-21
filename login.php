<?php
require_once __DIR__ . '/includes/i18n.php';
require 'backend/config/database.php';
require_once __DIR__ . '/includes/sidebar_permissions.php';
require_once __DIR__ . '/backend/services/AuthenticationService.php';

function normalizeLoginIdentifier(string $value): string
{
  return trim($value);
}

if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['logout'])) {
  if (!is_string($_POST['csrf_token']??null) || !is_string($_SESSION['logout_csrf_token']??null) || !hash_equals($_SESSION['logout_csrf_token'],$_POST['csrf_token'])) { http_response_code(403); exit('Please refresh and try again.'); }
  $_SESSION = [];
  session_destroy();
  header('Location: login.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $validToken = is_string($_POST['csrf_token'] ?? null) && is_string($_SESSION['login_csrf_token'] ?? null) && hash_equals($_SESSION['login_csrf_token'], $_POST['csrf_token']);
  $email = is_string($_POST['email'] ?? null) && strlen($_POST['email'])<=254 ? normalizeLoginIdentifier($_POST['email']) : '';
  $pass = is_string($_POST['password'] ?? null) && strlen($_POST['password'])<=4096 ? $_POST['password'] : '';
  if (!$validToken) { http_response_code(403); $email=''; $error=clmsT('Please refresh the login page and try again.'); }
  if ($email && $pass) {
    $pdo = getDb();
    $appEnv = strtolower(trim((string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'production'))));
    try {
      $user = (new AuthenticationService($pdo))->login($email, (string) $pass, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), $appEnv);
      session_regenerate_id(true);
      unset($_SESSION['login_csrf_token']);
      $_SESSION['user_id'] = $user['user_id'];
      $_SESSION['user_name'] = $user['name'];
      $_SESSION['user_roles'] = $user['roles'];
      require_once __DIR__.'/backend/services/SessionPolicyService.php';
      SessionPolicyService::establish($_SESSION,$user['_session_version'],$user['_session_credential'],null,(string)$pdo->query('SELECT DATABASE()')->fetchColumn());
      $roles = $_SESSION['user_roles'];
      header('Location: ' . clmsGetAccessibleHomeUrl($roles, $pdo, (int) $user['user_id']));
      exit;
    } catch (AuthenticationException $e) {
      $error = clmsT($e->getMessage());
    }
  }
  if (empty($error)) $error = clmsT('Invalid email/username or password');
}
if (empty($_SESSION['login_csrf_token'])) $_SESSION['login_csrf_token']=bin2hex(random_bytes(32));
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(clmsGetUiLocale()) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(clmsT('Login')) ?> | CLMS</title>
    <link href="/cargochina/frontend/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light d-flex align-items-center min-vh-100">
    <div class="container">
        <div class="d-flex justify-content-end mb-3 gap-2">
            <a class="btn btn-outline-secondary btn-sm<?= clmsGetUiLocale() === 'en' ? ' active' : '' ?>" href="<?= htmlspecialchars(clmsCurrentUrlWithUiLocale('en')) ?>">EN</a>
            <a class="btn btn-outline-secondary btn-sm<?= clmsGetUiLocale() === 'zh-CN' ? ' active' : '' ?>" href="<?= htmlspecialchars(clmsCurrentUrlWithUiLocale('zh-CN')) ?>">中文</a>
        </div>
        <div class="row justify-content-center">
            <div class="col-md-4">
                <div class="card shadow">
                    <div class="card-body p-4">
                        <h4 class="card-title mb-4"><?= htmlspecialchars(clmsT('CLMS Login')) ?></h4>
                        <?php if (!empty($error)): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?>
                        </div><?php endif; ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['login_csrf_token']) ?>">
                            <div class="mb-3"><label class="form-label" for="loginEmail"><?= htmlspecialchars(clmsT('Email or Username')) ?></label><input type="text"
                                    id="loginEmail" name="email" class="form-control" autocomplete="username" required>
                            </div>
                            <div class="mb-3"><label class="form-label" for="loginPassword"><?= htmlspecialchars(clmsT('Password')) ?></label><input
                                    type="password" id="loginPassword" name="password" class="form-control"
                                    autocomplete="current-password" required></div>
                            <button type="submit" class="btn btn-primary w-100"><?= htmlspecialchars(clmsT('Login')) ?></button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
