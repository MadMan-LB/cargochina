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
        $stmt = $pdo->prepare("SELECT r.code FROM roles r JOIN user_roles ur ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.code");
        $stmt->execute([$userId]);
        $_SESSION['user_roles'] = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'code');

        $nameStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
        $nameStmt->execute([$userId]);
        $name = $nameStmt->fetchColumn();
        if ($name !== false && trim((string) $name) !== '') {
            $_SESSION['user_name'] = (string) $name;
        }
    } catch (Throwable $e) {
        // Keep the existing session roles if the database is temporarily unavailable.
    }
}
