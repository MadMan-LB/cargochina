<?php
session_start();
require 'backend/config/database.php';

if (!empty($_GET['logout'])) {
  $_SESSION = [];
  session_destroy();
  header('Location: login.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $pass = $_POST['password'] ?? '';
  if ($email && $pass) {
    $pdo = getDb();
    $stmt = $pdo->prepare("SELECT id, password_hash, full_name FROM users WHERE email = ? AND is_active = 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && password_verify($pass, $user['password_hash'])) {
      $_SESSION['user_id'] = (int) $user['id'];
      $_SESSION['user_name'] = $user['full_name'];
      $roleStmt = $pdo->prepare("SELECT r.code FROM roles r JOIN user_roles ur ON r.id = ur.role_id WHERE ur.user_id = ?");
      $roleStmt->execute([$user['id']]);
      $_SESSION['user_roles'] = array_column($roleStmt->fetchAll(PDO::FETCH_ASSOC), 'code');
      header('Location: index.php');
      exit;
    }
  }
  $error = 'Invalid email or password';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login | CLMS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light d-flex align-items-center min-vh-100">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-md-4">
        <div class="card shadow">
          <div class="card-body p-4">
            <h4 class="card-title mb-4">CLMS Login</h4>
            <?php if (!empty($error)): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="post">
              <div class="mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" required></div>
              <div class="mb-3"><label class="form-label">Password</label><input type="password" name="password" class="form-control" required></div>
              <button type="submit" class="btn btn-primary w-100">Login</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</body>

</html>