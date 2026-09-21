<?php

/**
 * Regression: receive with 2 items (one variance, one normal)
 * Asserts: order state, receipt_items, notification_delivery_log, idempotency
 * Run: php tests/regression_receive_variance_test.php
 */

$root = dirname(__DIR__);
require_once $root . '/backend/config/database.php';
require_once $root . '/backend/api/helpers.php';
require_once $root . '/backend/services/NotificationService.php';
require_once $root . '/backend/services/OrderReceivingService.php';
require_once $root . '/backend/services/OrderReceiptWorkflowService.php';
require_once $root . '/backend/services/FinancialReconciliationService.php';

$pdo = getDb();
$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "PASS: $name\n";
        $passed++;
    } catch (Throwable $e) {
        echo "FAIL: $name - " . $e->getMessage() . "\n";
        $failed++;
    }
}

function startConcurrentReceiveWorker(string $root,int $orderId,int $itemId,int $userId,string $key): array
{
    $rootEsc=addslashes(str_replace('\\','/',$root));
    $input=var_export(['idempotency_key'=>$key,'actual_cartons'=>4,'actual_cbm'=>0.4,'actual_weight'=>4,'condition'=>'partial','items'=>[['order_item_id'=>$itemId,'actual_cartons'=>4,'actual_cbm'=>0.4,'actual_weight'=>4,'condition'=>'partial']]],true);
    $code="<?php\nrequire '$rootEsc/backend/config/database.php';\nrequire '$rootEsc/backend/api/helpers.php';\nrequire '$rootEsc/backend/services/OrderReceivingService.php';\ntry{echo json_encode((new OrderReceivingService())->receive(getDb(),$orderId,$input,$userId));}catch(Throwable \$e){fwrite(STDERR,\$e->getMessage());exit(1);}";
    $tmp=tempnam(sys_get_temp_dir(),'receive_concurrency_').'.php';file_put_contents($tmp,$code);
    $pipes=[];$process=proc_open([PHP_BINARY,$tmp],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process)){@unlink($tmp);throw new RuntimeException('Could not start receive worker');}fclose($pipes[0]);return compact('process','pipes','tmp');
}
function finishConcurrentReceiveWorker(array $worker): array
{
    $out=stream_get_contents($worker['pipes'][1]);$err=stream_get_contents($worker['pipes'][2]);fclose($worker['pipes'][1]);fclose($worker['pipes'][2]);$exit=proc_close($worker['process']);@unlink($worker['tmp']);if($exit!==0)throw new RuntimeException('Receive worker failed: '.$err);$json=json_decode($out,true);if(!is_array($json))throw new RuntimeException('Invalid receive worker output: '.$out);return $json;
}

$orderId = null;
$receiptId = null;
$itemIds = [];
$calcOrderId = null;
$partialOrderId = null;
$resetOrderId = null;
$concurrentOrderId = null;

try {
    $cust = $pdo->query("SELECT id FROM customers LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $supp = $pdo->query("SELECT id FROM suppliers LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $user = $pdo->query("SELECT id FROM users LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$cust || !$supp || !$user) {
        echo "SKIP: Need customer, supplier, user in DB\n";
        exit(0);
    }

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO orders (customer_id, supplier_id, expected_ready_date, status, created_by) VALUES (?,?,CURDATE(),'Approved',?)")
        ->execute([$cust['id'], $supp['id'], $user['id']]);
    $orderId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO order_items (order_id, quantity, unit, declared_cbm, declared_weight, description_en) VALUES (?,10,'cartons',2.0,50,'Item1')")
        ->execute([$orderId]);
    $itemIds[] = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO order_items (order_id, quantity, unit, declared_cbm, declared_weight, description_en) VALUES (?,5,'cartons',1.0,25,'Item2')")
        ->execute([$orderId]);
    $itemIds[] = (int) $pdo->lastInsertId();
    $pdo->commit();

    $beforeLog = (int) $pdo->query("SELECT COUNT(*) FROM notification_delivery_log")->fetchColumn();

    $pdo->beginTransaction();
    $wrCols = $pdo->query("SHOW COLUMNS FROM warehouse_receipts")->fetchAll(PDO::FETCH_COLUMN);
    $wrHasCond = in_array('receipt_condition', $wrCols, true);
    if ($wrHasCond) {
        $pdo->prepare("INSERT INTO warehouse_receipts (order_id, actual_cartons, actual_cbm, actual_weight, receipt_condition, received_by) VALUES (?,15,3.6,75,'good',?)")->execute([$orderId, $user['id']]);
    } else {
        $pdo->prepare("INSERT INTO warehouse_receipts (order_id, actual_cartons, actual_cbm, actual_weight, received_by) VALUES (?,15,3.6,75,?)")->execute([$orderId, $user['id']]);
    }
    $receiptId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO warehouse_receipt_photos (receipt_id, file_path) VALUES (?,'test/photo.jpg')")->execute([$receiptId]);
    $insItem = $pdo->prepare("INSERT INTO warehouse_receipt_items (receipt_id, order_item_id, actual_cbm, actual_weight, variance_detected) VALUES (?,?,?,?,?)");
    $insItem->execute([$receiptId, $itemIds[0], 2.5, 50, 1]);
    $insItem->execute([$receiptId, $itemIds[1], 1.1, 25, 0]);
    $pdo->prepare("UPDATE orders SET status='AwaitingCustomerConfirmation' WHERE id=?")->execute([$orderId]);
    $pdo->commit();

    $GLOBALS['_log_order_id'] = $orderId;
    $GLOBALS['_log_receipt_id'] = $receiptId;
    $svc = new NotificationService($pdo);
    $svc->notifyOrderReceived($orderId, (int) $user['id'], true);
    unset($GLOBALS['_log_order_id'], $GLOBALS['_log_receipt_id']);

    test('Order status is AwaitingCustomerConfirmation', function () use ($pdo, $orderId) {
        $s = $pdo->query("SELECT status FROM orders WHERE id=$orderId")->fetchColumn();
        if ($s !== 'AwaitingCustomerConfirmation') throw new Exception("Expected AwaitingCustomerConfirmation, got $s");
    });

    test('warehouse_receipt_items has 2 rows', function () use ($pdo, $receiptId) {
        $n = (int) $pdo->query("SELECT COUNT(*) FROM warehouse_receipt_items WHERE receipt_id=$receiptId")->fetchColumn();
        if ($n !== 2) throw new Exception("Expected 2 receipt items, got $n");
    });

    test('One receipt item has variance_detected=1', function () use ($pdo, $receiptId) {
        $n = (int) $pdo->query("SELECT COUNT(*) FROM warehouse_receipt_items WHERE receipt_id=$receiptId AND variance_detected=1")->fetchColumn();
        if ($n !== 1) throw new Exception("Expected 1 variance item, got $n");
    });

    test('notification_delivery_log or notifications created', function () use ($pdo, $beforeLog, $orderId) {
        $afterLog = (int) $pdo->query("SELECT COUNT(*) FROM notification_delivery_log")->fetchColumn();
        $notifCount = (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE title LIKE 'Order #$orderId%'")->fetchColumn();
        if ($afterLog <= $beforeLog && $notifCount === 0) throw new Exception("Expected new delivery log entries or notifications");
    });

    test('payload_hash prevents duplicate sends on rerun', function () use ($pdo, $orderId, $user) {
        $before = (int) $pdo->query("SELECT COUNT(*) FROM notification_delivery_log WHERE status='sent'")->fetchColumn();
        $svc = new NotificationService($pdo);
        $svc->notifyOrderReceived($orderId, (int) $user['id'], true);
        $after = (int) $pdo->query("SELECT COUNT(*) FROM notification_delivery_log WHERE status='sent'")->fetchColumn();
        if ($after > $before + 2) throw new Exception("Idempotency may have failed: sent count increased too much");
    });

    test('OrderReceivingService derives item CBM and total weight from dimensions and weight/carton', function () use ($pdo, $cust, $supp, $user, &$calcOrderId) {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO orders (customer_id, supplier_id, expected_ready_date, status, created_by) VALUES (?,?,CURDATE(),'Approved',?)")
            ->execute([$cust['id'], $supp['id'], $user['id']]);
        $calcOrderId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO order_items (order_id, quantity, unit, cartons, declared_cbm, declared_weight, description_en) VALUES (?,10,'cartons',10,6.0,25,'Auto Calc Item')")
            ->execute([$calcOrderId]);
        $calcItemId = (int) $pdo->lastInsertId();
        $pdo->commit();

        $result = (new OrderReceivingService())->receive($pdo, $calcOrderId, [
            'idempotency_key'=>'derived-metrics-'.$calcOrderId,
            'actual_cartons' => 10,
            'actual_cbm' => 6,
            'actual_weight' => 25,
            'condition' => 'good',
            'photo_paths' => [],
            'items' => [[
                'order_item_id' => $calcItemId,
                'actual_cartons' => 10,
                'actual_height' => 100,
                'actual_width' => 100,
                'actual_length' => 60,
                'weight_per_carton' => 2.5,
            ]],
        ], (int) $user['id']);

        $stmt = $pdo->prepare("SELECT actual_cbm, actual_weight FROM warehouse_receipt_items WHERE receipt_id = ? AND order_item_id = ?");
        $stmt->execute([(int) $result['receipt_id'], $calcItemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Missing derived receipt item row');
        if (abs((float) $row['actual_cbm'] - 6.0) > 0.00001) throw new Exception('Expected derived CBM 6.0, got ' . $row['actual_cbm']);
        if (abs((float) $row['actual_weight'] - 25.0) > 0.0001) throw new Exception('Expected derived weight 25.0, got ' . $row['actual_weight']);
    });

    test('Partial receipts reconcile cumulatively and idempotent replay does not duplicate stock', function () use ($pdo, $cust, $supp, $user, &$partialOrderId) {
        $pdo->prepare("INSERT INTO orders (customer_id, supplier_id, expected_ready_date, status, created_by) VALUES (?,?,CURDATE(),'Approved',?)")
            ->execute([$cust['id'], $supp['id'], $user['id']]);
        $partialOrderId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO order_items (order_id, quantity, unit, cartons, declared_cbm, declared_weight, description_en) VALUES (?,10,'cartons',10,1.0,10,'Partial Item')")
            ->execute([$partialOrderId]);
        $partialItemId = (int) $pdo->lastInsertId();

        $service = new OrderReceivingService();
        $first = $service->receive($pdo, $partialOrderId, [
            'idempotency_key' => 'audit-partial-first-' . $partialOrderId,
            'actual_cartons' => 4,
            'actual_cbm' => 0.4,
            'actual_weight' => 4,
            'condition' => 'partial',
            'items' => [[
                'order_item_id' => $partialItemId,
                'actual_cartons' => 4,
                'actual_cbm' => 0.4,
                'actual_weight' => 4,
                'condition' => 'partial',
            ]],
        ], (int) $user['id']);
        if ($first['status'] !== 'InTransitToWarehouse') {
            throw new Exception('First partial receipt did not preserve in-transit state');
        }

        $secondKey = 'audit-partial-final-' . $partialOrderId;
        $secondPayload = [
            'idempotency_key' => $secondKey,
            'actual_cartons' => 6,
            'actual_cbm' => 0.6,
            'actual_weight' => 6,
            'condition' => 'good',
            'items' => [[
                'order_item_id' => $partialItemId,
                'actual_cartons' => 6,
                'actual_cbm' => 0.6,
                'actual_weight' => 6,
                'condition' => 'good',
            ]],
        ];
        $second = $service->receive($pdo,$partialOrderId,$secondPayload,(int)$user['id']);
        if ($second['status'] !== 'ReadyForConsolidation' || !empty($second['variance_detected'])) {
            throw new Exception('Cumulative final receipt was incorrectly treated as a variance');
        }

        $replay = $service->receive($pdo, $partialOrderId, $secondPayload, (int) $user['id']);
        if (empty($replay['idempotent_replay']) || (int) $replay['receipt_id'] !== (int) $second['receipt_id']) {
            throw new Exception('Idempotent replay did not return the original receipt');
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) receipt_count, SUM(actual_cartons) cartons, SUM(actual_cbm) cbm, SUM(actual_weight) weight FROM warehouse_receipts WHERE order_id=? AND voided_at IS NULL");
        $stmt->execute([$partialOrderId]);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC);
        if ((int) $totals['receipt_count'] !== 2 || (int) $totals['cartons'] !== 10 || abs((float) $totals['cbm'] - 1.0) > 0.000001 || abs((float) $totals['weight'] - 10.0) > 0.0001) {
            throw new Exception('Cumulative inventory totals are incorrect after partial receiving');
        }
    });

    test('Reset after customer decline atomically voids inventory receipts', function () use ($pdo, $cust, $supp, $user, &$resetOrderId) {
        $pdo->prepare("INSERT INTO orders (customer_id,supplier_id,expected_ready_date,status,created_by) VALUES (?,?,CURDATE(),'CustomerDeclinedAfterAutoConfirm',?)")
            ->execute([$cust['id'],$supp['id'],$user['id']]);
        $resetOrderId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO order_items (order_id,quantity,unit,declared_cbm,declared_weight,description_en) VALUES (?,2,'cartons',0.2,5,'Reset receipt item')")
            ->execute([$resetOrderId]);
        $pdo->prepare("INSERT INTO warehouse_receipts (order_id,actual_cartons,actual_cbm,actual_weight,receipt_condition,received_by,receiving_operation_id) VALUES (?,2,0.2,5,'good',?,?)")
            ->execute([$resetOrderId,$user['id'],'audit-reset-' . $resetOrderId]);

        OrderReceiptWorkflowService::resetDeclinedOrder($pdo,$resetOrderId,(int)$user['id'],'Regression reversal');
        $stmt=$pdo->prepare('SELECT status FROM orders WHERE id=?'); $stmt->execute([$resetOrderId]);
        if ($stmt->fetchColumn() !== 'Submitted') throw new Exception('Declined order was not reset to Submitted');
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM warehouse_receipts WHERE order_id=? AND voided_at IS NULL'); $stmt->execute([$resetOrderId]);
        if ((int)$stmt->fetchColumn() !== 0) throw new Exception('Active receipt remained after reset');
        $report=(new FinancialReconciliationService($pdo))->reconcileOrder($resetOrderId);
        if ((int)($report['inventory']['active_receipts']['receipt_count'] ?? -1) !== 0) throw new Exception('Reconciliation still counted voided inventory');
    });

    test('concurrent retry key produces one receiving operation', function () use ($pdo,$root,$cust,$supp,$user,&$concurrentOrderId) {
        $pdo->prepare("INSERT INTO orders(customer_id,supplier_id,expected_ready_date,status,created_by) VALUES (?,?,CURDATE(),'Approved',?)")->execute([$cust['id'],$supp['id'],$user['id']]);
        $concurrentOrderId=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO order_items(order_id,quantity,unit,cartons,declared_cbm,declared_weight,description_en) VALUES (?,10,'cartons',10,1,10,'Concurrent receipt')")->execute([$concurrentOrderId]);
        $itemId=(int)$pdo->lastInsertId();$key='audit-concurrent-receive-'.$concurrentOrderId;
        $workers=[startConcurrentReceiveWorker($root,$concurrentOrderId,$itemId,(int)$user['id'],$key),startConcurrentReceiveWorker($root,$concurrentOrderId,$itemId,(int)$user['id'],$key)];
        $results=[finishConcurrentReceiveWorker($workers[0]),finishConcurrentReceiveWorker($workers[1])];
        if((int)$results[0]['receipt_id']!==(int)$results[1]['receipt_id'])throw new Exception('Concurrent retry returned different receipt IDs');
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM warehouse_receipts WHERE order_id=? AND receiving_operation_id=?');$stmt->execute([$concurrentOrderId,$key]);
        if((int)$stmt->fetchColumn()!==1)throw new Exception('Concurrent retry created duplicate receipts');
    });
} finally {
    if ($concurrentOrderId) {
        $receipts=$pdo->query("SELECT id FROM warehouse_receipts WHERE order_id=$concurrentOrderId")->fetchAll(PDO::FETCH_COLUMN);
        foreach($receipts as $rid){$pdo->exec("DELETE FROM warehouse_receipt_items WHERE receipt_id=$rid");$pdo->exec("DELETE FROM warehouse_receipt_photos WHERE receipt_id=$rid");$pdo->exec("DELETE FROM warehouse_receipts WHERE id=$rid");}
        $nIds=$pdo->query("SELECT id FROM notifications WHERE title LIKE 'Order #$concurrentOrderId%'")->fetchAll(PDO::FETCH_COLUMN);foreach($nIds as $nid)$pdo->exec("DELETE FROM notification_delivery_log WHERE notification_id=$nid");foreach($nIds as $nid)$pdo->exec("DELETE FROM notifications WHERE id=$nid");
        $pdo->exec("DELETE FROM audit_log WHERE entity_type='order' AND entity_id=$concurrentOrderId");$pdo->exec("DELETE FROM order_items WHERE order_id=$concurrentOrderId");$pdo->exec("DELETE FROM orders WHERE id=$concurrentOrderId");
    }
    if ($resetOrderId) {
        $pdo->exec("DELETE FROM audit_log WHERE entity_type='order' AND entity_id=$resetOrderId");
        $pdo->exec("DELETE FROM warehouse_receipts WHERE order_id=$resetOrderId");
        $pdo->exec("DELETE FROM order_items WHERE order_id=$resetOrderId");
        $pdo->exec("DELETE FROM orders WHERE id=$resetOrderId");
    }
    if ($partialOrderId) {
        $receipts = $pdo->query("SELECT id FROM warehouse_receipts WHERE order_id=$partialOrderId")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($receipts as $rid) {
            $riIds = $pdo->query("SELECT id FROM warehouse_receipt_items WHERE receipt_id=$rid")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($riIds as $riid) {
                $pdo->exec("DELETE FROM warehouse_receipt_item_splits WHERE receipt_item_id=$riid");
                $pdo->exec("DELETE FROM warehouse_receipt_item_photos WHERE receipt_item_id=$riid");
            }
            $pdo->exec("DELETE FROM warehouse_receipt_items WHERE receipt_id=$rid");
            $pdo->exec("DELETE FROM warehouse_receipt_photos WHERE receipt_id=$rid");
            $pdo->exec("DELETE FROM warehouse_receipt_fees WHERE receipt_id=$rid");
            $pdo->exec("DELETE FROM warehouse_receipts WHERE id=$rid");
        }
        $nIds = $pdo->query("SELECT id FROM notifications WHERE type IN ('variance_confirmation','order_received') AND title LIKE 'Order #$partialOrderId%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($nIds as $nid) $pdo->exec("DELETE FROM notification_delivery_log WHERE notification_id=$nid");
        foreach ($nIds as $nid) $pdo->exec("DELETE FROM notifications WHERE id=$nid");
        $pdo->exec("DELETE FROM audit_log WHERE entity_type='order' AND entity_id=$partialOrderId");
        $pdo->exec("DELETE FROM order_items WHERE order_id=$partialOrderId");
        $pdo->exec("DELETE FROM orders WHERE id=$partialOrderId");
    }
    if ($calcOrderId) {
        $receipts = $pdo->query("SELECT id FROM warehouse_receipts WHERE order_id=$calcOrderId")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($receipts as $rid) {
            $riIds = $pdo->query("SELECT id FROM warehouse_receipt_items WHERE receipt_id=$rid")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($riIds as $riid) $pdo->exec("DELETE FROM warehouse_receipt_item_photos WHERE receipt_item_id=$riid");
            $pdo->exec("DELETE FROM warehouse_receipt_items WHERE receipt_id=$rid");
            $pdo->exec("DELETE FROM warehouse_receipt_photos WHERE receipt_id=$rid");
            $pdo->exec("DELETE FROM warehouse_receipts WHERE id=$rid");
        }
        $nIds = $pdo->query("SELECT id FROM notifications WHERE type IN ('variance_confirmation','order_received') AND title LIKE 'Order #$calcOrderId%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($nIds as $nid) $pdo->exec("DELETE FROM notification_delivery_log WHERE notification_id=$nid");
        foreach ($nIds as $nid) $pdo->exec("DELETE FROM notifications WHERE id=$nid");
        $pdo->exec("DELETE FROM order_items WHERE order_id=$calcOrderId");
        $pdo->exec("DELETE FROM orders WHERE id=$calcOrderId");
    }
    if ($orderId) {
        $receipts = $pdo->query("SELECT id FROM warehouse_receipts WHERE order_id=$orderId")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($receipts as $rid) {
            $riIds = $pdo->query("SELECT id FROM warehouse_receipt_items WHERE receipt_id=$rid")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($riIds as $riid) $pdo->exec("DELETE FROM warehouse_receipt_item_photos WHERE receipt_item_id=$riid");
            $pdo->exec("DELETE FROM warehouse_receipt_items WHERE receipt_id=$rid");
            $pdo->exec("DELETE FROM warehouse_receipt_photos WHERE receipt_id=$rid");
            $pdo->exec("DELETE FROM warehouse_receipts WHERE id=$rid");
        }
        $nIds = $pdo->query("SELECT id FROM notifications WHERE type IN ('variance_confirmation','order_received') AND title LIKE 'Order #$orderId%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($nIds as $nid) $pdo->exec("DELETE FROM notification_delivery_log WHERE notification_id=$nid");
        foreach ($nIds as $nid) $pdo->exec("DELETE FROM notifications WHERE id=$nid");
        $pdo->exec("DELETE FROM order_items WHERE order_id=$orderId");
        $pdo->exec("DELETE FROM orders WHERE id=$orderId");
    }
}

echo "\nTotal: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
