<?php
require_once __DIR__ . '/OrderStateService.php';

require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/ShipmentAccountingService.php';
require_once __DIR__ . '/ReceivingQuantityService.php';
require_once dirname(__DIR__) . '/api/helpers.php';

if (!class_exists('OrderReceivingValidationException')) {
    class OrderReceivingValidationException extends RuntimeException
    {
        private int $statusCode;
        private array $fieldErrors;

        public function __construct(string $message, int $statusCode = 400, array $fieldErrors = [])
        {
            parent::__construct($message);
            $this->statusCode = $statusCode;
            $this->fieldErrors = $fieldErrors;
        }

        public function getStatusCode(): int
        {
            return $this->statusCode;
        }

        public function getFieldErrors(): array
        {
            return $this->fieldErrors;
        }
    }
}

class OrderReceivingService
{
    public function receive(PDO $pdo, int $orderId, array $input, int $userId, bool $manageTransaction = true, array $options = []): array
    {
        if (isset($input['notes']) && !is_string($input['notes'])) throw new OrderReceivingValidationException('Receipt notes must be text', 400);
        if (isset($input['idempotency_key']) && !is_string($input['idempotency_key'])) throw new OrderReceivingValidationException('Receiving idempotency key must be text',400);
        $operationId = trim((string) ($options['idempotency_key'] ?? $input['idempotency_key'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $operationId)) {
            throw new OrderReceivingValidationException('A receiving idempotency key (8–64 letters, digits, dots, colons, underscores or hyphens) is required', 400);
        }
        if (!$this->tableHasColumn($pdo, 'warehouse_receipts', 'receiving_operation_id')) throw new OrderReceivingValidationException('Receiving retry protection is not installed. Run migration 075.', 503);
        $requestHash = $this->requestHash($input);
        if ($operationId !== '' && $this->tableHasColumn($pdo, 'warehouse_receipts', 'receiving_operation_id')) {
            $existing = $pdo->prepare('SELECT id, order_id FROM warehouse_receipts WHERE receiving_operation_id=? LIMIT 1');
            $existing->execute([$operationId]);
            $existingReceipt = $existing->fetch(PDO::FETCH_ASSOC);
            $existingId = (int) ($existingReceipt['id'] ?? 0);
            if ($existingId > 0) {
                if ((int) $existingReceipt['order_id'] !== $orderId) throw new OrderReceivingValidationException('Receiving idempotency key belongs to another order', 409);
                $this->validateReplay($pdo, $orderId, $existingId, $requestHash);
                return ['status' => 'already_received', 'receipt_id' => $existingId, 'idempotent_replay' => true];
            }
        }
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            throw new OrderReceivingValidationException('Order not found', 404);
        }

        $allowed = ['Approved', 'InTransitToWarehouse'];
        if (!in_array($order['status'], $allowed, true)) {
            throw new OrderReceivingValidationException('Order must be Approved or InTransitToWarehouse to receive', 400);
        }

        $condition = $input['condition'] ?? 'good';
        if (!in_array($condition, ['good', 'damaged', 'partial'], true)) {
            throw new OrderReceivingValidationException('Invalid receipt condition', 400);
        }

        $photoPaths = $this->normalizeStoredUploadPathList($input['photo_paths'] ?? []);
        $receiptFees = $this->normalizeReceiptFees($input['fees'] ?? [], (string) ($order['currency'] ?? 'USD'));
        if ($receiptFees && !$this->tableExists($pdo, 'warehouse_receipt_fees')) {
            throw new OrderReceivingValidationException('Receipt fee storage is not installed. Run the latest database migrations.', 500);
        }
        $receiptFeeTotals = $this->summarizeReceiptFees($receiptFees);
        $itemsInput = is_array($input['items'] ?? null) ? $input['items'] : [];
        $config = require dirname(__DIR__) . '/config/config.php';
        $thresholdPct = $config['variance_threshold_percent'] ?? 10;
        $thresholdAbs = $config['variance_threshold_abs_cbm'] ?? 0.1;
        $photoEvidencePerItem = (int) ($config['photo_evidence_per_item'] ?? 0);
        $itemLevelEnabled = (int) ($config['item_level_receiving_enabled'] ?? 0);

        $startedTransaction = false;
        if ($manageTransaction && !$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }
        if (!$manageTransaction && !$pdo->inTransaction()) {
            throw new RuntimeException('Caller-managed receiving requires an active transaction');
        }
        try {
            // Serialize before reading prior partial receipts or declarations.
            $lock = $pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');
            $lock->execute([$orderId]);
            $order = $lock->fetch(PDO::FETCH_ASSOC);
            $lockedStatus = (string) ($order['status'] ?? '');
            if ($operationId !== '' && $this->tableHasColumn($pdo, 'warehouse_receipts', 'receiving_operation_id')) {
                $existing = $pdo->prepare('SELECT id, order_id FROM warehouse_receipts WHERE receiving_operation_id=? LIMIT 1 FOR UPDATE');
                $existing->execute([$operationId]);
                $existingReceipt = $existing->fetch(PDO::FETCH_ASSOC);
                $existingId = (int) ($existingReceipt['id'] ?? 0);
                if ($existingId > 0) {
                    if ((int) $existingReceipt['order_id'] !== $orderId) throw new OrderReceivingValidationException('Receiving idempotency key belongs to another order', 409);
                    $this->validateReplay($pdo, $orderId, $existingId, $requestHash);
                    if ($startedTransaction) $pdo->commit();
                    return ['status'=>'already_received','receipt_id'=>$existingId,'idempotent_replay'=>true];
                }
            }
            if (!in_array($lockedStatus, $allowed, true)) {
                throw new OrderReceivingValidationException('Order has already been received or is no longer receivable', 409);
            }
        $orderItems = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ? FOR UPDATE");
        $orderItems->execute([$orderId]);
        $orderItemsRows = $orderItems->fetchAll(PDO::FETCH_ASSOC);
        $declaredCbm = array_sum(array_column($orderItemsRows, 'declared_cbm'));

        $priorSql = "SELECT COALESCE(SUM(actual_cbm),0) cbm,
                            COALESCE(SUM(actual_weight),0) weight,
                            COALESCE(SUM(actual_cartons),0) cartons,
                            COALESCE(MAX(receipt_condition='damaged'),0) has_damage
                       FROM warehouse_receipts WHERE order_id=?";
        if ($this->tableHasColumn($pdo, 'warehouse_receipts', 'voided_at')) $priorSql .= ' AND voided_at IS NULL';
        $priorStmt = $pdo->prepare($priorSql . ' FOR UPDATE'); $priorStmt->execute([$orderId]);
        $prior = $priorStmt->fetch(PDO::FETCH_ASSOC) ?: ['cbm'=>0,'weight'=>0,'cartons'=>0,'has_damage'=>0];
        $receivedQuantitySql = ReceivingQuantityService::legacyQuantitySql();
        $priorItemSql = "SELECT wri.order_item_id,
                                COALESCE(SUM(wri.actual_cbm),0) cbm,
                                SUM($receivedQuantitySql) quantity,
                                MAX(($receivedQuantitySql) IS NULL) unknown_quantity,
                                COALESCE(MAX(wri.variance_detected),0) has_variance,
                                COALESCE(MAX(wri.receipt_condition='damaged'),0) has_damage
                           FROM warehouse_receipt_items wri
                           JOIN warehouse_receipts wr ON wr.id=wri.receipt_id
                           JOIN order_items oi ON oi.id=wri.order_item_id
                          WHERE wr.order_id=?";
        if ($this->tableHasColumn($pdo, 'warehouse_receipts', 'voided_at')) $priorItemSql .= ' AND wr.voided_at IS NULL';
        $priorItemSql .= ' GROUP BY wri.order_item_id FOR UPDATE';
        $priorItemStmt = $pdo->prepare($priorItemSql); $priorItemStmt->execute([$orderId]);
        $priorItems = [];
        foreach ($priorItemStmt->fetchAll(PDO::FETCH_ASSOC) as $priorItem) {
            $priorItems[(int) $priorItem['order_item_id']] = $priorItem;
        }
        if (abs((float)$prior['cbm'] - array_sum(array_column($priorItems,'cbm'))) > 0.000001) {
            throw new OrderReceivingValidationException('Prior receipt item allocation is incomplete; reconcile receipt history before receiving more', 409);
        }
        $unallocated=$pdo->prepare('SELECT wr.id FROM warehouse_receipts wr WHERE wr.order_id=? AND wr.voided_at IS NULL AND NOT EXISTS (SELECT 1 FROM warehouse_receipt_items wri WHERE wri.receipt_id=wr.id) LIMIT 1 FOR UPDATE');
        $unallocated->execute([$orderId]);
        if($unallocated->fetchColumn()) throw new OrderReceivingValidationException('Prior receipt has no item quantities; reconcile receipt history before receiving more',409);
        try {
            $input = ReceivingQuantityService::normalize($input, $orderItemsRows, $priorItems);
        } catch (InvalidArgumentException $e) {
            throw new OrderReceivingValidationException($e->getMessage(), 400);
        }
        $itemsInput = $input['items'];
        $actualCartons = (int) $input['actual_cartons'];
        $actualCbm = (float) $input['actual_cbm'];
        $actualWeight = (float) $input['actual_weight'];
        $isPartial = $input['is_partial'];
        if ($condition !== 'damaged') $condition = $isPartial ? 'partial' : 'good';
        foreach ($itemsInput as &$receiptItem) {
            if (($receiptItem['condition'] ?? 'good') !== 'damaged') $receiptItem['condition'] = $isPartial ? 'partial' : 'good';
        }
        unset($receiptItem);
        $hasDamage = $condition === 'damaged' || (!$isPartial && (!empty($prior['has_damage']) || (bool)array_filter($priorItems, static fn($item)=>!empty($item['has_damage']))));
        $comparisonCbm = (float) $prior['cbm'] + $actualCbm;
        $orderVariancePct = $declaredCbm > 0 ? abs($comparisonCbm - $declaredCbm) / $declaredCbm * 100 : 0;
        $orderVarianceAbs = abs($comparisonCbm - $declaredCbm);
        $hasVariance = $hasDamage || (!$isPartial && ($orderVariancePct >= $thresholdPct || $orderVarianceAbs >= $thresholdAbs));
        $itemVariances = [];
        $normalizedReceiptSplitsByItem = [];

        if (!empty($itemsInput)) {
            $sumCbm = 0;
            $sumWeight = 0;
            $hasItemCbm = false;
            $hasItemWeight = false;
            $errors = [];

            foreach ($itemsInput as $idx => $it) {
                if (!is_array($it)) {
                    $errors["items.$idx"] = 'Invalid item payload';
                    continue;
                }
                $oiId = (int) ($it['order_item_id'] ?? 0);
                $oi = null;
                foreach ($orderItemsRows as $o) {
                    if ((int) $o['id'] === $oiId) {
                        $oi = $o;
                        break;
                    }
                }
                if (!$oi) {
                    $errors["items.$idx.order_item_id"] = 'Invalid order_item_id';
                    continue;
                }

                $packagingSplits = $this->normalizeReceiptPackagingSplits($it);
                if ($packagingSplits) {
                    $splitTotals = $this->aggregateReceiptPackagingSplits($packagingSplits);
                    $normalizedReceiptSplitsByItem[$oiId] = $packagingSplits;
                    foreach ($splitTotals as $field => $value) {
                        if ($value !== null) {
                            $it[$field] = $value;
                        }
                    }
                }

                $aCbm = isset($it['actual_cbm']) ? (float) $it['actual_cbm'] : null;
                $aWeight = isset($it['actual_weight']) ? (float) $it['actual_weight'] : null;
                $aHeight = isset($it['actual_height']) && $it['actual_height'] !== ''
                    ? (float) $it['actual_height']
                    : null;
                $aWidth = isset($it['actual_width']) && $it['actual_width'] !== ''
                    ? (float) $it['actual_width']
                    : null;
                $aLength = isset($it['actual_length']) && $it['actual_length'] !== ''
                    ? (float) $it['actual_length']
                    : null;
                $aCartons = isset($it['actual_cartons']) ? (int) $it['actual_cartons'] : null;
                $aWeightPerCarton = isset($it['weight_per_carton']) && $it['weight_per_carton'] !== ''
                    ? (float) $it['weight_per_carton']
                    : null;
                $aPiecesPerCarton = isset($it['actual_pieces_per_carton']) && $it['actual_pieces_per_carton'] !== ''
                    ? (float) $it['actual_pieces_per_carton']
                    : null;
                $aQuantity = isset($it['actual_quantity']) && $it['actual_quantity'] !== ''
                    ? (float) $it['actual_quantity']
                    : null;
                $aUnitPrice = isset($it['unit_price']) && $it['unit_price'] !== ''
                    ? (float) $it['unit_price']
                    : null;
                $aTotalAmount = isset($it['total_amount']) && $it['total_amount'] !== ''
                    ? (float) $it['total_amount']
                    : null;

                if (($aQuantity === null) && $aCartons !== null && $aPiecesPerCarton !== null && $aCartons > 0 && $aPiecesPerCarton > 0) {
                    $aQuantity = round($aCartons * $aPiecesPerCarton, 4);
                }
                if (($aTotalAmount === null) && $aQuantity !== null && $aUnitPrice !== null && $aQuantity > 0 && $aUnitPrice >= 0) {
                    $aTotalAmount = round($aQuantity * $aUnitPrice, 4);
                }
                if (($aCbm === null) && $aCartons !== null) {
                    $derivedCbm = $this->calculateCbmFromDimensions($aCartons, $aHeight, $aWidth, $aLength);
                    if ($derivedCbm !== null) {
                        $aCbm = $derivedCbm;
                    }
                }
                if (($aWeight === null) && $aCartons !== null && $aWeightPerCarton !== null && $aCartons > 0 && $aWeightPerCarton > 0) {
                    $aWeight = round($aCartons * $aWeightPerCarton, 4);
                }

                if (($aPiecesPerCarton !== null && $aPiecesPerCarton < 0)
                    || ($aQuantity !== null && $aQuantity < 0)
                    || ($aUnitPrice !== null && $aUnitPrice < 0)
                    || ($aTotalAmount !== null && $aTotalAmount < 0)) {
                    $errors["items.$idx.quantity_price"] = 'Quantity and price fields must be zero or positive';
                }

                foreach ($packagingSplits as $splitIndex => $split) {
                    if (($split['cartons'] !== null && $split['cartons'] < 0)
                        || ($split['pieces_per_carton'] !== null && $split['pieces_per_carton'] < 0)
                        || ($split['quantity'] !== null && $split['quantity'] < 0)
                        || ($split['unit_price'] !== null && $split['unit_price'] < 0)
                        || ($split['total_amount'] !== null && $split['total_amount'] < 0)) {
                        $errors["items.$idx.packaging_splits.$splitIndex"] = 'Packaging split quantity and price fields must be zero or positive';
                    }
                }

                if (($aCartons !== null && $aCartons < 0)
                    || ($aCbm !== null && $aCbm < 0)
                    || ($aWeight !== null && $aWeight < 0)
                    || ($aWeightPerCarton !== null && $aWeightPerCarton < 0)
                    || ($aHeight !== null && $aHeight < 0)
                    || ($aWidth !== null && $aWidth < 0)
                    || ($aLength !== null && $aLength < 0)) {
                    $errors["items.$idx.actuals"] = 'Actual cartons, CBM, weight, weight per carton, and dimensions must be zero or positive';
                }

                $itCond = $it['condition'] ?? 'good';
                if (!in_array($itCond, ['good', 'damaged', 'partial'], true)) {
                    $itCond = 'good';
                }
                $itPhotos = $this->normalizeStoredUploadPathList($it['photo_paths'] ?? []);
                $decCbm = (float) $oi['declared_cbm'];
                $priorItem = $priorItems[$oiId] ?? ['cbm' => 0, 'has_variance' => 0, 'has_damage' => 0];
                $itemDamaged = $itCond === 'damaged' || (!$isPartial && (!empty($priorItem['has_damage']) || !empty($priorItem['has_variance'])));
                $itemVar = $itemDamaged;
                if ($itemDamaged) {
                    $hasDamage = true;
                }
                if ($aCbm !== null && !$isPartial && $itCond !== 'partial') {
                    $cumulativeItemCbm = (float) $priorItem['cbm'] + $aCbm;
                    $varPct = $decCbm > 0 ? abs($cumulativeItemCbm - $decCbm) / $decCbm * 100 : 0;
                    $varAbs = abs($cumulativeItemCbm - $decCbm);
                    $itemVar = $itemVar || $varPct >= $thresholdPct || $varAbs >= $thresholdAbs;
                }
                $itemVariances[$oiId] = $itemVar;
                if ($itemVar) {
                    $hasVariance = true;
                }
                if ($aCbm !== null) {
                    $sumCbm += $aCbm;
                    $hasItemCbm = true;
                }
                if ($aWeight !== null) {
                    $sumWeight += $aWeight;
                    $hasItemWeight = true;
                }
                if ($photoEvidencePerItem && $itemVar && empty($itPhotos)) {
                    $errors["items.$idx.photo_paths"] = 'Photo evidence required for item with variance';
                }
            }

            if (!empty($errors)) {
                throw new OrderReceivingValidationException('Validation failed', 400, $errors);
            }

            if ($actualCbm <= 0 && $hasItemCbm) {
                $actualCbm = round($sumCbm, 6);
            }
            if ($actualWeight <= 0 && $hasItemWeight) {
                $actualWeight = round($sumWeight, 4);
            }

            $comparisonCbm = (float) $prior['cbm'] + $actualCbm;
            $orderVariancePct = $declaredCbm > 0 ? abs($comparisonCbm - $declaredCbm) / $declaredCbm * 100 : 0;
            $orderVarianceAbs = abs($comparisonCbm - $declaredCbm);
            $hasVariance = $hasDamage || (!$isPartial && ($orderVariancePct >= $thresholdPct
                || $orderVarianceAbs >= $thresholdAbs
                || in_array(true, $itemVariances, true)));

            $tolerance = 0.01;
            if (($hasItemCbm && abs($sumCbm - $actualCbm) > $tolerance)
                || ($hasItemWeight && abs($sumWeight - $actualWeight) > $tolerance)) {
                throw new OrderReceivingValidationException('Item-level totals must match order-level actuals (CBM/weight)', 400);
            }
        }

        if (($hasVariance || $hasDamage) && empty($photoPaths)) {
            throw new OrderReceivingValidationException('Evidence photos required when variance or damage is present', 400);
        }
        if ($itemLevelEnabled && empty($itemsInput)) {
            throw new OrderReceivingValidationException('Item-level receiving is required; provide items array', 400);
        }

            $receiptCols = 'order_id, actual_cartons, actual_cbm, actual_weight, receipt_condition, notes, received_by';
            $receiptVals = '?,?,?,?,?,?,?';
            $receiptParams = [$orderId, $actualCartons, $actualCbm, $actualWeight, $condition, $input['notes'] ?? null, $userId];
            if ($this->tableHasColumn($pdo, 'warehouse_receipts', 'receiving_operation_id')) {
                $receiptCols .= ', receiving_operation_id'; $receiptVals .= ',?'; $receiptParams[] = $operationId ?: null;
            }
            $pdo->prepare("INSERT INTO warehouse_receipts ($receiptCols) VALUES ($receiptVals)")->execute($receiptParams);
            $receiptId = (int) $pdo->lastInsertId();

            $insPhoto = $pdo->prepare("INSERT INTO warehouse_receipt_photos (receipt_id, file_path) VALUES (?,?)");
            foreach ($photoPaths as $path) {
                $insPhoto->execute([$receiptId, $path]);
            }

            if ($receiptFees) {
                $insFee = $pdo->prepare(
                    "INSERT INTO warehouse_receipt_fees (receipt_id, order_id, fee_label, amount, currency, notes, created_by)
                     VALUES (?,?,?,?,?,?,?)"
                );
                foreach ($receiptFees as $fee) {
                    $insFee->execute([
                        $receiptId,
                        $orderId,
                        $fee['label'],
                        $fee['amount'],
                        $fee['currency'],
                        $fee['notes'],
                        $userId,
                    ]);
                }
                (new ShipmentAccountingService($pdo))->postReceiptFees($receiptId, $userId);
            }

            if (!empty($itemsInput)) {
                $receiptItemCols = "receipt_id, order_item_id, actual_cartons, actual_cbm, actual_weight, receipt_condition, variance_detected, notes";
                $receiptItemVals = "?,?,?,?,?,?,?,?";
                $receiptExtraCols = [];
                foreach (['actual_pieces_per_carton', 'actual_quantity', 'unit_price', 'total_amount', 'actual_height', 'actual_width', 'actual_length'] as $column) {
                    if ($this->tableHasColumn($pdo, 'warehouse_receipt_items', $column)) {
                        $receiptExtraCols[] = $column;
                        $receiptItemCols .= ", $column";
                        $receiptItemVals .= ",?";
                    }
                }

                $insItem = $pdo->prepare("INSERT INTO warehouse_receipt_items ($receiptItemCols) VALUES ($receiptItemVals)");
                $insItemPhoto = $pdo->prepare("INSERT INTO warehouse_receipt_item_photos (receipt_item_id, file_path) VALUES (?,?)");
                $insSplit = $this->tableExists($pdo, 'warehouse_receipt_item_splits')
                    ? $pdo->prepare("INSERT INTO warehouse_receipt_item_splits (receipt_item_id, line_no, cartons, pieces_per_carton, quantity, unit_price, total_amount) VALUES (?,?,?,?,?,?,?)")
                    : null;

                foreach ($itemsInput as $it) {
                    if (!is_array($it)) {
                        continue;
                    }
                    $oiId = (int) ($it['order_item_id'] ?? 0);
                    $packagingSplits = $normalizedReceiptSplitsByItem[$oiId] ?? $this->normalizeReceiptPackagingSplits($it);
                    if ($packagingSplits) {
                        $splitTotals = $this->aggregateReceiptPackagingSplits($packagingSplits);
                        foreach ($splitTotals as $field => $value) {
                            if ($value !== null) {
                                $it[$field] = $value;
                            }
                        }
                    }
                    $aCbm = isset($it['actual_cbm']) ? (float) $it['actual_cbm'] : null;
                    $aWeight = isset($it['actual_weight']) ? (float) $it['actual_weight'] : null;
                    $aHeight = isset($it['actual_height']) && $it['actual_height'] !== ''
                        ? (float) $it['actual_height']
                        : null;
                    $aWidth = isset($it['actual_width']) && $it['actual_width'] !== ''
                        ? (float) $it['actual_width']
                        : null;
                    $aLength = isset($it['actual_length']) && $it['actual_length'] !== ''
                        ? (float) $it['actual_length']
                        : null;
                    $aCartons = isset($it['actual_cartons']) ? (int) $it['actual_cartons'] : null;
                    $aWeightPerCarton = isset($it['weight_per_carton']) && $it['weight_per_carton'] !== ''
                        ? (float) $it['weight_per_carton']
                        : null;
                    $aPiecesPerCarton = isset($it['actual_pieces_per_carton']) && $it['actual_pieces_per_carton'] !== ''
                        ? (float) $it['actual_pieces_per_carton']
                        : null;
                    $aQuantity = isset($it['actual_quantity']) && $it['actual_quantity'] !== ''
                        ? (float) $it['actual_quantity']
                        : null;
                    $aUnitPrice = isset($it['unit_price']) && $it['unit_price'] !== ''
                        ? (float) $it['unit_price']
                        : null;
                    $aTotalAmount = isset($it['total_amount']) && $it['total_amount'] !== ''
                        ? (float) $it['total_amount']
                        : null;

                    if (($aQuantity === null) && $aCartons !== null && $aPiecesPerCarton !== null && $aCartons > 0 && $aPiecesPerCarton > 0) {
                        $aQuantity = round($aCartons * $aPiecesPerCarton, 4);
                    }
                    if (($aTotalAmount === null) && $aQuantity !== null && $aUnitPrice !== null && $aQuantity > 0 && $aUnitPrice >= 0) {
                        $aTotalAmount = round($aQuantity * $aUnitPrice, 4);
                    }
                    if (($aCbm === null) && $aCartons !== null) {
                        $derivedCbm = $this->calculateCbmFromDimensions($aCartons, $aHeight, $aWidth, $aLength);
                        if ($derivedCbm !== null) {
                            $aCbm = $derivedCbm;
                        }
                    }
                    if (($aWeight === null) && $aCartons !== null && $aWeightPerCarton !== null && $aCartons > 0 && $aWeightPerCarton > 0) {
                        $aWeight = round($aCartons * $aWeightPerCarton, 4);
                    }

                    $itCond = in_array($it['condition'] ?? 'good', ['good', 'damaged', 'partial'], true) ? ($it['condition'] ?? 'good') : 'good';
                    $varDet = $itemVariances[$oiId] ?? 0;
                    $receiptParams = [$receiptId, $oiId, $aCartons, $aCbm, $aWeight, $itCond, $varDet ? 1 : 0, $it['notes'] ?? null];
                    foreach ($receiptExtraCols as $column) {
                        switch ($column) {
                            case 'actual_pieces_per_carton':
                                $receiptParams[] = $aPiecesPerCarton;
                                break;
                            case 'actual_quantity':
                                $receiptParams[] = $aQuantity;
                                break;
                            case 'unit_price':
                                $receiptParams[] = $aUnitPrice;
                                break;
                            case 'total_amount':
                                $receiptParams[] = $aTotalAmount;
                                break;
                            case 'actual_height':
                                $receiptParams[] = $aHeight;
                                break;
                            case 'actual_width':
                                $receiptParams[] = $aWidth;
                                break;
                            case 'actual_length':
                                $receiptParams[] = $aLength;
                                break;
                            default:
                                $receiptParams[] = null;
                        }
                    }
                    $insItem->execute($receiptParams);
                    $riId = (int) $pdo->lastInsertId();

                    if ($insSplit && $packagingSplits) {
                        foreach (array_values($packagingSplits) as $splitIndex => $split) {
                            $insSplit->execute([
                                $riId,
                                $splitIndex + 1,
                                $split['cartons'],
                                $split['pieces_per_carton'],
                                $split['quantity'],
                                $split['unit_price'],
                                $split['total_amount'],
                            ]);
                        }
                    }

                    foreach ($this->normalizeStoredUploadPathList($it['photo_paths'] ?? []) as $p) {
                        $insItemPhoto->execute([$riId, $p]);
                    }
                }
            }

            $newStatus = $isPartial ? 'InTransitToWarehouse' : ($hasVariance ? 'Confirmed' : 'ReadyForConsolidation');
            if ($newStatus !== $order['status']) OrderStateService::validateTransition($order['status'], $newStatus);
            $confirmToken = null;
            if ($hasVariance && !$isPartial) {
                $confirmToken = bin2hex(random_bytes(24));
                $pdo->prepare("UPDATE orders SET status=?, confirmation_token=? WHERE id=?")->execute([$newStatus, $confirmToken, $orderId]);
            } else {
                $pdo->prepare("UPDATE orders SET status=? WHERE id=?")->execute([$newStatus, $orderId]);
            }

            $auditPayload = [
                'actual_cbm' => $actualCbm,
                'actual_weight' => $actualWeight,
                'status' => $newStatus,
                'receipt_id' => $receiptId,
                'request_hash' => $requestHash,
                'quantity_totals' => $input['quantity_totals'],
                'fees_count' => count($receiptFees),
                'fees_total' => $receiptFeeTotals,
            ];
            if (!empty($options['source'])) {
                $auditPayload['source'] = (string) $options['source'];
            }
            if (!empty($options['import_id'])) {
                $auditPayload['import_id'] = (int) $options['import_id'];
            }

            $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('order',?,?,?,?)")
                ->execute([$orderId, 'receive', json_encode($auditPayload), $userId]);
            if (function_exists('logClms')) {
                logClms('order_received', [
                    'order_id' => $orderId,
                    'receipt_id' => $receiptId,
                    'user_id' => $userId,
                    'item_level' => !empty($itemsInput),
                    'variance_detected' => $hasVariance,
                    'fees_count' => count($receiptFees),
                    'fees_total' => $receiptFeeTotals,
                    'source' => $options['source'] ?? 'manual',
                    'import_id' => $options['import_id'] ?? null,
                ]);
            }
            (new NotificationService($pdo))->notifyOrderReceived($orderId, $userId, $hasVariance, $confirmToken, $isPartial);

            if ($startedTransaction) {
                $pdo->commit();
            }

            return [
                'status' => $newStatus,
                'receipt_id' => $receiptId,
                'variance_detected' => $hasVariance,
                'quantity_totals' => $input['quantity_totals'],
                'fees_count' => count($receiptFees),
                'fees_total' => $receiptFeeTotals,
            ];
        } catch (Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function normalizeStoredUploadPathList($paths): array
    {
        if (!is_array($paths)) throw new OrderReceivingValidationException('Photo paths must be an array', 400);
        $normalized = [];
        foreach ($paths as $path) {
            if (!is_string($path)) throw new OrderReceivingValidationException('Invalid photo path', 400);
            try {
                $meta = clmsResolveStoredUploadPathMeta($path);
                clmsAuthorizeUploadForCurrentActor($meta['normalized']);
                $imageInfo=@getimagesize($meta['resolved_path']);
                if ($imageInfo === false || (float)$imageInfo[0]*(float)$imageInfo[1]>25000000) throw new InvalidArgumentException('Receipt evidence must be an uploaded image within the supported dimensions');
                $normalized[] = $meta['normalized'];
            } catch (InvalidArgumentException $e) {
                throw new OrderReceivingValidationException($e->getMessage(), 400);
            }
        }
        return array_values(array_unique($normalized));
    }

    private function requestHash(array $input): string
    {
        unset($input['idempotency_key']);
        $sort = static function ($value) use (&$sort) {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value);
            return array_map($sort, $value);
        };
        try { return hash('sha256', json_encode($sort($input), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)); }
        catch (JsonException $e) { throw new OrderReceivingValidationException('Receipt contains invalid text or non-finite numeric values',400); }
    }

    private function validateReplay(PDO $pdo, int $orderId, int $receiptId, string $hash): void
    {
        $stmt = $pdo->prepare('SELECT voided_at FROM warehouse_receipts WHERE id=?');
        $stmt->execute([$receiptId]);
        if ($stmt->fetchColumn() !== null) throw new OrderReceivingValidationException('This receiving operation was voided; use a new operation key', 409);
        $stmt = $pdo->prepare("SELECT new_value FROM audit_log WHERE entity_type='order' AND entity_id=? AND action='receive' ORDER BY id DESC");
        $stmt->execute([$orderId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $audit = json_decode($json, true);
            if ((int) ($audit['receipt_id'] ?? 0) !== $receiptId) continue;
            if (isset($audit['request_hash']) && hash_equals($audit['request_hash'], $hash)) return;
            break;
        }
        throw new OrderReceivingValidationException('Receiving key payload differs or cannot be verified; inspect the existing receipt', 409);
    }

    private function normalizeReceiptFees($rawFees, string $defaultCurrency): array
    {
        if (!is_array($rawFees)) {
            throw new OrderReceivingValidationException('Receipt fees must be an array',400);
        }

        $defaultCurrency = $this->normalizeCurrency($defaultCurrency);
        $fees = [];
        $errors = [];

        foreach ($rawFees as $idx => $rawFee) {
            if (!is_array($rawFee)) {
                throw new OrderReceivingValidationException('Invalid receipt fee',400);
            }
            foreach (['label','fee_label','description','type','notes','currency'] as $field) if (isset($rawFee[$field]) && !is_string($rawFee[$field])) throw new OrderReceivingValidationException('Receipt fee '.$field.' must be text',400);

            $amountRaw = $rawFee['amount'] ?? null;
            $amountText = is_string($amountRaw)
                ? str_replace([',', ' '], '', trim($amountRaw))
                : $amountRaw;
            $label = trim((string) (
                $rawFee['label']
                ?? $rawFee['fee_label']
                ?? $rawFee['description']
                ?? $rawFee['type']
                ?? ''
            ));
            $notes = trim((string) ($rawFee['notes'] ?? ''));

            if (($amountText === null || $amountText === '') && $label === '' && $notes === '') {
                continue;
            }
            if (!is_numeric($amountText) || !is_finite((float)$amountText)) {
                $errors["fees.$idx.amount"] = 'Fee amount must be numeric';
                continue;
            }

            $amount = round((float) $amountText, 4);
            if ($amount < 0 || $amount > 99999999.9999) {
                $errors["fees.$idx.amount"] = 'Fee amount must be between zero and 99999999.9999';
                continue;
            }
            if ($amount <= 0) {
                continue;
            }

            if ($label === '') {
                $label = 'Warehouse fee';
            }

            $currency = $this->normalizeCurrency((string) ($rawFee['currency'] ?? $defaultCurrency));
            if ($currency !== $defaultCurrency) {
                $errors["fees.$idx.currency"] = 'Receiving fee currency must match the order currency';
                continue;
            }
            $fees[] = [
                'label' => mb_substr($label, 0, 160),
                'amount' => $amount,
                'currency' => $currency,
                'notes' => $notes !== '' ? $notes : null,
            ];
        }

        if ($errors) {
            throw new OrderReceivingValidationException('Validation failed', 400, $errors);
        }

        return $fees;
    }

    private function normalizeCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        return $currency !== '' ? mb_substr($currency, 0, 10) : 'USD';
    }

    private function summarizeReceiptFees(array $fees): array
    {
        $totals = [];
        foreach ($fees as $fee) {
            $currency = $this->normalizeCurrency((string) ($fee['currency'] ?? 'USD'));
            if (!isset($totals[$currency])) {
                $totals[$currency] = 0.0;
            }
            $totals[$currency] += (float) ($fee['amount'] ?? 0);
        }

        foreach ($totals as $currency => $amount) {
            $totals[$currency] = round($amount, 4);
        }

        return $totals;
    }

    private function calculateCbmFromDimensions(?float $cartons, ?float $height, ?float $width, ?float $length): ?float
    {
        if ($cartons === null || $height === null || $width === null || $length === null) {
            return null;
        }
        if ($cartons <= 0 || $height <= 0 || $width <= 0 || $length <= 0) {
            return null;
        }
        return round(($height * $width * $length * $cartons) / 1000000, 6);
    }

    private function tableHasColumn(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $stmt->execute([$column]);
            $cache[$key] = (bool) $stmt->rowCount();
        } catch (Throwable $e) {
            $cache[$key] = false;
        }

        return $cache[$key];
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }
        try {
            $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            $cache[$table] = (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            $cache[$table] = false;
        }
        return $cache[$table];
    }

    private function normalizeReceiptPackagingSplits(array $itemInput): array
    {
        $rawSplits = $itemInput['packaging_splits'] ?? $itemInput['splits'] ?? [];
        if (!is_array($rawSplits)) {
            $rawSplits = [];
        }

        $splits = [];
        foreach ($rawSplits as $rawSplit) {
            if (!is_array($rawSplit)) {
                continue;
            }
            $cartons = isset($rawSplit['cartons']) && $rawSplit['cartons'] !== '' ? (float) $rawSplit['cartons'] : null;
            $pieces = isset($rawSplit['pieces_per_carton']) && $rawSplit['pieces_per_carton'] !== '' ? (float) $rawSplit['pieces_per_carton'] : null;
            $quantity = isset($rawSplit['quantity']) && $rawSplit['quantity'] !== '' ? (float) $rawSplit['quantity'] : null;
            $unitPrice = isset($rawSplit['unit_price']) && $rawSplit['unit_price'] !== '' ? (float) $rawSplit['unit_price'] : null;
            $totalAmount = isset($rawSplit['total_amount']) && $rawSplit['total_amount'] !== '' ? (float) $rawSplit['total_amount'] : null;

            if (($quantity === null) && $cartons !== null && $pieces !== null && $cartons > 0 && $pieces > 0) {
                $quantity = round($cartons * $pieces, 4);
            }
            if (($totalAmount === null) && $quantity !== null && $unitPrice !== null && $quantity > 0 && $unitPrice >= 0) {
                $totalAmount = round($quantity * $unitPrice, 4);
            }

            if ($cartons === null && $pieces === null && $quantity === null && $unitPrice === null && $totalAmount === null) {
                continue;
            }

            $splits[] = [
                'cartons' => $cartons,
                'pieces_per_carton' => $pieces,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_amount' => $totalAmount,
            ];
        }

        if (!$splits) {
            $cartons = isset($itemInput['actual_cartons']) && $itemInput['actual_cartons'] !== '' ? (float) $itemInput['actual_cartons'] : null;
            $pieces = isset($itemInput['actual_pieces_per_carton']) && $itemInput['actual_pieces_per_carton'] !== '' ? (float) $itemInput['actual_pieces_per_carton'] : null;
            $quantity = isset($itemInput['actual_quantity']) && $itemInput['actual_quantity'] !== '' ? (float) $itemInput['actual_quantity'] : null;
            $unitPrice = isset($itemInput['unit_price']) && $itemInput['unit_price'] !== '' ? (float) $itemInput['unit_price'] : null;
            $totalAmount = isset($itemInput['total_amount']) && $itemInput['total_amount'] !== '' ? (float) $itemInput['total_amount'] : null;
            if ($cartons !== null || $pieces !== null || $quantity !== null || $unitPrice !== null || $totalAmount !== null) {
                $splits[] = [
                    'cartons' => $cartons,
                    'pieces_per_carton' => $pieces,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_amount' => $totalAmount,
                ];
            }
        }

        foreach ($splits as &$split) {
            if (($split['quantity'] === null)
                && $split['cartons'] !== null && $split['pieces_per_carton'] !== null
                && $split['cartons'] > 0 && $split['pieces_per_carton'] > 0) {
                $split['quantity'] = round($split['cartons'] * $split['pieces_per_carton'], 4);
            }
            if (($split['total_amount'] === null)
                && $split['quantity'] !== null && $split['unit_price'] !== null
                && $split['quantity'] > 0 && $split['unit_price'] >= 0) {
                $split['total_amount'] = round($split['quantity'] * $split['unit_price'], 4);
            }
        }
        unset($split);

        return $splits;
    }

    private function aggregateReceiptPackagingSplits(array $splits): array
    {
        $cartons = 0.0;
        $quantity = 0.0;
        $amount = 0.0;
        $unitPrices = [];
        $piecesValues = [];
        foreach ($splits as $split) {
            if ($split['cartons'] !== null) $cartons += (float) $split['cartons'];
            if ($split['quantity'] !== null) $quantity += (float) $split['quantity'];
            if ($split['total_amount'] !== null) $amount += (float) $split['total_amount'];
            if ($split['unit_price'] !== null) $unitPrices[] = (float) $split['unit_price'];
            if ($split['pieces_per_carton'] !== null) $piecesValues[] = (float) $split['pieces_per_carton'];
        }
        $sameUnitPrice = count(array_unique(array_map(static fn($v) => (string) round($v, 4), $unitPrices))) === 1;
        $samePieces = count(array_unique(array_map(static fn($v) => (string) round($v, 4), $piecesValues))) === 1;
        return [
            'actual_cartons' => $cartons > 0 ? round($cartons, 4) : null,
            'actual_pieces_per_carton' => $samePieces && $piecesValues ? $piecesValues[0] : null,
            'actual_quantity' => $quantity > 0 ? round($quantity, 4) : null,
            'unit_price' => $sameUnitPrice && $unitPrices ? $unitPrices[0] : null,
            'total_amount' => $amount > 0 ? round($amount, 4) : null,
        ];
    }
}
