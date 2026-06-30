<?php

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

class ReceivingExcelImportService
{
    private const MAX_ROWS = 1000;
    private const READ_LIMIT_EXTRA_ROWS = 25;
    private const DIRECT_INTAKE_CUSTOMER_CODE = 'DIRECT-WAREHOUSE-INTAKE';
    private const DIRECT_INTAKE_CUSTOMER_NAME = 'Direct Warehouse Intake';

    private array $aliases = [
        'order_id' => ['orderid', 'order', 'orderno', 'ordernumber', 'orderref', 'orderreference'],
        'order_item_id' => ['orderitemid', 'itemid', 'orderlineid', 'lineid', 'receiptitemid', 'itemrowid'],
        'product_id' => ['productid', 'product', 'productref', 'productreference'],
        'customer_id' => ['customerid', 'customerref', 'customerreference'],
        'item_code' => ['sku', 'itemcode', 'skuitemcode', 'code', 'productcode', 'productsku'],
        'express_number' => ['expressnumber', 'expressno', 'express', 'trackingnumber', 'couriernumber', 'waybill', 'waybillnumber'],
        'shipping_code' => ['shippingcode', 'shipping', 'shipcode', 'trackingcode', 'customerreference'],
        'item_no' => ['itemno', 'itemnumber', 'lineno', 'linenumber', 'no'],
        'description' => ['description', 'productnames', 'productname', 'product', 'names', 'notesdescription'],
        'description_en' => ['englishitemname', 'englishname', 'descriptionen', 'englishdescription'],
        'description_cn' => ['chineseitemname', 'chinesename', 'descriptioncn', 'chinesedescription'],
        'brand' => ['brand', 'brandname', 'whatbrand'],
        'materials' => ['material', 'materials'],
        'height' => ['height', 'h'],
        'width' => ['width', 'w'],
        'length' => ['length', 'lenght', 'l'],
        'hs_code' => ['hscode', 'hs', 'tariffcode'],
        'supplier_id' => ['supplierid'],
        'supplier_code' => ['supplier', 'suppliercode', 'supplierno'],
        'supplier_name' => ['suppliername', 'factoryname', 'factory', 'vendorname'],
        'customer_name' => ['customer', 'customername'],
        'actual_cartons' => ['actualcartons', 'cartons', 'carton', 'ctn', 'ctns', 'receivedcartons', 'cartonqty', 'totalcartons'],
        'actual_pieces_per_carton' => ['piecespercarton', 'piecescarton', 'pcspercarton', 'pcscarton', 'qtypercarton', 'actualpiecespercarton', 'actualpcscarton'],
        'actual_quantity' => ['actualquantity', 'quantity', 'qty', 'totalqty', 'totalquantity', 'receivedquantity', 'receivedqty'],
        'factory_price' => ['factoryprice', 'buyprice', 'cost', 'supplierprice'],
        'customer_price' => ['customerprice', 'sellprice', 'sellingprice'],
        'unit_price' => ['unitprice', 'price', 'actualunitprice', 'receivedunitprice'],
        'total_amount' => ['totalamount', 'amount', 'lineamount', 'actualamount', 'receivedamount'],
        'actual_cbm' => ['actualcbm', 'cbm', 'm3', 'volume', 'receivedcbm', 'totalcbm'],
        'cbm_unit' => ['cbmunit', 'cbmperunit', 'cbmpercarton', 'cbmcarton', 'unitcbm'],
        'actual_weight' => ['actualweight', 'weight', 'kg', 'grossweight', 'receivedweight', 'totalweight', 'declaredweightkg'],
        'weight_unit' => ['weightunit', 'weightperunit', 'weightpercarton', 'weightcarton', 'unitweight', 'kgunit'],
        'condition' => ['condition', 'receiptcondition', 'status'],
        'notes' => ['notes', 'note', 'remarks', 'remark', 'receivingnotes'],
    ];

    public function previewFromUploadedFile(PDO $pdo, array $file, array $overrides = []): array
    {
        $startedAt = microtime(true);
        $path = $this->validateUpload($file);
        [$rows, $readerMode] = $this->readRowsFromFile($path, (string) ($file['name'] ?? ''));
        if (!$rows) {
            return $this->emptyPreview(['The Excel file is empty.']);
        }

        $headerRowNumber = null;
        $headers = [];
        $metadata = [];
        foreach ($rows as $rowNumber => $row) {
            $values = array_map(static fn($value) => trim((string) ($value ?? '')), array_values($row));
            if (implode('', $values) === '') {
                continue;
            }
            $this->captureTemplateMetadata($row, $metadata);
            $candidateHeaders = $this->mapHeaders($row);
            if ($this->looksLikeImportHeader($candidateHeaders)) {
                $headerRowNumber = (int) $rowNumber;
                $headers = $candidateHeaders;
                break;
            }
        }
        if ($headerRowNumber === null) {
            return $this->emptyPreview(['The Excel file does not contain a supported receiving/procurement template header row. Use the Download import template button and keep the blue header row.']);
        }

        $metadata = $this->applyPreviewOverrides($metadata, $overrides);
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
            $this->applyDerivedTemplateValues($normalized);
            $rawRows[] = $normalized;
            if (count($rawRows) > self::MAX_ROWS) {
                $headerErrors[] = 'Import limit exceeded. Use 1000 rows or fewer.';
                break;
            }
        }

        $isDirectIntake = !$this->hasExplicitOrderReference($rawRows);
        $preview = $isDirectIntake
            ? $this->validateDirectIntakeRows($pdo, $rawRows, $metadata)
            : $this->validateRows($pdo, $rawRows);
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
        $preview['header_row'] = $headerRowNumber;
        $preview['mode'] = $isDirectIntake ? 'direct_intake' : 'existing_order_receipt';
        $preview['template_metadata'] = $metadata;
        $preview['reader_mode'] = $readerMode;
        $preview['missing_optional_columns'] = $this->missingOptionalColumns($headers);
        $preview['raw_rows'] = $rawRows;
        $preview['source_filename'] = (string) ($file['name'] ?? '');
        $preview['file_hash'] = hash_file('sha256', $path) ?: '';
        $preview['total_seconds'] = round(microtime(true) - $startedAt, 3);
        return $preview;
    }

    public function validateRows(PDO $pdo, array $rawRows): array
    {
        $rows = [];
        $errors = [];
        $seenItems = [];
        $orderCache = $this->loadOrdersForRows($pdo, $rawRows);
        $itemCache = $this->loadOrderItemsForRows($pdo, array_keys($orderCache));
        $productExistsCache = $this->loadProductExistsForRows($pdo, $rawRows);
        $config = require dirname(__DIR__) . '/config/config.php';
        $thresholdPct = (float) ($config['variance_threshold_percent'] ?? 10);
        $thresholdAbs = (float) ($config['variance_threshold_abs_cbm'] ?? 0.1);

        foreach ($rawRows as $raw) {
            $rowErrors = [];
            $rowNumber = (int) ($raw['_row'] ?? 0);

            if (!empty($raw['_order_match_error'])) {
                $rowErrors[] = (string) $raw['_order_match_error'];
            }
            $orderId = $this->parseInteger($raw['order_id'] ?? null, 'Order ID', false, $rowErrors);
            if ($orderId === null) {
                $rowErrors[] = 'Could not match this procurement-template row to a receiving order. Add Order ID or Order Item ID, or make sure Customer/Supplier/Item fields uniquely match one approved/in-transit order.';
            }
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
            if ($condition !== 'good') {
                $rowErrors[] = 'Damaged or partial direct receiving rows require photo evidence; receive this packet manually.';
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
                $order = $orderCache[$orderId] ?? null;
                if (!$order) {
                    $rowErrors[] = 'Order #' . $orderId . ' was not found.';
                } elseif (!in_array((string) ($order['status'] ?? ''), ['Approved', 'InTransitToWarehouse'], true)) {
                    $rowErrors[] = 'Order #' . $orderId . ' is not available for warehouse receiving.';
                }
            }

            $resolvedItem = null;
            if ($orderId !== null && $order) {
                $items = $itemCache[$orderId] ?? [];
                $resolvedItem = $this->resolveOrderItem($raw, $items, $productExistsCache, $rowErrors);
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

    public function validateDirectIntakeRows(PDO $pdo, array $rawRows, array $metadata = []): array
    {
        $rows = [];
        $errors = [];
        $customerCache = [];
        $supplierCache = [];
        $seenRows = [];

        foreach ($rawRows as $raw) {
            $rowErrors = [];
            $rowWarnings = [];
            $rowNumber = (int) ($raw['_row'] ?? 0);

            $customer = $this->resolveDirectCustomer($pdo, $raw, $metadata, $customerCache, $rowErrors);
            if (!empty($customer['_fallback'])) {
                $rowWarnings[] = 'No customer supplied; this direct receipt will be assigned to ' . self::DIRECT_INTAKE_CUSTOMER_NAME . '.';
            }
            $supplier = $this->resolveDirectSupplierPreview($pdo, $raw, $supplierCache);
            $supplierLabel = trim((string) (($raw['supplier_name'] ?? '') ?: ($raw['supplier_code'] ?? '') ?: ($raw['supplier_id'] ?? '')));
            if (!$supplier && $supplierLabel !== '') {
                $rowWarnings[] = 'Supplier "' . $supplierLabel . '" will be created during import.';
            }

            $actualCartons = $this->parseNumber($raw['actual_cartons'] ?? null, 'Cartons', true, $rowErrors);
            $actualPieces = $this->parseNumber($raw['actual_pieces_per_carton'] ?? null, 'Pieces / Carton', false, $rowErrors);
            $actualQuantity = $this->parseNumber($raw['actual_quantity'] ?? null, 'Quantity', false, $rowErrors);
            $factoryPrice = $this->parseNumber(($raw['factory_price'] ?? '') !== '' ? $raw['factory_price'] : ($raw['unit_price'] ?? null), 'Factory Price', false, $rowErrors);
            $customerPrice = $this->parseNumber($raw['customer_price'] ?? null, 'Customer Price', false, $rowErrors);
            $totalAmount = $this->parseNumber($raw['total_amount'] ?? null, 'Total Amount', false, $rowErrors);
            $actualCbm = $this->parseNumber($raw['actual_cbm'] ?? null, 'Total CBM', true, $rowErrors);
            $actualWeight = $this->parseNumber($raw['actual_weight'] ?? null, 'Total Weight', true, $rowErrors);
            $height = $this->parseNumber($raw['height'] ?? null, 'Height', false, $rowErrors);
            $width = $this->parseNumber($raw['width'] ?? null, 'Width', false, $rowErrors);
            $length = $this->parseNumber($raw['length'] ?? null, 'Length', false, $rowErrors);
            $condition = strtolower(trim((string) ($raw['condition'] ?? 'good')));
            if ($condition === '') {
                $condition = 'good';
            }
            if (!in_array($condition, ['good', 'damaged', 'partial'], true)) {
                $rowErrors[] = 'Condition must be good, damaged, or partial.';
                $condition = 'good';
            }

            if ($actualQuantity === null && $actualCartons !== null && $actualPieces !== null && $actualCartons > 0 && $actualPieces > 0) {
                $actualQuantity = round($actualCartons * $actualPieces, 4);
            }
            $unit = $this->normalizeDirectUnit($raw['unit'] ?? '', $actualQuantity, $actualCartons);
            if ($actualQuantity === null && $actualCartons !== null && $actualCartons > 0) {
                $actualQuantity = $actualCartons;
                $unit = 'cartons';
            }
            if ($totalAmount === null && $actualQuantity !== null) {
                $priceForTotal = $factoryPrice ?? $customerPrice;
                if ($priceForTotal !== null && $actualQuantity > 0) {
                    $totalAmount = round($actualQuantity * $priceForTotal, 4);
                }
            }

            $descriptionEn = $this->cleanDirectText(($raw['description_en'] ?? '') ?: ($raw['description'] ?? ''), 500);
            $descriptionCn = $this->cleanDirectText($raw['description_cn'] ?? '', 500);
            $itemCode = $this->cleanDirectText($raw['item_code'] ?? '', 100);
            $itemNo = $this->cleanDirectText($raw['item_no'] ?? '', 100);
            if ($descriptionEn === null && $descriptionCn === null && $itemCode !== null) {
                $descriptionEn = $itemCode;
            }
            if ($descriptionEn === null && $descriptionCn === null && $itemNo === null && $itemCode === null) {
                $rowErrors[] = 'Missing item description/name or SKU / Item Code.';
            }

            foreach ([
                'Cartons' => $actualCartons,
                'Pieces / Carton' => $actualPieces,
                'Quantity' => $actualQuantity,
                'Factory Price' => $factoryPrice,
                'Customer Price' => $customerPrice,
                'Total Amount' => $totalAmount,
                'Total CBM' => $actualCbm,
                'Total Weight' => $actualWeight,
                'Height' => $height,
                'Width' => $width,
                'Length' => $length,
            ] as $label => $value) {
                if ($value !== null && $value < 0) {
                    $rowErrors[] = $label . ' must be zero or positive.';
                }
            }
            if ($actualCartons !== null && $actualCartons <= 0) {
                $rowErrors[] = 'Cartons must be greater than zero.';
            }
            if ($actualCbm !== null && $actualCbm <= 0) {
                $rowErrors[] = 'Total CBM must be greater than zero.';
            }
            if ($actualWeight !== null && $actualWeight <= 0) {
                $rowErrors[] = 'Total Weight must be greater than zero.';
            }
            if ($actualQuantity !== null && $actualQuantity <= 0) {
                $rowErrors[] = 'Quantity must be greater than zero.';
            }

            $customerId = (int) ($customer['id'] ?? 0);
            $duplicateKey = implode('|', [
                $customerId,
                (int) ($supplier['id'] ?? 0),
                $this->normalizeMatchText($supplierLabel),
                $this->normalizeMatchText($itemCode ?? ''),
                $this->normalizeMatchText($itemNo ?? ''),
                $this->normalizeMatchText($descriptionEn ?? $descriptionCn ?? ''),
                (string) $actualCartons,
                (string) $actualCbm,
                (string) $actualWeight,
            ]);
            if ($duplicateKey !== '0|||||||' && isset($seenRows[$duplicateKey])) {
                $rowWarnings[] = 'Possible duplicate of row ' . $seenRows[$duplicateKey] . '.';
            } else {
                $seenRows[$duplicateKey] = $rowNumber;
            }

            $itemLabel = trim(($itemCode ? $itemCode . ' ' : '') . ($descriptionEn ?: $descriptionCn ?: $itemNo ?: 'New item'));
            $directItem = [
                'customer_id' => $customerId ?: null,
                'customer_name' => $customer['name'] ?? trim((string) (($raw['customer_name'] ?? '') ?: ($metadata['customer_name'] ?? ''))),
                'customer_fallback' => !empty($customer['_fallback']),
                'supplier_id' => $supplier['id'] ?? null,
                'supplier_code' => $this->cleanDirectText($raw['supplier_code'] ?? '', 100),
                'supplier_name' => $this->cleanDirectText(($raw['supplier_name'] ?? '') ?: ($raw['supplier_code'] ?? ''), 255),
                'expected_ready_date' => $this->normalizeDateValue((string) ($metadata['expected_ready_date'] ?? '')) ?: date('Y-m-d'),
                'currency' => strtoupper(trim((string) ($metadata['currency'] ?? 'RMB'))) ?: 'RMB',
                'item_no' => $itemNo,
                'shipping_code' => $this->cleanDirectText($raw['shipping_code'] ?? '', 150),
                'code' => $itemCode,
                'express_number' => $this->cleanDirectText($raw['express_number'] ?? '', 150),
                'brand' => $this->cleanDirectText($raw['brand'] ?? '', 150),
                'materials' => $this->cleanDirectText($raw['materials'] ?? '', 1000),
                'height' => $height,
                'width' => $width,
                'length' => $length,
                'hs_code' => $this->cleanDirectText($raw['hs_code'] ?? '', 40),
                'cartons' => $actualCartons,
                'pieces_per_carton' => $actualPieces,
                'quantity' => $actualQuantity,
                'unit' => $unit,
                'unit_price' => $factoryPrice,
                'sell_price' => $customerPrice,
                'total_amount' => $totalAmount,
                'declared_cbm' => $actualCbm,
                'declared_weight' => $actualWeight,
                'description_en' => $descriptionEn,
                'description_cn' => $descriptionCn,
                'notes' => $this->cleanDirectText($raw['notes'] ?? '', 1000),
                'condition' => $condition,
            ];

            $rows[] = [
                'row' => $rowNumber,
                'mode' => 'direct_intake',
                'valid' => empty($rowErrors),
                'errors' => array_values(array_unique($rowErrors)),
                'warnings' => array_values(array_unique($rowWarnings)),
                'order_id' => null,
                'order_label' => 'New direct receipt',
                'order_item_id' => null,
                'product_id' => null,
                'shipping_code' => $directItem['shipping_code'] ?? '',
                'item_label' => $itemLabel,
                'actual_cartons' => $actualCartons,
                'actual_pieces_per_carton' => $actualPieces,
                'actual_quantity' => $actualQuantity,
                'unit_price' => $factoryPrice,
                'total_amount' => $totalAmount,
                'actual_cbm' => $actualCbm,
                'actual_weight' => $actualWeight,
                'condition' => $condition,
                'notes' => $directItem['notes'],
                'direct_item' => $directItem,
            ];
        }

        if (!$rows) {
            $errors[] = 'No import rows found.';
        }
        $validCount = count(array_filter($rows, static fn($row) => !empty($row['valid'])));
        $errorCount = count($rows) - $validCount;
        if ($errorCount > 0) {
            $errors[] = 'Fix row-level errors before importing.';
        }

        $orders = $this->summarizeDirectIntakeOrders($rows);
        return [
            'mode' => 'direct_intake',
            'rows' => $rows,
            'orders' => array_values($orders),
            'payloads' => [],
            'direct_groups' => $this->buildDirectIntakeGroups($rows),
            'row_count' => count($rows),
            'valid_count' => $validCount,
            'error_count' => $errorCount,
            'warnings' => array_values(array_unique(array_merge(...array_map(static fn($row) => $row['warnings'] ?? [], $rows ?: [[]])))),
            'errors' => array_values(array_unique($errors)),
            'is_valid' => $errorCount === 0 && $validCount > 0,
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

    private function applyPreviewOverrides(array $metadata, array $overrides): array
    {
        $customerId = trim((string) ($overrides['customer_id'] ?? ''));
        if ($customerId !== '' && ctype_digit($customerId)) {
            $metadata['customer_id'] = $customerId;
        }
        $customerName = trim((string) ($overrides['customer_name'] ?? ''));
        if ($customerName !== '') {
            $metadata['customer_name'] = $customerName;
        }
        $currency = strtoupper(trim((string) ($overrides['currency'] ?? '')));
        if (in_array($currency, ['USD', 'RMB'], true)) {
            $metadata['currency'] = $currency;
        }
        $expectedReady = trim((string) ($overrides['expected_ready_date'] ?? ''));
        if ($expectedReady !== '') {
            $metadata['expected_ready_date'] = $this->normalizeDateValue($expectedReady);
        }
        return $metadata;
    }

    public function commitDirectIntake(PDO $pdo, array $preview, int $userId, int $importId): array
    {
        $groups = $preview['direct_groups'] ?? [];
        if (!$groups) {
            throw new OrderReceivingValidationException('No direct receiving rows to import', 400);
        }

        $results = [];
        $receivingService = new OrderReceivingService();
        foreach ($groups as $group) {
            if ((int) ($group['customer_id'] ?? 0) <= 0 && !empty($group['customer_fallback'])) {
                $group['customer_id'] = $this->resolveOrCreateDirectIntakeCustomer($pdo, $userId, $importId);
                $group['customer_name'] = self::DIRECT_INTAKE_CUSTOMER_NAME;
            }
            if ((int) ($group['customer_id'] ?? 0) <= 0) {
                throw new OrderReceivingValidationException('Customer is required for direct receiving import.', 422);
            }
            $supplierId = $this->resolveOrCreateSupplierForDirectIntake($pdo, $group, $userId, $importId);
            $orderId = $this->insertDirectIntakeOrder($pdo, $group, $supplierId, $userId);
            $items = $this->insertDirectIntakeItems($pdo, $orderId, $group['items'] ?? [], $supplierId);
            $receiptPayload = $this->buildDirectReceiptPayload($items);
            $results[$orderId] = $receivingService->receive($pdo, $orderId, $receiptPayload, $userId, false, [
                'source' => 'direct_receiving_excel_import',
                'import_id' => $importId,
            ]);
            $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('order', ?, 'direct_receive_import_create', ?, ?)")
                ->execute([$orderId, json_encode([
                    'import_id' => $importId,
                    'source' => 'receiving_excel_direct_intake',
                    'rows' => count($items),
                    'supplier_id' => $supplierId,
                ], JSON_UNESCAPED_UNICODE), $userId]);
        }

        return [
            'mode' => 'direct_intake',
            'orders_imported' => count($results),
            'receipts' => $results,
            'row_count' => (int) ($preview['row_count'] ?? 0),
        ];
    }

    private function hasExplicitOrderReference(array $rawRows): bool
    {
        foreach ($rawRows as $raw) {
            if (trim((string) ($raw['order_id'] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    private function cleanDirectText($value, int $maxLength): ?string
    {
        $text = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($value ?? '')) ?? '');
        if ($text === '') {
            return null;
        }
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $maxLength, 'UTF-8');
        }
        return substr($text, 0, $maxLength);
    }

    private function normalizeDirectUnit($value, ?float $quantity, ?float $cartons): string
    {
        $normalized = $this->normalizeHeaderKey($value);
        if (in_array($normalized, ['carton', 'cartons', 'ctn', 'ctns'], true)) {
            return 'cartons';
        }
        if (in_array($normalized, ['piece', 'pieces', 'pcs', 'pc', 'unit', 'units'], true)) {
            return 'pieces';
        }
        return ($quantity === null && $cartons !== null && $cartons > 0) ? 'cartons' : 'pieces';
    }

    private function resolveDirectCustomer(PDO $pdo, array $raw, array $metadata, array &$cache, array &$errors): ?array
    {
        $idRaw = trim((string) (($raw['customer_id'] ?? '') ?: ($metadata['customer_id'] ?? '')));
        $value = trim((string) (($raw['customer_name'] ?? '') ?: ($metadata['customer_name'] ?? '')));
        $cacheKey = $idRaw !== '' ? 'id:' . $idRaw : 'value:' . $this->normalizeMatchText($value);
        if (isset($cache[$cacheKey])) {
            if (!$cache[$cacheKey]) {
                $errors[] = $value !== '' ? 'Customer "' . $value . '" was not found.' : 'Customer is required in the template metadata or row.';
            }
            return $cache[$cacheKey];
        }

        if ($idRaw !== '') {
            if (!ctype_digit($idRaw)) {
                $errors[] = 'Customer ID must be a whole number.';
                $cache[$cacheKey] = null;
                return null;
            }
            $stmt = $pdo->prepare("SELECT id, name FROM customers WHERE id = ? LIMIT 1");
            $stmt->execute([(int) $idRaw]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$row) {
                $errors[] = 'Customer #' . $idRaw . ' was not found.';
            }
            return $cache[$cacheKey] = $row ?: null;
        }

        if ($value === '') {
            return $cache[$cacheKey] = [
                'id' => 0,
                'name' => self::DIRECT_INTAKE_CUSTOMER_NAME,
                '_fallback' => true,
            ];
        }

        $clauses = ['LOWER(TRIM(name)) = LOWER(TRIM(?))'];
        $params = [$value];
        if ($this->tableHasColumn($pdo, 'customers', 'code')) {
            $clauses[] = 'LOWER(TRIM(code)) = LOWER(TRIM(?))';
            $params[] = $value;
        }
        if ($this->tableHasColumn($pdo, 'customers', 'default_shipping_code')) {
            $clauses[] = 'LOWER(TRIM(default_shipping_code)) = LOWER(TRIM(?))';
            $params[] = $value;
        }
        $stmt = $pdo->prepare("SELECT id, name FROM customers WHERE " . implode(' OR ', $clauses) . " ORDER BY id ASC LIMIT 2");
        $stmt->execute($params);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($matches) === 1) {
            return $cache[$cacheKey] = $matches[0];
        }
        $errors[] = count($matches) > 1
            ? 'Customer "' . $value . '" matches multiple records. Use Customer ID in the template.'
            : 'Customer "' . $value . '" was not found.';
        return $cache[$cacheKey] = null;
    }

    private function resolveOrCreateDirectIntakeCustomer(PDO $pdo, int $userId, int $importId): int
    {
        $clauses = ['code = ?'];
        $params = [self::DIRECT_INTAKE_CUSTOMER_CODE];
        $clauses[] = 'name = ?';
        $params[] = self::DIRECT_INTAKE_CUSTOMER_NAME;

        $stmt = $pdo->prepare("SELECT id FROM customers WHERE " . implode(' OR ', $clauses) . " ORDER BY id ASC LIMIT 1");
        $stmt->execute($params);
        $existingId = (int) ($stmt->fetchColumn() ?: 0);
        if ($existingId > 0) {
            return $existingId;
        }

        $columns = ['code', 'name'];
        $values = ['?', '?'];
        $insertParams = [self::DIRECT_INTAKE_CUSTOMER_CODE, self::DIRECT_INTAKE_CUSTOMER_NAME];
        if ($this->tableHasColumn($pdo, 'customers', 'default_shipping_code')) {
            $columns[] = 'default_shipping_code';
            $values[] = '?';
            $insertParams[] = 'DIRECT-WH';
        }
        if ($this->tableHasColumn($pdo, 'customers', 'created_by')) {
            $columns[] = 'created_by';
            $values[] = '?';
            $insertParams[] = $userId;
        }

        $pdo->prepare("INSERT INTO customers (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ")")
            ->execute($insertParams);
        $customerId = (int) $pdo->lastInsertId();
        $this->logDirectIntakeCustomer($pdo, $customerId, $userId, $importId);
        return $customerId;
    }

    private function logDirectIntakeCustomer(PDO $pdo, int $customerId, int $userId, int $importId): void
    {
        try {
            $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('customer', ?, 'direct_receive_import_fallback_create', ?, ?)")
                ->execute([$customerId, json_encode([
                    'import_id' => $importId,
                    'code' => self::DIRECT_INTAKE_CUSTOMER_CODE,
                    'name' => self::DIRECT_INTAKE_CUSTOMER_NAME,
                    'source' => 'receiving_excel_direct_intake',
                ], JSON_UNESCAPED_UNICODE), $userId]);
        } catch (Throwable $e) {
            // Audit logging should never block receiving intake.
        }
    }

    private function resolveDirectSupplierPreview(PDO $pdo, array $raw, array &$cache): ?array
    {
        return $this->resolveDirectSupplier($pdo, $raw, $cache, false, 0, 0);
    }

    private function resolveOrCreateSupplierForDirectIntake(PDO $pdo, array $group, int $userId, int $importId): ?int
    {
        $cache = [];
        $raw = [
            'supplier_id' => $group['supplier_id'] ?? '',
            'supplier_code' => $group['supplier_code'] ?? '',
            'supplier_name' => $group['supplier_name'] ?? '',
        ];
        $supplier = $this->resolveDirectSupplier($pdo, $raw, $cache, true, $userId, $importId);
        return $supplier ? (int) $supplier['id'] : null;
    }

    private function resolveDirectSupplier(PDO $pdo, array $raw, array &$cache, bool $createIfMissing, int $userId, int $importId): ?array
    {
        $idRaw = trim((string) ($raw['supplier_id'] ?? ''));
        $code = trim((string) ($raw['supplier_code'] ?? ''));
        $name = trim((string) (($raw['supplier_name'] ?? '') ?: $code));
        $cacheKey = $idRaw !== '' ? 'id:' . $idRaw : 'value:' . $this->normalizeMatchText($code . '|' . $name);
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }
        if ($idRaw !== '' && ctype_digit($idRaw)) {
            $stmt = $pdo->prepare("SELECT id, code, name FROM suppliers WHERE id = ? LIMIT 1");
            $stmt->execute([(int) $idRaw]);
            return $cache[$cacheKey] = ($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
        }
        if ($code === '' && $name === '') {
            return $cache[$cacheKey] = null;
        }

        $clauses = [];
        $params = [];
        if ($code !== '' && $this->tableHasColumn($pdo, 'suppliers', 'code')) {
            $clauses[] = 'LOWER(TRIM(code)) = LOWER(TRIM(?))';
            $params[] = $code;
        }
        if ($name !== '') {
            $clauses[] = 'LOWER(TRIM(name)) = LOWER(TRIM(?))';
            $params[] = $name;
        }
        if ($clauses) {
            $stmt = $pdo->prepare("SELECT id, code, name FROM suppliers WHERE " . implode(' OR ', $clauses) . " ORDER BY id ASC LIMIT 1");
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $cache[$cacheKey] = $row;
            }
        }
        if (!$createIfMissing) {
            return $cache[$cacheKey] = null;
        }

        $insertName = $name !== '' ? $name : $code;
        $insertCode = $this->uniqueSupplierCode($pdo, $code !== '' ? $code : $insertName);
        $stmt = $pdo->prepare("INSERT INTO suppliers (code, name, notes) VALUES (?, ?, ?)");
        $stmt->execute([$insertCode, $insertName, 'Created by receiving Excel direct import #' . $importId]);
        $supplierId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id) VALUES ('supplier', ?, 'create', ?, ?)")
            ->execute([$supplierId, json_encode([
                'source' => 'receiving_excel_direct_intake',
                'import_id' => $importId,
                'code' => $insertCode,
                'name' => $insertName,
            ], JSON_UNESCAPED_UNICODE), $userId]);
        return $cache[$cacheKey] = ['id' => $supplierId, 'code' => $insertCode, 'name' => $insertName];
    }

    private function uniqueSupplierCode(PDO $pdo, string $base): string
    {
        $base = strtoupper(preg_replace('/[^A-Z0-9]+/i', '-', trim($base)) ?? '');
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'SUP';
        }
        $base = substr($base, 0, 30);
        $candidate = $base;
        $i = 1;
        $stmt = $pdo->prepare("SELECT 1 FROM suppliers WHERE code = ? LIMIT 1");
        while (true) {
            $stmt->execute([$candidate]);
            if (!$stmt->fetchColumn()) {
                return $candidate;
            }
            $suffix = '-' . (++$i);
            $candidate = substr($base, 0, max(1, 50 - strlen($suffix))) . $suffix;
        }
    }

    private function summarizeDirectIntakeOrders(array $rows): array
    {
        $summary = [];
        foreach ($rows as $row) {
            if (empty($row['valid'])) {
                continue;
            }
            $item = $row['direct_item'] ?? [];
            $key = $this->directGroupKey($item);
            if (!isset($summary[$key])) {
                $summary[$key] = [
                    'order_id' => null,
                    'label' => 'New direct receipt',
                    'customer_id' => $item['customer_id'] ?? null,
                    'customer_name' => $item['customer_name'] ?? '',
                    'supplier_id' => $item['supplier_id'] ?? null,
                    'supplier_name' => $item['supplier_name'] ?? '',
                    'rows' => 0,
                    'valid_rows' => 0,
                    'actual_cartons' => 0,
                    'actual_cbm' => 0,
                    'actual_weight' => 0,
                ];
            }
            $summary[$key]['rows']++;
            $summary[$key]['valid_rows']++;
            $summary[$key]['actual_cartons'] += (int) round((float) ($row['actual_cartons'] ?? 0));
            $summary[$key]['actual_cbm'] += (float) ($row['actual_cbm'] ?? 0);
            $summary[$key]['actual_weight'] += (float) ($row['actual_weight'] ?? 0);
        }
        foreach ($summary as &$row) {
            $row['actual_cbm'] = round((float) $row['actual_cbm'], 6);
            $row['actual_weight'] = round((float) $row['actual_weight'], 4);
        }
        unset($row);
        return $summary;
    }

    private function buildDirectIntakeGroups(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            if (empty($row['valid'])) {
                continue;
            }
            $item = $row['direct_item'] ?? [];
            $key = $this->directGroupKey($item);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'customer_id' => (int) ($item['customer_id'] ?? 0),
                    'customer_name' => $item['customer_name'] ?? '',
                    'customer_fallback' => !empty($item['customer_fallback']),
                    'supplier_id' => $item['supplier_id'] ?? null,
                    'supplier_code' => $item['supplier_code'] ?? null,
                    'supplier_name' => $item['supplier_name'] ?? null,
                    'expected_ready_date' => $item['expected_ready_date'] ?? date('Y-m-d'),
                    'currency' => in_array($item['currency'] ?? 'RMB', ['USD', 'RMB'], true) ? $item['currency'] : 'RMB',
                    'items' => [],
                ];
            }
            $groups[$key]['items'][] = $item;
        }
        return array_values($groups);
    }

    private function directGroupKey(array $item): string
    {
        return md5(implode('|', [
            (string) ($item['customer_id'] ?? ''),
            !empty($item['customer_fallback']) ? 'fallback' : '',
            (string) ($item['supplier_id'] ?? ''),
            $this->normalizeMatchText($item['supplier_code'] ?? ''),
            $this->normalizeMatchText($item['supplier_name'] ?? ''),
            (string) ($item['expected_ready_date'] ?? ''),
            (string) ($item['currency'] ?? ''),
        ]));
    }

    private function insertDirectIntakeOrder(PDO $pdo, array $group, ?int $supplierId, int $userId): int
    {
        $columns = ['customer_id', 'supplier_id', 'expected_ready_date', 'status', 'created_by'];
        $values = ['?', '?', '?', "'Approved'", '?'];
        $params = [
            (int) ($group['customer_id'] ?? 0),
            $supplierId,
            $group['expected_ready_date'] ?? date('Y-m-d'),
            $userId,
        ];
        if ($this->tableHasColumn($pdo, 'orders', 'currency')) {
            $columns[] = 'currency';
            $values[] = '?';
            $params[] = in_array($group['currency'] ?? 'RMB', ['USD', 'RMB'], true) ? $group['currency'] : 'RMB';
        }
        if ($this->tableHasColumn($pdo, 'orders', 'order_type')) {
            $columns[] = 'order_type';
            $values[] = '?';
            $params[] = 'draft_procurement';
        }
        if ($this->tableHasColumn($pdo, 'orders', 'high_alert_notes')) {
            $columns[] = 'high_alert_notes';
            $values[] = '?';
            $params[] = 'Direct warehouse receiving Excel import';
        }
        $pdo->prepare("INSERT INTO orders (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ")")
            ->execute($params);
        return (int) $pdo->lastInsertId();
    }

    private function insertDirectIntakeItems(PDO $pdo, int $orderId, array $items, ?int $defaultSupplierId): array
    {
        $metadataColumns = [];
        foreach (['what_brand', 'brand', 'materials', 'copy_normal_goods', 'code', 'express_number', 'size', 'length', 'width', 'height'] as $column) {
            if ($this->tableHasColumn($pdo, 'order_items', $column)) {
                $metadataColumns[] = $column;
            }
        }
        $columns = "order_id, product_id, item_no, shipping_code, cartons, qty_per_carton, quantity, unit, declared_cbm, declared_weight, item_length, item_width, item_height, unit_price, total_amount, notes, image_paths, description_cn, description_en";
        $values = "?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?";
        foreach ($metadataColumns as $column) {
            $columns .= ", $column";
            $values .= ",?";
        }
        foreach (['supplier_id', 'buy_price', 'sell_price', 'order_cartons', 'order_qty_per_carton', 'hs_code'] as $column) {
            if ($this->tableHasColumn($pdo, 'order_items', $column)) {
                $columns .= ", $column";
                $values .= ",?";
            }
        }
        $insert = $pdo->prepare("INSERT INTO order_items ($columns) VALUES ($values)");
        $inserted = [];
        foreach ($items as $item) {
            $supplierId = !empty($item['supplier_id']) ? (int) $item['supplier_id'] : $defaultSupplierId;
            $cartons = isset($item['cartons']) ? (int) round((float) $item['cartons']) : null;
            $qtyPerCarton = $item['pieces_per_carton'] !== null ? (float) $item['pieces_per_carton'] : null;
            $quantity = $item['quantity'] !== null ? (float) $item['quantity'] : (float) ($cartons ?? 0);
            $unitPrice = $item['unit_price'] !== null ? (float) $item['unit_price'] : null;
            $sellPrice = $item['sell_price'] !== null ? (float) $item['sell_price'] : null;
            $params = [
                $orderId,
                null,
                $item['item_no'] ?? null,
                $item['shipping_code'] ?? null,
                $cartons,
                $qtyPerCarton,
                $quantity,
                in_array($item['unit'] ?? 'pieces', ['pieces', 'cartons'], true) ? $item['unit'] : 'pieces',
                (float) ($item['declared_cbm'] ?? 0),
                (float) ($item['declared_weight'] ?? 0),
                $item['length'] ?? null,
                $item['width'] ?? null,
                $item['height'] ?? null,
                $unitPrice,
                $item['total_amount'] !== null ? (float) $item['total_amount'] : null,
                $item['notes'] ?? null,
                null,
                $item['description_cn'] ?? null,
                $item['description_en'] ?? null,
            ];
            foreach ($metadataColumns as $column) {
                switch ($column) {
                    case 'what_brand':
                    case 'brand':
                        $params[] = $item['brand'] ?? null;
                        break;
                    case 'materials':
                        $params[] = $item['materials'] ?? null;
                        break;
                    case 'code':
                        $params[] = $item['code'] ?? null;
                        break;
                    case 'express_number':
                        $params[] = $item['express_number'] ?? null;
                        break;
                    case 'length':
                        $params[] = $item['length'] ?? null;
                        break;
                    case 'width':
                        $params[] = $item['width'] ?? null;
                        break;
                    case 'height':
                        $params[] = $item['height'] ?? null;
                        break;
                    default:
                        $params[] = null;
                        break;
                }
            }
            foreach (['supplier_id', 'buy_price', 'sell_price', 'order_cartons', 'order_qty_per_carton', 'hs_code'] as $column) {
                if (!$this->tableHasColumn($pdo, 'order_items', $column)) {
                    continue;
                }
                switch ($column) {
                    case 'supplier_id':
                        $params[] = $supplierId;
                        break;
                    case 'buy_price':
                        $params[] = $unitPrice;
                        break;
                    case 'sell_price':
                        $params[] = $sellPrice;
                        break;
                    case 'order_cartons':
                        $params[] = $cartons;
                        break;
                    case 'order_qty_per_carton':
                        $params[] = $qtyPerCarton;
                        break;
                    case 'hs_code':
                        $params[] = $item['hs_code'] ?? null;
                        break;
                    default:
                        $params[] = null;
                        break;
                }
            }
            $insert->execute($params);
            $item['order_item_id'] = (int) $pdo->lastInsertId();
            $inserted[] = $item;
        }
        return $inserted;
    }

    private function buildDirectReceiptPayload(array $items): array
    {
        $actualCartons = 0;
        $actualCbm = 0.0;
        $actualWeight = 0.0;
        $receiptItems = [];
        foreach ($items as $item) {
            $cartons = isset($item['cartons']) ? (int) round((float) $item['cartons']) : 0;
            $quantity = $item['quantity'] !== null ? (float) $item['quantity'] : null;
            $unitPrice = $item['unit_price'] !== null ? (float) $item['unit_price'] : null;
            $totalAmount = $item['total_amount'] !== null ? (float) $item['total_amount'] : null;
            $actualCartons += $cartons;
            $actualCbm += (float) ($item['declared_cbm'] ?? 0);
            $actualWeight += (float) ($item['declared_weight'] ?? 0);
            $receiptItems[] = [
                'order_item_id' => (int) $item['order_item_id'],
                'actual_cartons' => $cartons,
                'actual_pieces_per_carton' => $item['pieces_per_carton'],
                'actual_quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_amount' => $totalAmount,
                'actual_cbm' => (float) ($item['declared_cbm'] ?? 0),
                'actual_weight' => (float) ($item['declared_weight'] ?? 0),
                'condition' => $item['condition'] ?? 'good',
                'notes' => $item['notes'] ?? null,
                'photo_paths' => [],
                'packaging_splits' => [[
                    'cartons' => $cartons,
                    'pieces_per_carton' => $item['pieces_per_carton'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_amount' => $totalAmount,
                ]],
            ];
        }

        return [
            'actual_cartons' => $actualCartons,
            'actual_cbm' => round($actualCbm, 6),
            'actual_weight' => round($actualWeight, 4),
            'condition' => 'good',
            'notes' => 'Direct warehouse receiving Excel import',
            'photo_paths' => [],
            'items' => $receiptItems,
        ];
    }

    private function validateUpload(array $file): string
    {
        if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('No receiving import file uploaded.');
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls', 'csv', 'cv'], true)) {
            throw new InvalidArgumentException('Upload an .xlsx, .xls, or .csv file.');
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

    private function readRowsFromFile(string $path, string $filename): array
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, ['csv', 'cv'], true)) {
            return [$this->readCsvRows($path), 'csv'];
        }

        if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            throw new InvalidArgumentException('Excel import is not available because the spreadsheet library is missing on this server.');
        }

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }
        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }
        if (method_exists($reader, 'setReadFilter')) {
            $reader->setReadFilter(new class(self::MAX_ROWS + self::READ_LIMIT_EXTRA_ROWS) implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
                private int $maxRow;

                public function __construct(int $maxRow)
                {
                    $this->maxRow = $maxRow;
                }

                public function readCell($columnAddress, $row, $worksheetName = ''): bool
                {
                    return (int) $row <= $this->maxRow;
                }
            });
        }

        $spreadsheet = $reader->load($path);
        try {
            $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
        return [$rows, strtolower($ext ?: 'excel') . '_data_only'];
    }

    private function readCsvRows(string $path): array
    {
        $sample = (string) @file_get_contents($path, false, null, 0, 8192);
        $delimiter = ',';
        $counts = [
            ',' => substr_count($sample, ','),
            ';' => substr_count($sample, ';'),
            "\t" => substr_count($sample, "\t"),
        ];
        arsort($counts);
        $candidate = (string) array_key_first($counts);
        if (($counts[$candidate] ?? 0) > 0) {
            $delimiter = $candidate;
        }

        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new InvalidArgumentException('Uploaded CSV file is not readable.');
        }
        $rows = [];
        $rowNumber = 0;
        while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNumber++;
            if ($rowNumber > self::MAX_ROWS + self::READ_LIMIT_EXTRA_ROWS) {
                break;
            }
            $row = [];
            foreach ($values as $index => $value) {
                $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
                $row[$column] = is_string($value) ? preg_replace('/^\xEF\xBB\xBF/', '', $value) : $value;
            }
            $rows[$rowNumber] = $row;
        }
        fclose($handle);
        return $rows;
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
        $value = strtolower(trim((string) ($value ?? '')));
        $value = str_replace(['#', '（', '）', '(', ')'], ' ', $value);
        return preg_replace('/[^a-z0-9]+/', '', $value) ?: '';
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

    private function looksLikeImportHeader(array $headers): bool
    {
        if (!$headers) {
            return false;
        }

        $itemFields = [
            'order_id',
            'order_item_id',
            'product_id',
            'item_code',
            'express_number',
            'shipping_code',
            'item_no',
            'description',
            'description_en',
            'description_cn',
        ];
        $measureFields = [
            'actual_cartons',
            'actual_quantity',
            'actual_cbm',
            'cbm_unit',
            'actual_weight',
            'weight_unit',
        ];
        $itemScore = count(array_filter($itemFields, static fn($field) => isset($headers[$field])));
        $measureScore = count(array_filter($measureFields, static fn($field) => isset($headers[$field])));

        return $measureScore > 0 && ($itemScore > 0 || isset($headers['supplier_name']) || isset($headers['supplier_code']));
    }

    private function captureTemplateMetadata(array $row, array &$metadata): void
    {
        $values = array_values($row);
        $label = $this->normalizeHeaderKey($values[0] ?? '');
        $value = trim((string) ($values[1] ?? ''));
        if ($label === '' || $value === '') {
            return;
        }

        if ($label === 'customer') {
            $metadata['customer_name'] = $value;
        } elseif (in_array($label, ['expectedready', 'expecteddate', 'readydate'], true)) {
            $metadata['expected_ready_date'] = $this->normalizeDateValue($value);
        } elseif ($label === 'currency') {
            $currency = strtoupper($value);
            if (in_array($currency, ['USD', 'RMB'], true)) {
                $metadata['currency'] = $currency;
            }
        } elseif (in_array($label, ['supplier', 'suppliername'], true)) {
            $metadata['supplier_name'] = $value;
        }
    }

    private function validateHeaders(array $headers): array
    {
        $errors = [];
        if (!$this->looksLikeImportHeader($headers)) {
            $errors[] = 'No receiving rows matched the procurement import template. Use the same template as Draft an Order and keep the blue header row.';
        }
        if (!isset($headers['actual_cartons'])) {
            $errors[] = 'Missing required column: Cartons.';
        }
        if (!isset($headers['actual_cbm']) && !isset($headers['cbm_unit'])) {
            $errors[] = 'Missing required column: Total CBM or CBM/Unit.';
        }
        if (!isset($headers['actual_weight']) && !isset($headers['weight_unit'])) {
            $errors[] = 'Missing required column: Total Weight or Weight/Unit.';
        }
        return $errors;
    }

    private function missingOptionalColumns(array $headers): array
    {
        $labels = [
            'order_item_id' => 'Order Item ID',
            'product_id' => 'Product ID',
            'item_code' => 'SKU / Item Code',
            'express_number' => 'Express Number',
            'shipping_code' => 'Shipping Code',
            'item_no' => 'Item No',
            'description_en' => 'English Item Name',
            'description_cn' => 'Chinese Item Name',
            'supplier_name' => 'Supplier Name',
            'actual_pieces_per_carton' => 'Pieces / Carton',
            'actual_quantity' => 'Quantity',
            'unit_price' => 'Unit Price',
            'total_amount' => 'Total Amount',
            'cbm_unit' => 'CBM/Unit',
            'weight_unit' => 'Weight/Unit',
            'condition' => 'Condition',
            'notes' => 'Notes',
        ];
        $missing = [];
        foreach ($labels as $field => $label) {
            if (!isset($headers[$field])) {
                $missing[] = $label;
            }
        }
        return $missing;
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

    private function normalizeDateValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (is_numeric($value)) {
            $serial = (float) $value;
            if ($serial > 20000 && $serial < 80000) {
                return gmdate('Y-m-d', (int) round(($serial - 25569) * 86400));
            }
        }
        $timestamp = strtotime($value);
        return $timestamp !== false ? date('Y-m-d', $timestamp) : $value;
    }

    private function numberFromString($value): ?float
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '' || $raw === '-' || $raw === '—') {
            return null;
        }
        $normalized = preg_replace('/[^\d.\-]+/', '', str_replace(',', '', $raw)) ?? '';
        if ($normalized === '' || $normalized === '-' || $normalized === '.') {
            return null;
        }
        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function formatImportNumber(?float $value, int $decimals = 6): string
    {
        if ($value === null) {
            return '';
        }
        $formatted = number_format($value, $decimals, '.', '');
        return rtrim(rtrim($formatted, '0'), '.');
    }

    private function applyDerivedTemplateValues(array &$row): void
    {
        $cartons = $this->numberFromString($row['actual_cartons'] ?? '');
        $quantity = $this->numberFromString($row['actual_quantity'] ?? '');

        if (trim((string) ($row['actual_cbm'] ?? '')) === '') {
            $cbmUnit = $this->numberFromString($row['cbm_unit'] ?? '');
            if ($cbmUnit !== null) {
                $multiplier = $cartons !== null && $cartons > 0
                    ? $cartons
                    : ($quantity !== null && $quantity > 0 ? $quantity : null);
                if ($multiplier !== null) {
                    $row['actual_cbm'] = $this->formatImportNumber($cbmUnit * $multiplier, 6);
                }
            }
        }

        if (trim((string) ($row['actual_weight'] ?? '')) === '') {
            $weightUnit = $this->numberFromString($row['weight_unit'] ?? '');
            if ($weightUnit !== null) {
                $multiplier = $cartons !== null && $cartons > 0
                    ? $cartons
                    : ($quantity !== null && $quantity > 0 ? $quantity : null);
                if ($multiplier !== null) {
                    $row['actual_weight'] = $this->formatImportNumber($weightUnit * $multiplier, 4);
                }
            }
        }
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
        $number = $this->numberFromString($raw);
        if ($number === null) {
            $errors[] = $label . ' has an invalid number format.';
            return null;
        }
        return $number;
    }

    private function rowDescriptionText(array $raw): string
    {
        foreach (['description_en', 'description_cn', 'description'] as $field) {
            $value = trim((string) ($raw[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function normalizeMatchText($value): string
    {
        $value = strtolower(trim((string) ($value ?? '')));
        return preg_replace('/[\s_\-\/#]+/', '', $value) ?: '';
    }

    private function sameText($left, $right): bool
    {
        $left = $this->normalizeMatchText($left);
        $right = $this->normalizeMatchText($right);
        return $left !== '' && $right !== '' && $left === $right;
    }

    private function loadOrdersForRows(PDO $pdo, array $rawRows): array
    {
        $orderIds = [];
        foreach ($rawRows as $raw) {
            $rawOrderId = trim((string) ($raw['order_id'] ?? ''));
            if ($rawOrderId !== '' && ctype_digit($rawOrderId)) {
                $orderIds[(int) $rawOrderId] = true;
            }
        }
        if (!$orderIds) {
            return [];
        }
        $ids = array_keys($orderIds);
        $stmt = $pdo->prepare("SELECT id, status FROM orders WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
        $stmt->execute($ids);
        $orders = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $order) {
            $orders[(int) $order['id']] = $order;
        }
        return $orders;
    }

    private function loadOrderItemsForRows(PDO $pdo, array $orderIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds), static fn($id) => $id > 0)));
        if (!$ids) {
            return [];
        }
        $extraCols = '';
        foreach (['code', 'express_number', 'size'] as $column) {
            if ($this->tableHasColumn($pdo, 'order_items', $column)) {
                $extraCols .= ", oi.$column";
            }
        }
        $stmt = $pdo->prepare("SELECT oi.order_id, oi.id, oi.product_id, oi.item_no, oi.shipping_code, oi.cartons, oi.qty_per_carton, oi.quantity, oi.unit_price, oi.total_amount, oi.declared_cbm, oi.declared_weight, oi.description_cn, oi.description_en$extraCols FROM order_items oi WHERE oi.order_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
        $stmt->execute($ids);
        $itemsByOrder = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $orderId = (int) ($item['order_id'] ?? 0);
            unset($item['order_id']);
            $itemsByOrder[$orderId][] = $item;
        }
        return $itemsByOrder;
    }

    private function loadProductExistsForRows(PDO $pdo, array $rawRows): array
    {
        $productIds = [];
        foreach ($rawRows as $raw) {
            $rawProductId = trim((string) ($raw['product_id'] ?? ''));
            if ($rawProductId !== '' && ctype_digit($rawProductId)) {
                $productIds[(int) $rawProductId] = true;
            }
        }
        if (!$productIds) {
            return [];
        }
        $ids = array_keys($productIds);
        $stmt = $pdo->prepare("SELECT id FROM products WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
        $stmt->execute($ids);
        $exists = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $exists[(int) $id] = true;
        }
        return $exists;
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
            return $cache[$key] = (bool) $stmt->rowCount();
        } catch (Throwable $e) {
            return $cache[$key] = false;
        }
    }

    private function resolveOrderItem(array $raw, array $items, array $productExistsCache, array &$errors): ?array
    {
        $orderItemIdRaw = trim((string) ($raw['order_item_id'] ?? ''));
        $productIdRaw = trim((string) ($raw['product_id'] ?? ''));
        $itemCode = trim((string) ($raw['item_code'] ?? ''));
        $expressNumber = trim((string) ($raw['express_number'] ?? ''));
        $shippingCode = trim((string) ($raw['shipping_code'] ?? ''));
        $itemNo = trim((string) ($raw['item_no'] ?? ''));
        $description = $this->rowDescriptionText($raw);

        if ($orderItemIdRaw === '' && $productIdRaw === '' && $itemCode === '' && $expressNumber === '' && $shippingCode === '' && $itemNo === '' && $description === '') {
            if (count($items) === 1) {
                return $items[0];
            }
            $errors[] = count($items) > 1
                ? 'Missing item reference. This order has multiple items; use Order Item ID, Shipping Code, Item No, SKU / Item Code, Express Number, or Product ID.'
                : 'This order has no items available for receiving.';
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
            $errors[] = isset($productExistsCache[$productId])
                ? 'Product #' . $productId . ' is not on this order.'
                : 'Product #' . $productId . ' was not found.';
            return null;
        }

        if ($itemCode !== '') {
            $matches = array_values(array_filter($items, static fn($item) => strcasecmp(trim((string) ($item['code'] ?? '')), $itemCode) === 0));
            if (count($matches) === 1) {
                return $matches[0];
            }
            $errors[] = count($matches) > 1
                ? 'SKU / Item Code "' . $itemCode . '" matches multiple items. Use Order Item ID.'
                : 'SKU / Item Code "' . $itemCode . '" was not found on this order.';
            return null;
        }

        if ($expressNumber !== '') {
            $matches = array_values(array_filter($items, fn($item) => $this->sameText($item['express_number'] ?? '', $expressNumber)));
            if (count($matches) === 1) {
                return $matches[0];
            }
            $errors[] = count($matches) > 1
                ? 'Express Number "' . $expressNumber . '" matches multiple items. Use Order Item ID.'
                : 'Express Number "' . $expressNumber . '" was not found on this order.';
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

        if ($description !== '') {
            $matches = array_values(array_filter($items, fn($item) => $this->sameText($item['description_en'] ?? '', $description) || $this->sameText($item['description_cn'] ?? '', $description)));
            if (count($matches) === 1) {
                return $matches[0];
            }
            if (count($matches) > 1) {
                $errors[] = 'Description "' . $description . '" matches multiple items. Use Order Item ID.';
            }
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
