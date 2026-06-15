<?php

require_once __DIR__ . '/NotificationService.php';

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

        $actualCartons = (int) ($input['actual_cartons'] ?? 0);
        $actualCbm = (float) ($input['actual_cbm'] ?? 0);
        $actualWeight = (float) ($input['actual_weight'] ?? 0);
        if ($actualCartons < 0 || $actualCbm < 0 || $actualWeight < 0) {
            throw new OrderReceivingValidationException('Actual cartons, CBM, and weight must be zero or positive', 400);
        }

        $condition = $input['condition'] ?? 'good';
        if (!in_array($condition, ['good', 'damaged', 'partial'], true)) {
            $condition = 'good';
        }

        $photoPaths = $this->normalizeStoredUploadPathList($input['photo_paths'] ?? []);
        $itemsInput = is_array($input['items'] ?? null) ? $input['items'] : [];
        $config = require dirname(__DIR__) . '/config/config.php';
        $thresholdPct = $config['variance_threshold_percent'] ?? 10;
        $thresholdAbs = $config['variance_threshold_abs_cbm'] ?? 0.1;
        $photoEvidencePerItem = (int) ($config['photo_evidence_per_item'] ?? 0);
        $itemLevelEnabled = (int) ($config['item_level_receiving_enabled'] ?? 0);

        $orderItems = $pdo->prepare("SELECT id, declared_cbm, declared_weight FROM order_items WHERE order_id = ?");
        $orderItems->execute([$orderId]);
        $orderItemsRows = $orderItems->fetchAll(PDO::FETCH_ASSOC);
        $declaredCbm = array_sum(array_column($orderItemsRows, 'declared_cbm'));

        $orderVariancePct = $declaredCbm > 0 ? abs($actualCbm - $declaredCbm) / $declaredCbm * 100 : 0;
        $orderVarianceAbs = abs($actualCbm - $declaredCbm);
        $hasVariance = $orderVariancePct >= $thresholdPct || $orderVarianceAbs >= $thresholdAbs || $condition !== 'good';
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
                $aCartons = isset($it['actual_cartons']) ? (int) $it['actual_cartons'] : null;
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

                if (($aQuantity === null || $aQuantity <= 0) && $aCartons !== null && $aPiecesPerCarton !== null && $aCartons > 0 && $aPiecesPerCarton > 0) {
                    $aQuantity = round($aCartons * $aPiecesPerCarton, 4);
                }
                if (($aTotalAmount === null || $aTotalAmount <= 0) && $aQuantity !== null && $aUnitPrice !== null && $aQuantity > 0 && $aUnitPrice >= 0) {
                    $aTotalAmount = round($aQuantity * $aUnitPrice, 4);
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
                    || ($aWeight !== null && $aWeight < 0)) {
                    $errors["items.$idx.actuals"] = 'Actual cartons, CBM, and weight must be zero or positive';
                }

                $itCond = $it['condition'] ?? 'good';
                if (!in_array($itCond, ['good', 'damaged', 'partial'], true)) {
                    $itCond = 'good';
                }
                $itPhotos = $this->normalizeStoredUploadPathList($it['photo_paths'] ?? []);
                $decCbm = (float) $oi['declared_cbm'];
                $itemVar = $itCond !== 'good';
                if ($aCbm !== null) {
                    $varPct = $decCbm > 0 ? abs($aCbm - $decCbm) / $decCbm * 100 : 0;
                    $varAbs = abs($aCbm - $decCbm);
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

            $tolerance = 0.01;
            if (($hasItemCbm && abs($sumCbm - $actualCbm) > $tolerance)
                || ($hasItemWeight && abs($sumWeight - $actualWeight) > $tolerance)) {
                throw new OrderReceivingValidationException('Item-level totals must match order-level actuals (CBM/weight)', 400);
            }
        }

        if ($hasVariance && empty($photoPaths)) {
            throw new OrderReceivingValidationException('Evidence photos required when variance or damage is present', 400);
        }
        if ($itemLevelEnabled && empty($itemsInput)) {
            throw new OrderReceivingValidationException('Item-level receiving is required; provide items array', 400);
        }

        $startedTransaction = false;
        if ($manageTransaction && !$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        try {
            $pdo->prepare("INSERT INTO warehouse_receipts (order_id, actual_cartons, actual_cbm, actual_weight, receipt_condition, notes, received_by) VALUES (?,?,?,?,?,?,?)")
                ->execute([$orderId, $actualCartons, $actualCbm, $actualWeight, $condition, $input['notes'] ?? null, $userId]);
            $receiptId = (int) $pdo->lastInsertId();

            $insPhoto = $pdo->prepare("INSERT INTO warehouse_receipt_photos (receipt_id, file_path) VALUES (?,?)");
            foreach ($photoPaths as $path) {
                $insPhoto->execute([$receiptId, $path]);
            }

            if (!empty($itemsInput)) {
                $receiptItemCols = "receipt_id, order_item_id, actual_cartons, actual_cbm, actual_weight, receipt_condition, variance_detected, notes";
                $receiptItemVals = "?,?,?,?,?,?,?,?";
                $receiptExtraCols = [];
                foreach (['actual_pieces_per_carton', 'actual_quantity', 'unit_price', 'total_amount'] as $column) {
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
                    $aCartons = isset($it['actual_cartons']) ? (int) $it['actual_cartons'] : null;
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

                    if (($aQuantity === null || $aQuantity <= 0) && $aCartons !== null && $aPiecesPerCarton !== null && $aCartons > 0 && $aPiecesPerCarton > 0) {
                        $aQuantity = round($aCartons * $aPiecesPerCarton, 4);
                    }
                    if (($aTotalAmount === null || $aTotalAmount <= 0) && $aQuantity !== null && $aUnitPrice !== null && $aQuantity > 0 && $aUnitPrice >= 0) {
                        $aTotalAmount = round($aQuantity * $aUnitPrice, 4);
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

            $newStatus = $hasVariance ? 'Confirmed' : 'ReadyForConsolidation';
            $confirmToken = null;
            if ($hasVariance) {
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
                    'source' => $options['source'] ?? 'manual',
                    'import_id' => $options['import_id'] ?? null,
                ]);
            }
            (new NotificationService($pdo))->notifyOrderReceived($orderId, $userId, $hasVariance, $confirmToken);

            if ($startedTransaction) {
                $pdo->commit();
            }

            return [
                'status' => $newStatus,
                'receipt_id' => $receiptId,
                'variance_detected' => $hasVariance,
            ];
        } catch (Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function normalizeStoredUploadPathList(array $paths): array
    {
        if (function_exists('normalizeStoredUploadPathList')) {
            return normalizeStoredUploadPathList($paths);
        }

        return array_values(array_unique(array_filter(array_map('strval', $paths))));
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

            if (($quantity === null || $quantity <= 0) && $cartons !== null && $pieces !== null && $cartons > 0 && $pieces > 0) {
                $quantity = round($cartons * $pieces, 4);
            }
            if (($totalAmount === null || $totalAmount <= 0) && $quantity !== null && $unitPrice !== null && $quantity > 0 && $unitPrice >= 0) {
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
            if (($split['quantity'] === null || $split['quantity'] <= 0)
                && $split['cartons'] !== null && $split['pieces_per_carton'] !== null
                && $split['cartons'] > 0 && $split['pieces_per_carton'] > 0) {
                $split['quantity'] = round($split['cartons'] * $split['pieces_per_carton'], 4);
            }
            if (($split['total_amount'] === null || $split['total_amount'] <= 0)
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
