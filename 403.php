<?php
/**
 * Access Denied page. Included when user lacks role for requested area.
 * Expects $userRoles, $area in scope when included from area_bootstrap.php.
 */
$roles = $userRoles ?? $_SESSION['user_roles'] ?? [];
$homeUrl = '/cargochina/warehouse/';
if (in_array('SuperAdmin', $roles)) {
    $homeUrl = '/cargochina/superadmin/';
} elseif (in_array('WarehouseStaff', $roles) && !in_array('SuperAdmin', $roles)) {
    $homeUrl = '/cargochina/warehouse/';
} elseif (in_array('ChinaAdmin', $roles)) {
    $homeUrl = '/cargochina/buyers/';
} elseif (in_array('LebanonAdmin', $roles)) {
    $homeUrl = '/cargochina/admin/';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Access Denied | CLMS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center min-vh-100">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-md-6">
        <div class="card shadow text-center">
          <div class="card-body p-5">
            <div class="text-danger mb-3">
              <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" fill="currentColor" class="bi bi-shield-x" viewBox="0 0 16 16">
                <path d="M5.338 1.59a61 61 0 0 0-2.837.856.48 48 0 0 0-.528.02c-.146.067-.408.115-.577.124-.17.009-.331-.008-.746-.08l-.002-.001c-.325-.04-.75-.138-1.148-.293a1 1 0 0 0-.707-.028c-.787.265-1.593.49-2.042.634a1 1 0 0 0-.364.063c-.018.014-.021.021-.021.021l-.001-.001A1 1 0 0 0 0 2v10a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1H1a1 1 0 0 0-.662.028z"/>
                <path d="M10.646 7.646a.5.5 0 0 1 .708 0L13 9.293l1.646-1.647a.5.5 0 0 1 .708.708L13.707 10l1.647 1.646a.5.5 0 0 1-.708.708L13 10.707l-1.646 1.647a.5.5 0 0 1-.708-.708L12.293 10z"/>
              </svg>
            </div>
            <h1 class="h3 mb-3">Access Denied</h1>
            <p class="text-muted mb-4">You do not have permission to access this area.</p>
            <a href="<?= htmlspecialchars($homeUrl) ?>" class="btn btn-primary">Return to My Area</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
