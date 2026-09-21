<?php

/**
 * Financials API - profit, balances, outstanding
 * Roles: ChinaAdmin, LebanonAdmin, SuperAdmin
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 2) . '/services/DecimalMath.php';

function financialsTableHasColumn(PDO $pdo, string $table, string $column): bool
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

function financialSupplierSettlementExpr(PDO $pdo): string
{
    if (financialsTableHasColumn($pdo, 'supplier_payments', 'settlement_delta')) {
        return 'COALESCE(settlement_delta, COALESCE(discount_amount,0), 0)';
    }
    return 'COALESCE(discount_amount,0)';
}

function financialsSupportsSharedCartons(PDO $pdo): bool
{
    return financialsTableHasColumn($pdo, 'order_items', 'shared_carton_enabled')
        && financialsTableHasColumn($pdo, 'order_items', 'shared_carton_contents');
}

function financialsDecodeSharedCartonContents(array $row): array
{
    $raw = $row['shared_carton_contents'] ?? null;
    if (!$raw) {
        return [];
    }

    $decoded = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
    if (!$decoded) {
        return [];
    }

    $cartons = DecimalMath::normalize($row['cartons'] ?? '0');
    foreach ($decoded as &$content) {
        $content['supplier_id'] = !empty($content['supplier_id']) ? (int) $content['supplier_id'] : null;
        $content['quantity_per_carton'] = DecimalMath::normalize($content['quantity_per_carton'] ?? $content['quantity'] ?? '0');
        $content['quantity'] = DecimalMath::multiply($content['quantity_per_carton'], $cartons);
        $content['unit_price'] = isset($content['unit_price']) && $content['unit_price'] !== '' ? DecimalMath::normalize($content['unit_price']) : null;
        $content['sell_price'] = isset($content['sell_price']) && $content['sell_price'] !== '' ? DecimalMath::normalize($content['sell_price']) : null;
    }
    unset($content);

    return $decoded;
}

function financialsLookupSupplierNames(PDO $pdo, array $supplierIds): array
{
    $supplierIds = array_values(array_unique(array_filter(array_map('intval', $supplierIds))));
    if (!$supplierIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($supplierIds), '?'));
    $stmt = $pdo->prepare("SELECT id, name FROM suppliers WHERE id IN ($placeholders)");
    $stmt->execute($supplierIds);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

function financialsBuildSupplierDisplay(array $supplierNames, string $fallback = ''): string
{
    $names = array_values(array_unique(array_filter(array_map('trim', $supplierNames))));
    if (!$names) {
        return trim($fallback);
    }
    if (count($names) === 1) {
        return $names[0];
    }
    return 'Multiple (' . implode(', ', $names) . ')';
}

function financialsBuildOrderItemAnalysis(PDO $pdo, array $orderIds): array
{
    if (!$orderIds) {
        return ['lines' => [], 'order_suppliers' => [], 'order_supplier_names' => []];
    }

    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $hasSharedCartons = financialsSupportsSharedCartons($pdo);
    $selectCols = [
        'oi.order_id',
        'oi.quantity',
        'oi.buy_price',
        'oi.sell_price',
        'oi.unit_price',
        'oi.cartons',
        'COALESCE(oi.supplier_id, p.supplier_id) as eff_supplier_id',
    ];
    if ($hasSharedCartons) {
        $selectCols[] = 'oi.shared_carton_enabled';
        $selectCols[] = 'oi.shared_carton_contents';
    }

    $stmt = $pdo->prepare(
        "SELECT " . implode(', ', $selectCols) . "
         FROM order_items oi
         LEFT JOIN products p ON oi.product_id = p.id
         WHERE oi.order_id IN ($placeholders)"
    );
    $stmt->execute($orderIds);

    $lines = [];
    $orderSuppliers = [];
    $supplierIdsSeen = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $orderId = (int) ($row['order_id'] ?? 0);
        $defaultSupplierId = !empty($row['eff_supplier_id']) ? (int) $row['eff_supplier_id'] : null;
        $sharedContents = ($hasSharedCartons && !empty($row['shared_carton_enabled']))
            ? financialsDecodeSharedCartonContents($row)
            : [];

        if ($sharedContents) {
            foreach ($sharedContents as $content) {
                $supplierId = $content['supplier_id'] ?: $defaultSupplierId;
                if ($supplierId) {
                    $orderSuppliers[$orderId][$supplierId] = true;
                    $supplierIdsSeen[$supplierId] = true;
                }
                $qty = DecimalMath::normalize($content['quantity'] ?? '0');
                $buyPrice = $content['unit_price'];
                $sellPrice = $content['sell_price'] ?? $buyPrice;
                $lines[] = [
                    'order_id' => $orderId,
                    'supplier_id' => $supplierId,
                    'buy_total' => DecimalMath::multiply($qty, $buyPrice ?? '0'),
                    'sell_total' => DecimalMath::multiply($qty, $sellPrice ?? '0'),
                ];
            }
            continue;
        }

        if ($defaultSupplierId) {
            $orderSuppliers[$orderId][$defaultSupplierId] = true;
            $supplierIdsSeen[$defaultSupplierId] = true;
        }
        $qty = DecimalMath::normalize($row['quantity'] ?? '0');
        $buyPrice = isset($row['buy_price']) && $row['buy_price'] !== null ? DecimalMath::normalize($row['buy_price']) : DecimalMath::normalize($row['unit_price'] ?? '0');
        $sellPrice = isset($row['sell_price']) && $row['sell_price'] !== null ? DecimalMath::normalize($row['sell_price']) : DecimalMath::normalize($row['unit_price'] ?? '0');
        $lines[] = [
            'order_id' => $orderId,
            'supplier_id' => $defaultSupplierId,
            'buy_total' => DecimalMath::multiply($qty, $buyPrice),
            'sell_total' => DecimalMath::multiply($qty, $sellPrice),
        ];
    }

    $supplierNamesById = financialsLookupSupplierNames($pdo, array_keys($supplierIdsSeen));
    $orderSupplierNames = [];
    foreach ($orderSuppliers as $orderId => $supplierMap) {
        $names = [];
        foreach (array_keys($supplierMap) as $supplierId) {
            $name = trim((string) ($supplierNamesById[(int) $supplierId] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }
        $orderSupplierNames[$orderId] = array_values(array_unique($names));
    }

    return [
        'lines' => $lines,
        'order_suppliers' => array_map(
            static fn(array $supplierMap): array => array_map('intval', array_keys($supplierMap)),
            $orderSuppliers
        ),
        'order_supplier_names' => $orderSupplierNames,
    ];
}

return function (string $method, ?string $id, ?string $action, array $input) {
    require_once __DIR__ . '/../authorization.php';
    clmsAuthorizeApiRequest('financials', $method, $id, $action);
    $pdo = getDb();
    if (!getAuthUserId()) jsonError('Unauthorized', 401);
    requirePageAccess('financials');
    if ($method !== 'GET') requireRole(['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin']);

    if ($method !== 'GET') jsonError('Method not allowed', 405);

    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo = $_GET['date_to'] ?? null;
    $customerId = $_GET['customer_id'] ?? null;
    $supplierId = $_GET['supplier_id'] ?? null;
    $statusParam = $_GET['status'] ?? null;
    $statuses = is_array($statusParam) ? array_values(array_filter($statusParam)) : ($statusParam ? [$statusParam] : []);
    $statusMode = strtolower(trim((string) ($_GET['status_mode'] ?? 'include')));
    $statusMode = $statusMode === 'exclude' ? 'exclude' : 'include';
    $defaultExcludedStatuses = ['Draft', 'CustomerDeclined', 'CustomerDeclinedAfterAutoConfirm'];
    $itemType = clmsNormalizeItemTypeFilter($_GET['item_type'] ?? null);

    if ($id === 'balances') {
        $customers = [];
        $chkDep = @$pdo->query("SHOW TABLES LIKE 'customer_deposits'");
        $hasDeposits = $chkDep && $chkDep->rowCount() > 0;
        $chkSell = @$pdo->query("SHOW COLUMNS FROM order_items LIKE 'sell_price'");
        $hasSell = $chkSell && $chkSell->rowCount() > 0;
        $custSql = "SELECT c.id, c.name, c.code FROM customers c WHERE 1=1";
        $custParams = [];
        if ($customerId) {
            $custSql .= " AND c.id = ?";
            $custParams[] = $customerId;
        }
        $stmt = $custParams ? $pdo->prepare($custSql) : $pdo->query($custSql);
        if ($custParams) {
            $stmt->execute($custParams);
        }
        $depositMap = [];
        if ($hasDeposits) {
            $depSql = "SELECT cd.customer_id, cd.currency, SUM(cd.amount) as total
                FROM customer_deposits cd
                WHERE 1=1";
            $depParams = [];
            if ($customerId) {
                $depSql .= " AND cd.customer_id = ?";
                $depParams[] = $customerId;
            }
            $depSql .= " GROUP BY cd.customer_id, cd.currency";
            $depStmt = $depParams ? $pdo->prepare($depSql) : $pdo->query($depSql);
            if ($depParams) {
                $depStmt->execute($depParams);
            }
            while ($dep = $depStmt->fetch(PDO::FETCH_ASSOC)) {
                $depositMap[(int) $dep['customer_id']][(string) ($dep['currency'] ?: 'USD')] = DecimalMath::normalize($dep['total']);
            }
        }
        $receivableExpr = $hasSell
            ? "SUM(CASE WHEN oi.sell_price IS NOT NULL THEN oi.quantity * oi.sell_price ELSE COALESCE(oi.total_amount, oi.quantity * COALESCE(oi.unit_price, 0)) END)"
            : "SUM(COALESCE(oi.total_amount, oi.quantity * COALESCE(oi.unit_price, 0)))";
        $receivableSql = "SELECT o.customer_id, o.currency, COALESCE($receivableExpr, 0) as total
            FROM orders o
            JOIN order_items oi ON oi.order_id = o.id
            WHERE o.status NOT IN ('Draft','CustomerDeclined','CustomerDeclinedAfterAutoConfirm')";
        $receivableParams = [];
        if ($customerId) {
            $receivableSql .= " AND o.customer_id = ?";
            $receivableParams[] = $customerId;
        }
        $receivableSql .= " GROUP BY o.customer_id, o.currency";
        $receivableStmt = $receivableParams ? $pdo->prepare($receivableSql) : $pdo->query($receivableSql);
        if ($receivableParams) {
            $receivableStmt->execute($receivableParams);
        }
        $receivableMap = [];
        while ($rr = $receivableStmt->fetch(PDO::FETCH_ASSOC)) {
            $receivableMap[(int) $rr['customer_id']][(string) ($rr['currency'] ?: 'USD')] = DecimalMath::normalize($rr['total']);
        }
        $pendingChargeMap=[];
        $hasShipmentLedger=(bool)$pdo->query("SHOW TABLES LIKE 'shipment_financial_entries'")->fetchColumn();
        if($hasShipmentLedger){
            $chargeSql="SELECT customer_id,base_currency,posting_state,CAST(COALESCE(SUM(base_amount),0) AS CHAR) total FROM shipment_financial_entries WHERE entry_role='customer_charge' AND archived_at IS NULL";
            $chargeParams=[];if($customerId){$chargeSql.=' AND customer_id=?';$chargeParams[]=$customerId;}$chargeSql.=' GROUP BY customer_id,base_currency,posting_state';
            $chargeStmt=$pdo->prepare($chargeSql);$chargeStmt->execute($chargeParams);
            foreach($chargeStmt->fetchAll(PDO::FETCH_ASSOC) as $charge){$cid=(int)$charge['customer_id'];$cur=(string)$charge['base_currency'];if($charge['posting_state']==='finalized')$receivableMap[$cid][$cur]=DecimalMath::add($receivableMap[$cid][$cur]??'0',$charge['total']);elseif($charge['posting_state']==='provisional')$pendingChargeMap[$cid][$cur]=DecimalMath::normalize($charge['total']);}
        }

        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $custId = $r['id'];
            $currencies = [];
            foreach (['USD', 'RMB'] as $currency) {
                $deposits = (string) ($depositMap[$custId][$currency] ?? '0.0000');
                $receivable = (string) ($receivableMap[$custId][$currency] ?? '0.0000');
                $currencies[$currency] = [
                    'deposits' => $deposits,
                    'receivable' => $receivable,
                    'balance' => DecimalMath::subtract($deposits, $receivable),
                    'pending_shipment_charges' => $pendingChargeMap[$custId][$currency] ?? '0.0000',
                ];
            }
            $customers[] = [
                'id' => $custId,
                'name' => $r['name'],
                'code' => $r['code'],
                'currencies' => $currencies,
            ];
        }
        $suppliers = [];
        $suppSql = "SELECT s.id, s.name, s.code FROM suppliers s";
        $suppParams = [];
        if ($supplierId) {
            $suppSql .= " WHERE s.id = ?";
            $suppParams[] = $supplierId;
        }
        $chkPay = @$pdo->query("SHOW TABLES LIKE 'supplier_payments'");
        $hasPayments = $chkPay && $chkPay->rowCount() > 0;
        $settlementExpr = financialSupplierSettlementExpr($pdo);
        if ($hasPayments) {
            $stmt = $suppParams ? $pdo->prepare($suppSql) : $pdo->query($suppSql);
            if ($suppParams) {
                $stmt->execute($suppParams);
            }
            $paymentSql = "SELECT supplier_id, currency, SUM(amount) as total_paid, SUM(COALESCE(invoice_amount, amount)) as total_invoiced, SUM($settlementExpr) as total_settlement FROM supplier_payments";
            $paymentParams = [];
            if ($supplierId) {
                $paymentSql .= " WHERE supplier_id = ?";
                $paymentParams[] = $supplierId;
            }
            $paymentSql .= " GROUP BY supplier_id, currency";
            $paymentStmt = $paymentParams ? $pdo->prepare($paymentSql) : $pdo->query($paymentSql);
            if ($paymentParams) {
                $paymentStmt->execute($paymentParams);
            }
            $paymentMap = [];
            while ($pay = $paymentStmt->fetch(PDO::FETCH_ASSOC)) {
                $paymentMap[(int) $pay['supplier_id']][(string) ($pay['currency'] ?: 'USD')] = [
                    'paid' => DecimalMath::normalize($pay['total_paid']),
                    'invoiced' => DecimalMath::normalize($pay['total_invoiced']),
                    'settlement_delta' => DecimalMath::normalize($pay['total_settlement']),
                ];
            }
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $supplierCurrencies = [];
                foreach (['USD', 'RMB'] as $currency) {
                    $paidVal = (string) ($paymentMap[(int) $r['id']][$currency]['paid'] ?? '0.0000');
                    $invVal = (string) ($paymentMap[(int) $r['id']][$currency]['invoiced'] ?? '0.0000');
                    $settled = (string) ($paymentMap[(int) $r['id']][$currency]['settlement_delta'] ?? '0.0000');
                    $supplierCurrencies[$currency] = [
                        'paid' => $paidVal,
                        'invoiced' => $invVal,
                        'settlement_delta' => $settled,
                        'payable' => DecimalMath::subtract(DecimalMath::subtract($invVal, $paidVal), $settled),
                    ];
                }
                $detailStmt = $pdo->prepare("SELECT payment_facility_days, payment_links FROM suppliers WHERE id = ?");
                $detailStmt->execute([$r['id']]);
                $supplierMeta = $detailStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $suppliers[] = [
                    'id' => $r['id'],
                    'name' => $r['name'],
                    'code' => $r['code'],
                    'currencies' => $supplierCurrencies,
                    'payment_facility_days' => isset($supplierMeta['payment_facility_days']) ? (int) $supplierMeta['payment_facility_days'] : null,
                    'payment_links' => !empty($supplierMeta['payment_links']) ? (json_decode((string) $supplierMeta['payment_links'], true) ?: []) : [],
                ];
            }
        } else {
            $stmt = $suppParams ? $pdo->prepare($suppSql) : $pdo->query($suppSql);
            if ($suppParams) {
                $stmt->execute($suppParams);
            }
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $suppliers[] = [
                    'id' => $r['id'],
                    'name' => $r['name'],
                    'code' => $r['code'],
                    'currencies' => [
                        'USD' => ['paid' => '0.0000', 'invoiced' => '0.0000', 'settlement_delta' => '0.0000', 'payable' => '0.0000'],
                        'RMB' => ['paid' => '0.0000', 'invoiced' => '0.0000', 'settlement_delta' => '0.0000', 'payable' => '0.0000'],
                    ],
                    'payment_facility_days' => null,
                    'payment_links' => [],
                ];
            }
        }
        if ($itemType !== null && financialsTableHasColumn($pdo,'item_classifications','item_type_code')) {
            $sql .= " AND EXISTS (SELECT 1 FROM order_items fit JOIN item_classifications fic ON fic.entity_type='order_item' AND fic.entity_id=fit.id WHERE fit.order_id=o.id AND fic.item_type_code=?)";
            $params[]=$itemType;
        }
        jsonResponse(['data' => ['customers' => $customers, 'suppliers' => $suppliers]]);
    }

    if ($id === 'profit' || $id === null) {
        $chkSell = @$pdo->query("SHOW COLUMNS FROM order_items LIKE 'sell_price'");
        $hasSell = $chkSell && $chkSell->rowCount() > 0;
        $chkBuy = @$pdo->query("SHOW COLUMNS FROM order_items LIKE 'buy_price'");
        $hasBuy = $chkBuy && $chkBuy->rowCount() > 0;
        $chkItemSupp = @$pdo->query("SHOW COLUMNS FROM order_items LIKE 'supplier_id'");
        $hasItemSupplier = $chkItemSupp && $chkItemSupp->rowCount() > 0;
        $chkComm = @$pdo->query("SHOW COLUMNS FROM suppliers LIKE 'commission_rate'");
        $hasCommission = $chkComm && $chkComm->rowCount() > 0;
        $hasSharedCartons = financialsSupportsSharedCartons($pdo);

        $sellExpr = $hasSell
            ? "(SELECT SUM(CASE WHEN oi.sell_price IS NOT NULL THEN oi.quantity * oi.sell_price ELSE COALESCE(oi.total_amount, oi.quantity * COALESCE(oi.unit_price, 0)) END) FROM order_items oi WHERE oi.order_id = o.id)"
            : "(SELECT SUM(COALESCE(oi.total_amount, oi.quantity * COALESCE(oi.unit_price, 0))) FROM order_items oi WHERE oi.order_id = o.id)";
        $buyExpr = $hasBuy
            ? "(SELECT SUM(oi.quantity * COALESCE(oi.buy_price, oi.unit_price)) FROM order_items oi WHERE oi.order_id = o.id)"
            : "(SELECT SUM(oi.quantity * COALESCE(oi.unit_price, 0)) FROM order_items oi WHERE oi.order_id = o.id)";

        $suppCols = 's.name as supplier_name';
        if ($hasCommission && !$hasItemSupplier) {
            $suppCols .= ', s.commission_rate, s.commission_type, s.commission_applied_on';
        }
        $sql = "SELECT o.id, o.customer_id, o.supplier_id, o.currency, o.status, o.expected_ready_date,
            c.name as customer_name, $suppCols,
            $sellExpr as order_total,
            $buyExpr as buy_total
            FROM orders o
            JOIN customers c ON o.customer_id = c.id
            LEFT JOIN suppliers s ON o.supplier_id = s.id
            WHERE 1=1";
        $params = [];
        if ($statuses) {
            if ($statusMode === 'exclude') {
                $excludedStatuses = array_values(array_unique(array_merge($defaultExcludedStatuses, $statuses)));
                $placeholders = implode(',', array_fill(0, count($excludedStatuses), '?'));
                $sql .= " AND o.status NOT IN ($placeholders)";
                $params = array_merge($params, $excludedStatuses);
            } else {
                $placeholders = implode(',', array_fill(0, count($statuses), '?'));
                $sql .= " AND o.status IN ($placeholders)";
                $params = array_merge($params, $statuses);
            }
        } else {
            $placeholders = implode(',', array_fill(0, count($defaultExcludedStatuses), '?'));
            $sql .= " AND o.status NOT IN ($placeholders)";
            $params = array_merge($params, $defaultExcludedStatuses);
        }
        if ($dateFrom) {
            $sql .= " AND o.expected_ready_date >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo) {
            $sql .= " AND o.expected_ready_date <= ?";
            $params[] = $dateTo;
        }
        if ($customerId) {
            $sql .= " AND o.customer_id = ?";
            $params[] = $customerId;
        }
        if ($supplierId) {
            if ($hasItemSupplier && !$hasSharedCartons) {
                $sql .= " AND (o.supplier_id = ? OR EXISTS (SELECT 1 FROM order_items oi LEFT JOIN products p ON oi.product_id = p.id WHERE oi.order_id = o.id AND COALESCE(oi.supplier_id, p.supplier_id) = ?))";
                $params[] = $supplierId;
                $params[] = $supplierId;
            } elseif (!$hasItemSupplier) {
                $sql .= " AND o.supplier_id = ?";
                $params[] = $supplierId;
            }
        }
        $sql .= " ORDER BY o.expected_ready_date DESC";
        $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
        if ($params) $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $shipmentTotals=[];
        if($rows && (bool)$pdo->query("SHOW TABLES LIKE 'shipment_financial_entries'")->fetchColumn()){
            $ids=array_map('intval',array_column($rows,'id'));$ph=implode(',',array_fill(0,count($ids),'?'));
            $shipmentStmt=$pdo->prepare("SELECT order_id,entry_role,CAST(COALESCE(SUM(base_amount),0) AS CHAR) total FROM shipment_financial_entries WHERE posting_state='finalized' AND archived_at IS NULL AND order_id IN ($ph) GROUP BY order_id,entry_role");$shipmentStmt->execute($ids);
            foreach($shipmentStmt->fetchAll(PDO::FETCH_ASSOC) as $entry)$shipmentTotals[(int)$entry['order_id']][(string)$entry['entry_role']]=DecimalMath::normalize($entry['total']);
        }

        $itemAnalysis = null;
        if (!empty($rows) && (($supplierId && $hasItemSupplier) || ($hasCommission && $hasItemSupplier))) {
            $itemAnalysis = financialsBuildOrderItemAnalysis($pdo, array_map('intval', array_column($rows, 'id')));
        }
        if ($supplierId && $hasItemSupplier && $itemAnalysis !== null) {
            $filterSupplierId = (int) $supplierId;
            $rows = array_values(array_filter($rows, static function (array $row) use ($itemAnalysis, $filterSupplierId): bool {
                $orderId = (int) ($row['id'] ?? 0);
                $orderSuppliers = $itemAnalysis['order_suppliers'][$orderId] ?? [];
                if (in_array($filterSupplierId, $orderSuppliers, true)) {
                    return true;
                }
                return (int) ($row['supplier_id'] ?? 0) === $filterSupplierId;
            }));
        }

        $orderCommissions = [];
        if ($hasCommission && $hasItemSupplier && !empty($rows)) {
            if ($itemAnalysis === null) {
                $itemAnalysis = financialsBuildOrderItemAnalysis($pdo, array_map('intval', array_column($rows, 'id')));
            }
            $remainingOrderIds = array_fill_keys(array_map('intval', array_column($rows, 'id')), true);
            $supplierMeta = [];
            $supplierStmt = $pdo->query("SELECT id, commission_rate, commission_type, commission_applied_on FROM suppliers");
            foreach ($supplierStmt->fetchAll(PDO::FETCH_ASSOC) as $supplierRow) {
                $supplierMeta[(int) $supplierRow['id']] = $supplierRow;
            }
            foreach (($itemAnalysis['lines'] ?? []) as $it) {
                $oid = (int) ($it['order_id'] ?? 0);
                if (!isset($remainingOrderIds[$oid])) {
                    continue;
                }
                $sid = !empty($it['supplier_id']) ? (int) $it['supplier_id'] : null;
                $meta = $sid ? ($supplierMeta[$sid] ?? null) : null;
                $rate = DecimalMath::normalize($meta['commission_rate'] ?? '0',8);
                $type = $meta['commission_type'] ?? 'percentage';
                $appliedOn = $meta['commission_applied_on'] ?? 'buy_value';
                if (DecimalMath::compare($rate,'0',8)===0 || !$sid) continue;
                $buyVal = DecimalMath::normalize($it['buy_total'] ?? '0');
                $sellVal = DecimalMath::normalize($it['sell_total'] ?? '0');
                $base = $appliedOn === 'sell_value' ? $sellVal : $buyVal;
                if ($type === 'fixed') {
                    if (!isset($orderCommissions[$oid])) $orderCommissions[$oid] = ['pct' => '0.0000', 'fixed' => []];
                    if (!isset($orderCommissions[$oid]['fixed'][$sid])) $orderCommissions[$oid]['fixed'][$sid] = $rate;
                } else {
                    if (!isset($orderCommissions[$oid])) $orderCommissions[$oid] = ['pct' => '0.0000', 'fixed' => []];
                    $commission=DecimalMath::divide(DecimalMath::multiply($base,$rate,8),'100',4);
                    $orderCommissions[$oid]['pct']=DecimalMath::add($orderCommissions[$oid]['pct'],$commission);
                }
            }
        } elseif ($hasCommission && !empty($rows)) {
            foreach ($rows as $r) {
                $oid = (int) $r['id'];
                $rate = DecimalMath::normalize($r['commission_rate'] ?? '0',8);
                if (DecimalMath::compare($rate,'0',8)===0) continue;
                $type = $r['commission_type'] ?? 'percentage';
                $appliedOn = $r['commission_applied_on'] ?? 'buy_value';
                $base = DecimalMath::normalize($appliedOn === 'sell_value' ? ($r['order_total'] ?? '0') : ($r['buy_total'] ?? '0'));
                if ($type === 'fixed') {
                    $orderCommissions[$oid] = ['pct' => '0.0000', 'fixed' => [0 => $rate]];
                } else {
                    $orderCommissions[$oid] = ['pct' => DecimalMath::divide(DecimalMath::multiply($base,$rate,8),'100',4), 'fixed' => []];
                }
            }
        }

        $totalSell = '0.0000';
        $totalBuy = '0.0000';
        $totalCommission = '0.0000';
        $totalShipmentCharges='0.0000';
        $totalShipmentExpenses='0.0000';
        foreach ($rows as &$r) {
            $supplierNames = [];
            if ($itemAnalysis !== null) {
                $supplierNames = $itemAnalysis['order_supplier_names'][(int) ($r['id'] ?? 0)] ?? [];
            }
            $r['supplier_name_display'] = financialsBuildSupplierDisplay($supplierNames, (string) ($r['supplier_name'] ?? ''));
            if ($r['supplier_name_display'] !== '') {
                $r['supplier_name'] = $r['supplier_name_display'];
            }
            $r['order_total'] = DecimalMath::normalize($r['order_total'] ?? '0');
            $r['buy_total'] = DecimalMath::normalize($r['buy_total'] ?? $r['order_total']);
            $commission = '0.0000';
            $oid = (int) $r['id'];
            if (isset($orderCommissions[$oid])) {
                $commission = (string)($orderCommissions[$oid]['pct'] ?? '0.0000');
                if(isset($orderCommissions[$oid]['fixed']))$commission=DecimalMath::add($commission,DecimalMath::sum($orderCommissions[$oid]['fixed']));
            }
            $r['commission'] = DecimalMath::normalize($commission);
            $r['shipment_customer_charges']=$shipmentTotals[$oid]['customer_charge']??'0.0000';
            $r['shipment_operational_expenses']=$shipmentTotals[$oid]['shipment_expense']??'0.0000';
            $r['total_customer_charges']=DecimalMath::add($r['order_total'],$r['shipment_customer_charges']);
            $r['gross_result']=DecimalMath::subtract($r['total_customer_charges'],$r['buy_total']);
            $r['net_shipment_result']=DecimalMath::subtract($r['gross_result'],$r['shipment_operational_expenses']);
            $r['margin']=$r['net_shipment_result'];
            $totalSell = DecimalMath::add($totalSell,$r['order_total']);
            $totalBuy = DecimalMath::add($totalBuy,$r['buy_total']);
            $totalCommission = DecimalMath::add($totalCommission,$r['commission']);
            $totalShipmentCharges=DecimalMath::add($totalShipmentCharges,$r['shipment_customer_charges']);
            $totalShipmentExpenses=DecimalMath::add($totalShipmentExpenses,$r['shipment_operational_expenses']);
        }
        $expenseSql = "SELECT currency, SUM(amount) as total FROM expenses WHERE 1=1";
        $expParams = [];
        if ($dateFrom) {
            $expenseSql .= " AND expense_date >= ?";
            $expParams[] = $dateFrom;
        }
        if ($dateTo) {
            $expenseSql .= " AND expense_date <= ?";
            $expParams[] = $dateTo;
        }
        $expenseSql .= " GROUP BY currency";
        $expStmt = $expParams ? $pdo->prepare($expenseSql) : $pdo->query($expenseSql);
        if ($expParams) $expStmt->execute($expParams);
        $expenses = $expStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($expenses as &$expense)$expense['total']=DecimalMath::normalize($expense['total']??'0');
        unset($expense);
        $limit=clmsQueryLimit($_GET['limit']??null,50,200);$offset=clmsQueryOffset($_GET['offset']??null);$totalCount=count($rows);$pageRows=array_slice($rows,$offset,$limit);
        $totalCustomerCharges=DecimalMath::add($totalSell,$totalShipmentCharges);$grossResult=DecimalMath::subtract($totalCustomerCharges,$totalBuy);$netShipmentResult=DecimalMath::subtract($grossResult,$totalShipmentExpenses);
        jsonResponse(['data' => $pageRows, 'meta'=>['limit'=>$limit,'offset'=>$offset,'total_count'=>$totalCount,'has_more'=>$offset+$limit<$totalCount], 'summary' => ['total_sell' => $totalSell, 'shipment_customer_charges'=>$totalShipmentCharges,'total_customer_charges'=>$totalCustomerCharges,'total_buy' => $totalBuy,'shipment_operational_expenses'=>$totalShipmentExpenses, 'total_commission' => $totalCommission, 'gross_profit' => $grossResult, 'net_profit' => $netShipmentResult,'gross_result'=>$grossResult,'net_shipment_result'=>$netShipmentResult, 'expenses' => $expenses]]);
    }

    jsonError('Not found', 404);
};
