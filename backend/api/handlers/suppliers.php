<?php

/**
 * Suppliers API - GET list, GET one, POST create, PUT update, DELETE
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();

    switch ($method) {
        case 'GET':
            if ($id === null) {
                $stmt = $pdo->query("SELECT * FROM suppliers ORDER BY name");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as &$r) {
                    $r['contacts'] = $r['contacts'] ? json_decode($r['contacts'], true) : [];
                }
                jsonResponse(['data' => $rows]);
            }
            $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                jsonError('Supplier not found', 404);
            }
            $row['contacts'] = $row['contacts'] ? json_decode($row['contacts'], true) : [];
            jsonResponse(['data' => $row]);

        case 'POST':
            $code = trim($input['code'] ?? '');
            $name = trim($input['name'] ?? '');
            if (!$code || !$name) {
                jsonError('Missing required fields', 400, ['code' => 'Required', 'name' => 'Required']);
            }
            $contacts = isset($input['contacts']) ? json_encode($input['contacts']) : null;
            $factoryLocation = $input['factory_location'] ?? null;
            $notes = $input['notes'] ?? null;
            try {
                $stmt = $pdo->prepare("INSERT INTO suppliers (code, name, contacts, factory_location, notes) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$code, $name, $contacts, $factoryLocation, $notes]);
                $newId = (int) $pdo->lastInsertId();
                $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
                $stmt->execute([$newId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $row['contacts'] = $row['contacts'] ? json_decode($row['contacts'], true) : [];
                jsonResponse(['data' => $row], 201);
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    jsonError('Supplier code already exists', 409);
                }
                throw $e;
            }

        case 'PUT':
            if (!$id) {
                jsonError('ID required', 400);
            }
            $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                jsonError('Supplier not found', 404);
            }
            $code = trim($input['code'] ?? '');
            $name = trim($input['name'] ?? '');
            if (!$code || !$name) {
                jsonError('Missing required fields', 400);
            }
            $contacts = isset($input['contacts']) ? json_encode($input['contacts']) : null;
            $factoryLocation = $input['factory_location'] ?? null;
            $notes = $input['notes'] ?? null;
            $pdo->prepare("UPDATE suppliers SET code=?, name=?, contacts=?, factory_location=?, notes=? WHERE id=?")
                ->execute([$code, $name, $contacts, $factoryLocation, $notes, $id]);
            $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $row['contacts'] = $row['contacts'] ? json_decode($row['contacts'], true) : [];
            jsonResponse(['data' => $row]);

        case 'DELETE':
            if (!$id) {
                jsonError('ID required', 400);
            }
            $stmt = $pdo->prepare("DELETE FROM suppliers WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                jsonError('Supplier not found', 404);
            }
            jsonResponse(['message' => 'Deleted']);

        default:
            jsonError('Method not allowed', 405);
    }
};
