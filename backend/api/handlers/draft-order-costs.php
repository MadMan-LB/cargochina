<?php

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 2) . '/services/DraftOrderCostService.php';

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();
    $service = new DraftOrderCostService($pdo);
    requirePermission('draft-order-costs.read', ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'SuperAdmin']);
    try {
        if ($method === 'GET') {
            $orderId = (int) ($_GET['order_id'] ?? 0);
            if ($orderId <= 0) jsonError('order_id is required', 400);
            if(!empty($_GET['archived']))jsonResponse(['data'=>['lines'=>$service->listArchivedForOrder($orderId),'archived'=>true]]);
            jsonResponse(['data' => $service->summarize($orderId)]);
        }
        requirePermission('draft-order-costs.write', ['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin']);
        $pdo->beginTransaction();
        if ($method === 'POST' && $id === null) {
            $orderId = (int) ($input['order_id'] ?? 0);
            $row = $service->create($orderId, $input, (int) getAuthUserId());
            $pdo->commit(); jsonResponse(['data' => $row], 201);
        }
        if ($method === 'PUT' && ctype_digit((string) $id)) {
            $row = $service->update((int) $id, $input, (int) getAuthUserId());
            $pdo->commit(); jsonResponse(['data' => $row]);
        }
        if ($method === 'DELETE' && ctype_digit((string) $id)) {
            $service->delete((int) $id, (int) getAuthUserId());
            $pdo->commit(); jsonResponse(['data' => ['deleted' => true]]);
        }
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonError('Method not allowed', 405);
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack(); jsonError($e->getMessage(), 400);
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack(); jsonError($e->getMessage(), 409);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack(); throw $e;
    }
};
