<?php
require_once __DIR__ . '/includes/i18n.php';
require 'backend/config/database.php';
require_once __DIR__ . '/includes/sidebar_permissions.php';

function normalizeLoginIdentifier(string $value): string
{
  return trim($value);
}

if (!empty($_GET['logout'])) {
  $_SESSION = [];
  session_destroy();
  header('Location: login.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = normalizeLoginIdentifier((string) ($_POST['email'] ?? ''));
  $pass = $_POST['password'] ?? '';
  if ($email && $pass) {
    $pdo = getDb();
    $stmt = $pdo->prepare("SELECT id, email, password_hash, full_name FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) AND is_active = 1 LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && password_verify($pass, $user['password_hash'])) {
      $_SESSION['user_id'] = (int) $user['id'];
      $_SESSION['user_name'] = $user['full_name'];
      $roleStmt = $pdo->prepare("SELECT r.code FROM roles r JOIN user_roles ur ON r.id = ur.role_id WHERE ur.user_id = ?");
      $roleStmt->execute([$user['id']]);
      $_SESSION['user_roles'] = array_column($roleStmt->fetchAll(PDO::FETCH_ASSOC), 'code');
      $roles = $_SESSION['user_roles'];
      header('Location: ' . clmsGetAccessibleHomeUrl($roles, $pdo));
      exit;
    }
  }
  $error = clmsT('Invalid email/username or password');
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(clmsGetUiLocale()) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(clmsT('Login')) ?> | CLMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
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
