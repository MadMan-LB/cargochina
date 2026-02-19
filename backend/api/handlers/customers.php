<?php

/**
 * Customers API - GET list, GET one, POST create, PUT update, DELETE
 */

require_once __DIR__ . '/../helpers.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();

    switch ($method) {
        case 'GET':
            if ($id === null) {
                $stmt = $pdo->query("SELECT * FROM customers ORDER BY name");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as &$r) {
                    $r['contacts'] = $r['contacts'] ? json_decode($r['contacts'], true) : [];
                    $r['addresses'] = $r['addresses'] ? json_decode($r['addresses'], true) : [];
                }
                jsonResponse(['data' => $rows]);
            }
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                jsonError('Customer not found', 404);
            }
            $row['contacts'] = $row['contacts'] ? json_decode($row['contacts'], true) : [];
            $row['addresses'] = $row['addresses'] ? json_decode($row['addresses'], true) : [];
            jsonResponse(['data' => $row]);

        case 'POST':
            $code = trim($input['code'] ?? '');
            $name = trim($input['name'] ?? '');
            if (!$code || !$name) {
                jsonError('Missing required fields', 400, ['code' => 'Required', 'name' => 'Required']);
            }
            $contacts = isset($input['contacts']) ? json_encode($input['contacts']) : null;
            $addresses = isset($input['addresses']) ? json_encode($input['addresses']) : null;
            $paymentTerms = $input['payment_terms'] ?? null;
            try {
                $stmt = $pdo->prepare("INSERT INTO customers (code, name, contacts, addresses, payment_terms) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$code, $name, $contacts, $addresses, $paymentTerms]);
                $newId = (int) $pdo->lastInsertId();
                $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
                $stmt->execute([$newId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $row['contacts'] = $row['contacts'] ? json_decode($row['contacts'], true) : [];
                $row['addresses'] = $row['addresses'] ? json_decode($row['addresses'], true) : [];
                jsonResponse(['data' => $row], 201);
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    jsonError('Customer code already exists', 409);
                }
                throw $e;
            }

        case 'PUT':
            if (!$id) {
                jsonError('ID required', 400);
            }
            $stmt = $pdo->prepare("SELECT id FROM customers WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                jsonError('Customer not found', 404);
            }
            $code = trim($input['code'] ?? '');
            $name = trim($input['name'] ?? '');
            if (!$code || !$name) {
                jsonError('Missing required fields', 400);
            }
            $contacts = isset($input['contacts']) ? json_encode($input['contacts']) : null;
            $addresses = isset($input['addresses']) ? json_encode($input['addresses']) : null;
            $paymentTerms = $input['payment_terms'] ?? null;
            $pdo->prepare("UPDATE customers SET code=?, name=?, contacts=?, addresses=?, payment_terms=? WHERE id=?")
                ->execute([$code, $name, $contacts, $addresses, $paymentTerms, $id]);
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $row['contacts'] = $row['contacts'] ? json_decode($row['contacts'], true) : [];
            $row['addresses'] = $row['addresses'] ? json_decode($row['addresses'], true) : [];
            jsonResponse(['data' => $row]);

        case 'DELETE':
            if (!$id) {
                jsonError('ID required', 400);
            }
            $stmt = $pdo->prepare("DELETE FROM customers WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                jsonError('Customer not found', 404);
            }
            jsonResponse(['message' => 'Deleted']);

        default:
            jsonError('Method not allowed', 405);
    }
};
