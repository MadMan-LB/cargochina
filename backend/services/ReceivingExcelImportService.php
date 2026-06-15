<?php

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

class ReceivingExcelImportService
{
    private const MAX_ROWS = 1000;

    private array $aliases = [
        'order_id' => ['orderid', 'order', 'orderno', 'ordernumber'],
        'order_item_id' => ['orderitemid', 'itemid', 'orderlineid', 'lineid'],
        'product_id' => ['productid', 'product', 'productref'],
        'shipping_code' => ['shippingcode', 'shipping', 'shipcode'],
        'item_no' => ['itemno', 'itemnumber', 'lineno'],
        'actual_cartons' => ['actualcartons', 'cartons', 'receivedcartons', 'cartonqty'],
        'actual_pieces_per_carton' => ['piecespercarton', 'pcspercarton', 'qtypercarton', 'actualpiecespercarton'],
        'actual_quantity' => ['actualquantity', 'quantity', 'qty', 'totalqty', 'totalquantity'],
        'unit_price' => ['unitprice', 'factoryprice', 'price'],
        'total_amount' => ['totalamount', 'amount', 'lineamount'],
        'actual_cbm' => ['actualcbm', 'cbm', 'receivedcbm'],
        'actual_weight' => ['actualweight', 'weight', 'kg', 'receivedweight'],
        'condition' => ['condition', 'receiptcondition'],
        'notes' => ['notes', 'note', 'remarks'],
    ];

    public function previewFromUploadedFile(PDO $pdo, array $file): array
    {
        $path = $this->validateUpload($file);
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);
        if (!$rows) {
            return $this->emptyPreview(['The Excel file is empty.']);
        }

        $headerRowNumber = null;
        $headers = [];
        foreach ($rows as $rowNumber => $row) {
            $values = array_map(static fn($value) => trim((string) ($value ?? '')), array_values($row));
            if (implode('', $values) === '') {
                continue;
            }
            $headerRowNumber = (int) $rowNumber;
            $headers = $this->mapHeaders($row);
            break;
        }
        if ($headerRowNumber === null) {
            return $this->emptyPreview(['The Excel file does not contain a header row.']);
        }

        $headerErrors = $this->validateHeaders($headers);
        $rawRows = [];
        foreach ($rows as $rowNumber => $row) {
            if ((int) $rowNumber <= $headerRowNumber) {
                continue;
            }
            $normalized = $this->normalizeSpreadsheetRow((int) $rowNumber, $row, $headers);
            if ($this->isBlankRow($normalized)) {
                continue;
            }
            $rawRows[] = $normalized;
            if (count($rawRows) > self::MAX_ROWS) {
                $headerErrors[] = 'Import limit exceeded. Use 1000 rows or fewer.';
                break;
            }
        }

        $preview = $this->validateRows($pdo, $rawRows);
        if ($headerErrors) {
            foreach ($preview['rows'] as &$row) {
                $row['errors'] = array_values(array_unique(array_merge($row['errors'], $headerErrors)));
                $row['valid'] = false;
            }
            unset($row);
            $preview['errors'] = array_values(array_unique(array_merge($preview['errors'], $headerErrors)));
            $preview['valid_count'] = count(array_filter($preview['rows'], static fn($row) => !empty($row['valid'])));
            $preview['error_count'] = count($preview['rows']) - $preview['valid_count'];
        }

        $preview['headers'] = array_keys($headers);
        $preview['raw_rows'] = $rawRows;
        $preview['source_filename'] = (string) ($file['name'] ?? '');
        $preview['file_hash'] = hash_file('sha256', $path) ?: '';
        return $preview;
    }

    public function validateRows(PDO $pdo, array $rawRows): array
    {
        $rows = [];
        $errors = [];
        $seenItems = [];
        $orderCache = [];
        $itemCache = [];
        $config = require dirname(__DIR__) . '/config/config.php';
        $thresholdPct = (float) ($config['variance_threshold_percent'] ?? 10);
        $thresholdAbs = (float) ($config['variance_threshold_abs_cbm'] ?? 0.1);

        foreach ($rawRows as $raw) {
            $rowErrors = [];
            $rowNumber = (int) ($raw['_row'] ?? 0);

            $orderId = $this->parseInteger($raw['order_id'] ?? null, 'Order ID', true, $rowErrors);
            $actualCartons = $this->parseNumber($raw['actual_cartons'] ?? null, 'Actual Cartons', true, $rowErrors);
            $actualPieces = $this->parseNumber($raw['actual_pieces_per_carton'] ?? null, 'Pieces / Carton', false, $rowErrors);
            $actualQuantity = $this->parseNumber($raw['actual_quantity'] ?? null, 'Quantity', false, $rowErrors);
            $unitPrice = $this->parseNumber($raw['unit_price'] ?? null, 'Unit Price', false, $rowErrors);
            $totalAmount = $this->parseNumber($raw['total_amount'] ?? null, 'Total Amount', false, $rowErrors);
            $actualCbm = $this->parseNumber($raw['actual_cbm'] ?? null, 'Actual CBM', true, $rowErrors);
            $actualWeight = $this->parseNumber($raw['actual_weight'] ?? null, 'Actual Weight', true, $rowErrors);
            $condition = strtolower(trim((string) ($raw['condition'] ?? 'good')));
            if ($condition === '') {
                $condition = 'good';
            }
            if (!in_array($condition, ['good', 'damaged', 'partial'], true)) {
                $rowErrors[] = 'Condition must be good, damaged, or partial.';
                $condition = 'good';
            }

            if ($actualCartons !== null && $actualCartons <= 0) {
                $rowErrors[] = 'Actual Cartons must be greater than zero.';
            }
            foreach ([
                'Pieces / Carton' => $actualPieces,
                'Quantity' => $actualQuantity,
                'Unit Price' => $unitPrice,
                'Total Amount' => $totalAmount,
                'Actual CBM' => $actualCbm,
                'Actual Weight' => $actualWeight,
            ] as $label => $value) {
                if ($value !== null && $value < 0) {
                    $rowErrors[] = $label . ' must be zero or positive.';
                }
            }

            $order = null;
            if ($orderId !== null) {
                $order = $this->loadOrder($pdo, $orderId, $orderCache);
                if (!$order) {
                    $rowErrors[] = 'Order #' . $orderId . ' was not found.';
                } elseif (!in_array((string) ($order['status'] ?? ''), ['Approved', 'InTransitToWarehouse'], true)) {
                    $rowErrors[] = 'Order #' . $orderId . ' is not available for warehouse receiving.';
                }
            }

            $resolvedItem = null;
            if ($orderId !== null && $order) {
                $items = $this->loadOrderItems($pdo, $orderId, $itemCache);
                $resolvedItem = $this->resolveOrderItem($pdo, $raw, $items, $rowErrors);
                if ($resolvedItem) {
                    $duplicateKey = $orderId . ':' . (int) $resolvedItem['id'];
                    if (isset($seenItems[$duplicateKey])) {
                        $rowErrors[] = 'Duplicate item in import. First seen on row ' . $seenItems[$duplicateKey] . '.';
                    } else {
                        $seenItems[$duplicateKey] = $rowNumber;
                    }
                    $declaredItemCbm = (float) ($resolvedItem['declared_cbm'] ?? 0);
                    $variancePct = $declaredItemCbm > 0 && $actualCbm !== null
                        ? abs($actualCbm - $declaredItemCbm) / $declaredItemCbm * 100
                        : 0;
                    $varianceAbs = $actualCbm !== null ? abs($actualCbm - $declaredItemCbm) : 0;
                    if ($condition !== 'good' || $variancePct >= $thresholdPct || $varianceAbs >= $thresholdAbs) {
                        $rowErrors[] = 'Evidence photos are required for damage or variance; receive this item manually.';
                    }
                }
            }

            if ($actualQuantity === null && $actualCartons !== null && $actualPieces !== null && $actualCartons > 0 && $actualPieces > 0) {
                $actualQuantity = round($actualCartons * $actualPieces, 4);
            }
            if ($totalAmount === null && $actualQuantity !== null && $unitPrice !== null && $actualQuantity > 0 && $unitPrice >= 0) {
                $totalAmount = round($actualQuantity * $unitPrice, 4);
            }

            $rows[] = [
                'row' => $rowNumber,
                'valid' => empty($rowErrors),
                'errors' => array_values(array_unique($rowErrors)),
                'order_id' => $orderId,
                'order_item_id' => $resolvedItem ? (int) $resolvedItem['id'] : null,
                'product_id' => $resolvedItem && !empty($resolvedItem['product_id']) ? (int) $resolvedItem['product_id'] : null,
                'shipping_code' => $resolvedItem['shipping_code'] ?? trim((string) ($raw['shipping_code'] ?? '')),
                'item_label' => $this->formatItemLabel($resolvedItem),
                'actual_cartons' => $actualCartons,
                'actual_pieces_per_carton' => $actualPieces,
                'actual_quantity' => $actualQuantity,
                'unit_price' => $unitPrice,
                'total_amount' => $totalAmount,
                'actual_cbm' => $actualCbm,
                'actual_weight' => $actualWeight,
                'condition' => $condition,
                'notes' => trim((string) ($raw['notes'] ?? '')),
            ];
        }

        if (!$rows) {
            $errors[] = 'No import rows found.';
        }

        $grouped = $this->buildPayloadsFromRows($rows);
        foreach ($grouped as $orderId => $payload) {
            if (($payload['actual_cbm'] ?? 0) <= 0) {
                $this->markOrderRowsInvalid($rows, (int) $orderId, 'Order total actual CBM must be greater than zero.');
            }
        }

        $validCount = count(array_filter($rows, static fn($row) => !empty($row['valid'])));
        $errorCount = count($rows) - $validCount;
        if ($errorCount > 0) {
            $errors[] = 'Fix row-level errors before importing.';
        }

        return [
            'rows' => $rows,
            'orders' => array_values($this->summarizeOrders($rows)),
            'payloads' => $this->buildPayloadsFromRows($rows),
            'row_count' => count($rows),
            'valid_count' => $validCount,
            'error_count' => $errorCount,
            'errors' => array_values(array_unique($errors)),
            'is_valid' => $errorCount === 0 && empty($errors),
        ];
    }

    public function buildPayloadsFromRows(array $rows): array
    {
        $payloads = [];
        foreach ($rows as $row) {
            if (empty($row['valid']) || empty($row['order_id']) || empty($row['order_item_id'])) {
                continue;
            }
            $orderId = (int) $row['order_id'];
            if (!isset($payloads[$orderId])) {
                $payloads[$orderId] = [
                    'actual_cartons' => 0,
                    'actual_cbm' => 0,
                    'actual_weight' => 0,
                    'condition' => 'good',
                    'notes' => 'Excel receiving import',
                    'photo_paths' => [],
                    'items' => [],
                ];
            }
            $payloads[$orderId]['actual_cartons'] += (int) round((float) ($row['actual_cartons'] ?? 0));
            $payloads[$orderId]['actual_cbm'] += (float) ($row['actual_cbm'] ?? 0);
            $payloads[$orderId]['actual_weight'] += (float) ($row['actual_weight'] ?? 0);
            if (($row['condition'] ?? 'good') === 'damaged') {
                $payloads[$orderId]['condition'] = 'damaged';
            } elseif (($row['condition'] ?? 'good') === 'partial' && $payloads[$orderId]['condition'] === 'good') {
                $payloads[$orderId]['condition'] = 'partial';
            }

            $payloads[$orderId]['items'][] = [
                'order_item_id' => (int) $row['order_item_id'],
                'actual_cartons' => (int) round((float) ($row['actual_cartons'] ?? 0)),
                'actual_pieces_per_carton' => $row['actual_pieces_per_carton'],
                'actual_quantity' => $row['actual_quantity'],
                'unit_price' => $row['unit_price'],
                'total_amount' => $row['total_amount'],
                'actual_cbm' => $row['actual_cbm'],
                'actual_weight' => $row['actual_weight'],
                'condition' => $row['condition'] ?: 'good',
                'notes' => $row['notes'] ?: null,
                'photo_paths' => [],
                'packaging_splits' => [[
                    'cartons' => $row['actual_cartons'],
                    'pieces_per_carton' => $row['actual_pieces_per_carton'],
                    'quantity' => $row['actual_quantity'],
                    'unit_price' => $row['unit_price'],
                    'total_amount' => $row['total_amount'],
                ]],
            ];
        }

        foreach ($payloads as &$payload) {
            $payload['actual_cbm'] = round((float) $payload['actual_cbm'], 6);
            $payload['actual_weight'] = round((float) $payload['actual_weight'], 4);
        }
        unset($payload);

        return $payloads;
    }

    private function validateUpload(array $file): string
    {
        if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('No Excel file uploaded.');
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls'], true)) {
            throw new InvalidArgumentException('Upload an .xlsx or .xls file.');
        }
        $config = require dirname(__DIR__) . '/config/config.php';
        $maxSize = (int) ($config['upload_max_size'] ?? 8388608);
        if (!empty($file['size']) && (int) $file['size'] > $maxSize) {
            throw new InvalidArgumentException('Excel file is too large.');
        }
        $path = (string) ($file['tmp_name'] ?? '');
        if ($path === '' || !is_file($path)) {
            throw new InvalidArgumentException('Uploaded file is not readable.');
        }
        return $path;
    }

    private function emptyPreview(array $errors): array
    {
        return [
            'rows' => [],
            'orders' => [],
            'payloads' => [],
            'row_count' => 0,
            'valid_count' => 0,
            'error_count' => 0,
            'errors' => $errors,
            'is_valid' => false,
        ];
    }

    private function normalizeHeaderKey($value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(trim((string) ($value ?? '')))) ?: '';
    }

    private function mapHeaders(array $row): array
    {
        $mapped = [];
        foreach ($row as $column => $label) {
            $normalized = $this->normalizeHeaderKey($label);
            if ($normalized === '') {
                continue;
            }
            foreach ($this->aliases as $field => $aliases) {
                if ($normalized === $field || in_array($normalized, $aliases, true)) {
                    $mapped[$field] = $column;
                    break;
                }
            }
        }
        return $mapped;
    }

    private function validateHeaders(array $headers): array
    {
        $errors = [];
        if (!isset($headers['order_id'])) {
            $errors[] = 'Missing required column: Order ID.';
        }
        if (!isset($headers['order_item_id']) && !isset($headers['product_id']) && !isset($headers['shipping_code']) && !isset($headers['item_no'])) {
            $errors[] = 'Missing product reference column: Order Item ID, Product ID, Shipping Code, or Item No.';
        }
        foreach (['actual_cartons', 'actual_cbm', 'actual_weight'] as $field) {
            if (!isset($headers[$field])) {
                $errors[] = 'Missing required column: ' . $field . '.';
            }
        }
        return $errors;
    }

    private function normalizeSpreadsheetRow(int $rowNumber, array $row, array $headers): array
    {
        $normalized = ['_row' => $rowNumber];
        foreach ($this->aliases as $field => $_) {
            $column = $headers[$field] ?? null;
            $normalized[$field] = $column !== null ? trim((string) ($row[$column] ?? '')) : '';
        }
        return $normalized;
    }

    private function isBlankRow(array $row): bool
    {
        foreach ($this->aliases as $field => $_) {
            if (trim((string) ($row[$field] ?? '')) !== '') {
                return false;
            }
        }
        return true;
    }

    private function parseInteger($value, string $label, bool $required, array &$errors): ?int
    {
        $number = $this->parseNumber($value, $label, $required, $errors);
        if ($number === null) {
            return null;
        }
        if (floor($number) != $number) {
            $errors[] = $label . ' must be a whole number.';
            return null;
        }
        return (int) $number;
    }

    private function parseNumber($value, string $label, bool $required, array &$errors): ?float
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            if ($required) {
                $errors[] = 'Missing required value: ' . $label . '.';
            }
            return null;
        }
        $normalized = str_replace([',', ' '], '', $raw);
        if (!is_numeric($normalized)) {
            $errors[] = $label . ' has an invalid number format.';
            return null;
        }
        return (float) $normalized;
    }

    private function loadOrder(PDO $pdo, int $orderId, array &$cache): ?array
    {
        if (!array_key_exists($orderId, $cache)) {
            $stmt = $pdo->prepare("SELECT id, status FROM orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $cache[$orderId] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        return $cache[$orderId];
    }

    private function loadOrderItems(PDO $pdo, int $orderId, array &$cache): array
    {
        if (!array_key_exists($orderId, $cache)) {
            $extraCols = '';
            foreach (['code', 'express_number', 'size'] as $column) {
                try {
                    $chk = $pdo->prepare("SHOW COLUMNS FROM order_items LIKE ?");
                    $chk->execute([$column]);
                    if ($chk->rowCount() > 0) {
                        $extraCols .= ", oi.$column";
                    }
                } catch (Throwable $e) {
                }
            }
            $stmt = $pdo->prepare("SELECT oi.id, oi.product_id, oi.item_no, oi.shipping_code, oi.cartons, oi.qty_per_carton, oi.quantity, oi.unit_price, oi.total_amount, oi.declared_cbm, oi.declared_weight, oi.description_cn, oi.description_en$extraCols FROM order_items oi WHERE oi.order_id = ?");
            $stmt->execute([$orderId]);
            $cache[$orderId] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $cache[$orderId];
    }

    private function resolveOrderItem(PDO $pdo, array $raw, array $items, array &$errors): ?array
    {
        $orderItemIdRaw = trim((string) ($raw['order_item_id'] ?? ''));
        $productIdRaw = trim((string) ($raw['product_id'] ?? ''));
        $shippingCode = trim((string) ($raw['shipping_code'] ?? ''));
        $itemNo = trim((string) ($raw['item_no'] ?? ''));

        if ($orderItemIdRaw === '' && $productIdRaw === '' && $shippingCode === '' && $itemNo === '') {
            $errors[] = 'Missing product/order item reference.';
            return null;
        }

        if ($orderItemIdRaw !== '') {
            if (!ctype_digit($orderItemIdRaw)) {
                $errors[] = 'Order Item ID must be a whole number.';
                return null;
            }
            $matches = array_values(array_filter($items, static fn($item) => (int) $item['id'] === (int) $orderItemIdRaw));
            if (!$matches) {
                $errors[] = 'Order Item #' . $orderItemIdRaw . ' is not available on this order.';
                return null;
            }
            return $matches[0];
        }

        if ($productIdRaw !== '') {
            if (!ctype_digit($productIdRaw)) {
                $errors[] = 'Product ID must be a whole number.';
                return null;
            }
            $productId = (int) $productIdRaw;
            $matches = array_values(array_filter($items, static fn($item) => (int) ($item['product_id'] ?? 0) === $productId));
            if (count($matches) === 1) {
                return $matches[0];
            }
            if (count($matches) > 1) {
                $errors[] = 'Product #' . $productId . ' appears more than once on this order. Use Order Item ID.';
                return null;
            }
            $exists = $pdo->prepare("SELECT 1 FROM products WHERE id = ? LIMIT 1");
            $exists->execute([$productId]);
            $errors[] = $exists->fetchColumn()
                ? 'Product #' . $productId . ' is not on this order.'
                : 'Product #' . $productId . ' was not found.';
            return null;
        }

        if ($shippingCode !== '') {
            $matches = array_values(array_filter($items, static fn($item) => strcasecmp(trim((string) ($item['shipping_code'] ?? '')), $shippingCode) === 0));
            if (count($matches) === 1) {
                return $matches[0];
            }
            $errors[] = count($matches) > 1
                ? 'Shipping Code "' . $shippingCode . '" matches multiple items. Use Order Item ID.'
                : 'Shipping Code "' . $shippingCode . '" was not found on this order.';
            return null;
        }

        if ($itemNo !== '') {
            $matches = array_values(array_filter($items, static fn($item) => strcasecmp(trim((string) ($item['item_no'] ?? '')), $itemNo) === 0));
            if (count($matches) === 1) {
                return $matches[0];
            }
            $errors[] = count($matches) > 1
                ? 'Item No "' . $itemNo . '" matches multiple items. Use Order Item ID.'
                : 'Item No "' . $itemNo . '" was not found on this order.';
        }

        return null;
    }

    private function formatItemLabel(?array $item): string
    {
        if (!$item) {
            return '';
        }
        $desc = trim((string) ($item['description_en'] ?: $item['description_cn'] ?: ''));
        $code = trim((string) ($item['shipping_code'] ?? ''));
        return trim('#' . (int) $item['id'] . ' ' . ($code ? $code . ' ' : '') . $desc);
    }

    private function markOrderRowsInvalid(array &$rows, int $orderId, string $message): void
    {
        foreach ($rows as &$row) {
            if ((int) ($row['order_id'] ?? 0) === $orderId) {
                $row['valid'] = false;
                $row['errors'][] = $message;
                $row['errors'] = array_values(array_unique($row['errors']));
            }
        }
        unset($row);
    }

    private function summarizeOrders(array $rows): array
    {
        $summary = [];
        foreach ($rows as $row) {
            if (empty($row['order_id'])) {
                continue;
            }
            $orderId = (int) $row['order_id'];
            if (!isset($summary[$orderId])) {
                $summary[$orderId] = [
                    'order_id' => $orderId,
                    'rows' => 0,
                    'valid_rows' => 0,
                    'actual_cartons' => 0,
                    'actual_cbm' => 0,
                    'actual_weight' => 0,
                ];
            }
            $summary[$orderId]['rows']++;
            if (!empty($row['valid'])) {
                $summary[$orderId]['valid_rows']++;
                $summary[$orderId]['actual_cartons'] += (int) round((float) ($row['actual_cartons'] ?? 0));
                $summary[$orderId]['actual_cbm'] += (float) ($row['actual_cbm'] ?? 0);
                $summary[$orderId]['actual_weight'] += (float) ($row['actual_weight'] ?? 0);
            }
        }

        foreach ($summary as &$row) {
            $row['actual_cbm'] = round((float) $row['actual_cbm'], 6);
            $row['actual_weight'] = round((float) $row['actual_weight'], 4);
        }
        unset($row);

        return $summary;
    }
}
