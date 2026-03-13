<?php

/**
 * Users API - list, get one, update (SuperAdmin only)
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    requireRole(['SuperAdmin']);

    $pdo = getDb();

    if ($method === 'GET') {
        if ($id === null) {
            $stmt = $pdo->query("SELECT u.id, u.email, u.full_name, u.is_active, u.created_at FROM users u ORDER BY u.id");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $roleStmt = $pdo->prepare("SELECT r.code FROM roles r JOIN user_roles ur ON r.id = ur.role_id WHERE ur.user_id = ?");
                $roleStmt->execute([$r['id']]);
                $r['roles'] = array_column($roleStmt->fetchAll(PDO::FETCH_ASSOC), 'code');
                $deptStmt = $pdo->prepare("SELECT d.id, d.code, d.name FROM departments d JOIN user_departments ud ON d.id = ud.department_id WHERE ud.user_id = ? ORDER BY ud.is_primary DESC");
                $deptStmt->execute([$r['id']]);
                $r['departments'] = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            jsonResponse(['data' => $rows]);
        }
        $stmt = $pdo->prepare("SELECT id, email, full_name, is_active FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) jsonError('User not found', 404);
        $roleStmt = $pdo->prepare("SELECT r.code FROM roles r JOIN user_roles ur ON r.id = ur.role_id WHERE ur.user_id = ?");
        $roleStmt->execute([$id]);
        $row['roles'] = array_column($roleStmt->fetchAll(PDO::FETCH_ASSOC), 'code');
        $deptStmt = $pdo->prepare("SELECT d.id, d.code, d.name, ud.is_primary FROM departments d JOIN user_departments ud ON d.id = ud.department_id WHERE ud.user_id = ?");
        $deptStmt->execute([$id]);
        $row['departments'] = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(['data' => $row]);
    }

    if ($method === 'POST' && $id && $action === 'reset-password') {
        $newPassword = trim($input['password'] ?? '');
        if (strlen($newPassword) < 6) {
            jsonError('Password must be at least 6 characters', 400);
        }
        $stmt = $pdo->prepare("SELECT id, email FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) jsonError('User not found', 404);
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $id]);
        jsonResponse(['data' => ['user_id' => (int) $id, 'email' => $user['email'], 'new_password' => $newPassword]]);
    }

    if ($method === 'PUT' && $id) {
        $roles = $input['roles'] ?? null;
        $departmentIds = $input['department_ids'] ?? null;
        $isActive = isset($input['is_active']) ? (int) (bool) $input['is_active'] : null;
        $pdo->beginTransaction();
        try {
            if ($isActive !== null) {
                $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$isActive, $id]);
            }
            if (is_array($roles)) {
                $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$id]);
                $roleMap = [];
                foreach ($pdo->query("SELECT id, code FROM roles")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $roleMap[$r['code']] = $r['id'];
                }
                $insRole = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                foreach ($roles as $code) {
                    if (isset($roleMap[$code])) {
                        $insRole->execute([$id, $roleMap[$code]]);
                    }
                }
            }
            if (is_array($departmentIds)) {
                $pdo->prepare("DELETE FROM user_departments WHERE user_id = ?")->execute([$id]);
                $insDept = $pdo->prepare("INSERT INTO user_departments (user_id, department_id, is_primary) VALUES (?, ?, ?)");
                foreach ($departmentIds as $i => $deptId) {
                    if ($deptId) {
                        $insDept->execute([$id, (int) $deptId, $i === 0 ? 1 : 0]);
                    }
                }
            }
            $pdo->commit();
            $stmt = $pdo->prepare("SELECT id, email, full_name, is_active FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $roleStmt = $pdo->prepare("SELECT r.code FROM roles r JOIN user_roles ur ON r.id = ur.role_id WHERE ur.user_id = ?");
            $roleStmt->execute([$id]);
            $row['roles'] = array_column($roleStmt->fetchAll(PDO::FETCH_ASSOC), 'code');
            $deptStmt = $pdo->prepare("SELECT d.id, d.code, d.name FROM departments d JOIN user_departments ud ON d.id = ud.department_id WHERE ud.user_id = ?");
            $deptStmt->execute([$id]);
            $row['departments'] = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(['data' => $row]);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    jsonError('Method not allowed', 405);
};
