<?php

/**
 * Customers API - GET list, GET one, POST create, PUT update, DELETE
 */

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__,2).'/services/CustomerWriteService.php';
require_once dirname(__DIR__,2).'/services/OperationReplayService.php';
require_once dirname(__DIR__,2).'/services/CustomerDepositService.php';
require_once dirname(__DIR__,2).'/services/CsvTableService.php';

function customerApiRow(PDO $pdo,array $row): array
{
    foreach(['contacts','addresses','payment_links'] as $field)$row[$field]=is_array($row[$field]??null)?$row[$field]:(json_decode($row[$field]??'[]',true)?:[]);
    $row['country_shipping']=loadCountryShipping($pdo,(int)$row['id']);$row['por']=loadCustomerPorValues($pdo,(int)$row['id']);
    $row['revision']=CustomerWriteService::revision($row,$row['country_shipping'],$row['por']);return $row;
}

function customerTableHas(PDO $pdo, string $table, string $column): bool
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

function customerUtf8LikeExpr(string $expr): string
{
    return clmsUtf8SearchExpr($expr);
}

function normalizeCustomerPriority(array $input): array
{
    $priorityLevel = trim((string) ($input['priority_level'] ?? 'normal'));
    if (!in_array($priorityLevel, ['normal', 'medium', 'high', 'critical'], true)) {
        $priorityLevel = 'normal';
    }
    $priorityNote = isset($input['priority_note']) ? trim((string) $input['priority_note']) : null;
    if ($priorityLevel === 'normal') {
        $priorityNote = $priorityNote ?: null;
    } elseif ($priorityNote === '') {
        jsonError('Priority note is required when priority is not normal', 400);
    }

    return [$priorityLevel, $priorityNote ?: null];
}

function findDuplicateCustomerShippingCode(PDO $pdo, ?string $shippingCode, int $excludeCustomerId = 0): ?array
{
    $shippingCode = trim((string) $shippingCode);
    if ($shippingCode === '') {
        return null;
    }

    $chk = @$pdo->query("SHOW COLUMNS FROM customers LIKE 'default_shipping_code'");
    if (!$chk || $chk->rowCount() === 0) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT id, code, name FROM customers WHERE default_shipping_code = ? AND id != ? LIMIT 1");
    $stmt->execute([$shippingCode, $excludeCustomerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;

    // Also check customer_country_shipping
    try {
        $stmt2 = $pdo->prepare("SELECT c.id, c.code, c.name FROM customer_country_shipping ccs JOIN customers c ON c.id = ccs.customer_id WHERE ccs.shipping_code = ? AND c.id != ? LIMIT 1");
        $stmt2->execute([$shippingCode, $excludeCustomerId]);
        $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);
        return $row2 ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function customerDuplicateShippingCodeMessage(PDO $pdo, string $shippingCode, array $duplicate): string
{
    $message = 'Shipping code "' . $shippingCode . '" already belongs to another customer.';
    if (clmsCanAccessCustomer($pdo, (int) ($duplicate['id'] ?? 0))) {
        $label = $duplicate['code'] ?: ('#' . $duplicate['id']);
        $message = 'Shipping code "' . $shippingCode . '" already belongs to customer ' . $label . ' (' . $duplicate['name'] . ')';
    }
    return $message;
}

function loadCountryShipping(PDO $pdo, int $customerId): array
{
    try {
        $stmt = $pdo->prepare("SELECT ccs.id, ccs.country_id, ccs.shipping_code, co.code as country_code, co.name as country_name FROM customer_country_shipping ccs JOIN countries co ON co.id = ccs.country_id WHERE ccs.customer_id = ? ORDER BY co.name");
        $stmt->execute([$customerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/** Enrich only this already-authorized page; preserve child ordering and revision inputs. */
function loadCustomerPageRelations(PDO $pdo, array $ids): array
{
    $shipping = $pors = [];
    foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 200) as $chunk) {
        $marks = implode(',', array_fill(0, count($chunk), '?'));
        try {
            $stmt = $pdo->prepare("SELECT ccs.customer_id, ccs.id, ccs.country_id, ccs.shipping_code, co.code as country_code, co.name as country_name FROM customer_country_shipping ccs JOIN countries co ON co.id=ccs.country_id WHERE ccs.customer_id IN ($marks) ORDER BY ccs.customer_id, co.name");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (int)$row['customer_id']; unset($row['customer_id']);
                $shipping[$id][] = $row;
            }
        } catch (Throwable $e) { /* Same optional-schema behavior as detail reads. */ }
        if (customerHasPorTable($pdo)) {
            try {
                $stmt = $pdo->prepare("SELECT customer_id, por_value FROM customer_pors WHERE customer_id IN ($marks) ORDER BY customer_id, sort_order, id");
                $stmt->execute($chunk);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $value = trim((string)$row['por_value']);
                    if ($value !== '') $pors[(int)$row['customer_id']][] = $value;
                }
            } catch (Throwable $e) { /* Same optional-schema behavior as detail reads. */ }
        }
    }
    return [$shipping, $pors];
}

function customerLookupRoles(): array
{
    return ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'ContainersStaff', 'FieldStaff', 'SuperAdmin'];
}

function customerCreateRoles(): array
{
    return customerLookupRoles();
}

function customerLookupSelectColumns(PDO $pdo): string
{
    $cols = ['id', 'code', 'name'];
    if (customerTableHas($pdo, 'customers', 'default_shipping_code')) {
        $cols[] = 'default_shipping_code';
    }

    return implode(', ', $cols);
}

function customerLookupRow(PDO $pdo, int $customerId): ?array
{
    $stmt = $pdo->prepare("SELECT " . customerLookupSelectColumns($pdo) . " FROM customers WHERE id = ? LIMIT 1");
    $stmt->execute([$customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $row['country_shipping'] = loadCountryShipping($pdo, $customerId);
    return $row;
}

function customerHasPorTable(PDO $pdo): bool
{
    static $hasTable = null;
    if ($hasTable !== null) {
        return $hasTable;
    }
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'customer_pors'");
        $hasTable = (bool) ($stmt && $stmt->rowCount() > 0);
    } catch (Throwable $e) {
        $hasTable = false;
    }

    return $hasTable;
}

function normalizeCustomerPorInput($input): array
{
    $values = [];
    if (is_array($input)) {
        $values = $input;
    } elseif (is_string($input)) {
        $values = [$input];
    }

    $normalized = [];
    foreach ($values as $value) {
        $por = trim((string) $value);
        if ($por === '') {
            continue;
        }
        if (mb_strlen($por) > 120) {
            jsonError('Each por value must be 120 characters or fewer', 400);
        }
        $normalized[] = $por;
    }

    return array_values(array_unique($normalized));
}

function loadCustomerPorValues(PDO $pdo, int $customerId): array
{
    if (!customerHasPorTable($pdo)) {
        return [];
    }

    try {
        $stmt = $pdo->prepare("SELECT por_value FROM customer_pors WHERE customer_id = ? ORDER BY sort_order, id");
        $stmt->execute([$customerId]);
        return array_values(array_filter(array_map(static function ($row) {
            return trim((string) ($row['por_value'] ?? ''));
        }, $stmt->fetchAll(PDO::FETCH_ASSOC)), static fn($value) => $value !== ''));
    } catch (Throwable $e) {
        return [];
    }
}

function persistCustomerPorValues(PDO $pdo, int $customerId, array $porValues): void
{
    if (!customerHasPorTable($pdo)) {
        return;
    }

    $pdo->prepare("DELETE FROM customer_pors WHERE customer_id = ?")->execute([$customerId]);
    if (empty($porValues)) {
        return;
    }

    $stmt = $pdo->prepare("INSERT INTO customer_pors (customer_id, por_value, sort_order) VALUES (?, ?, ?)");
    foreach (array_values($porValues) as $index => $por) {
        $stmt->execute([$customerId, $por, $index]);
    }
}

function persistCountryShipping(PDO $pdo, int $customerId, array $countryShipping): void
{
    $pdo->prepare("DELETE FROM customer_country_shipping WHERE customer_id = ?")->execute([$customerId]);
    if (empty($countryShipping)) {
        return;
    }

    $ins = $pdo->prepare("INSERT INTO customer_country_shipping (customer_id, country_id, shipping_code) VALUES (?, ?, ?)");
    foreach ($countryShipping as $cs) {
        $cid = (int) ($cs['country_id'] ?? 0);
        $sc = trim((string) ($cs['shipping_code'] ?? '')) ?: null;
        if ($cid > 0) {
            $ins->execute([$customerId, $cid, $sc]);
        }
    }
}

function generateCustomerCode(PDO $pdo, string $name, ?string $defaultShippingCode, array $countryShipping): string
{
    $candidates = [];
    if ($defaultShippingCode && trim($defaultShippingCode) !== '') {
        $candidates[] = trim($defaultShippingCode);
    }
    foreach ($countryShipping as $cs) {
        $sc = trim($cs['shipping_code'] ?? '');
        if ($sc !== '' && !in_array($sc, $candidates, true)) {
            $candidates[] = $sc;
        }
    }
    if (!empty($candidates)) {
        $base=mb_substr($candidates[0],0,50);$code=$base;$suffix=1;
        while(true){$s=$pdo->prepare('SELECT id FROM customers WHERE code=?');$s->execute([$code]);if(!$s->fetchColumn())return $code;$suffix++;$tail='-'.$suffix;$code=mb_substr($base,0,50-strlen($tail)).$tail;}
    }
    $slug = preg_replace('/[^a-zA-Z0-9]+/', '_', substr(trim($name), 0, 30));
    $slug = $slug ?: 'cust';
    $base = $slug . '_' . bin2hex(random_bytes(2));
    $attempt = 0;
    while ($attempt < 10) {
        $stmt = $pdo->prepare("SELECT 1 FROM customers WHERE code = ? LIMIT 1");
        $stmt->execute([$base]);
        if (!$stmt->fetch()) return $base;
        $base = $slug . '_' . bin2hex(random_bytes(2));
        $attempt++;
    }
    return $slug . '_' . time();
}

return function (string $method, ?string $id, ?string $action, array $input) {
    require_once __DIR__ . '/../authorization.php';
    clmsAuthorizeApiRequest('customers', $method, $id, $action);
    $pdo = getDb();
    if ($method === 'GET') { require_once dirname(__DIR__, 2) . '/services/QueryFilterService.php'; QueryFilterService::validate($_GET, 'customers'); }
    if($method==='GET'){
        requirePermission(($id==='lookup'||$action==='lookup')?'customers.lookup':'customers.read',customerLookupRoles());
        if(in_array($action,['deposits','balance'],true))requirePermission('customers.finance');
        foreach(['q','shipping_code'] as $field)if(isset($_GET[$field])&&!is_string($_GET[$field]))jsonError("Invalid $field",422);
        foreach(['limit'=>1,'offset'=>0] as $field=>$minimum)if(isset($_GET[$field])&&(filter_var($_GET[$field],FILTER_VALIDATE_INT)===false||(int)$_GET[$field]<$minimum))jsonError("Invalid $field",422);
    }elseif(in_array($method,['PUT','DELETE'],true))requirePermission('customers.write');

    switch ($method) {
        case 'GET':
            if ($id === 'lookup') {
                $q = clmsNormalizeSearchQuery($_GET['q'] ?? '');
                if (strlen($q) < 1) {
                    jsonResponse(['data' => []]);
                }

                $like = clmsSearchLike($q);
                $where = '(' . customerUtf8LikeExpr('name') . ' LIKE ?) OR (' . customerUtf8LikeExpr('code') . ' LIKE ?)';
                $params = [$like, $like];
                if (customerTableHas($pdo, 'customers', 'default_shipping_code')) {
                    $where .= ' OR (' . customerUtf8LikeExpr('default_shipping_code') . ' LIKE ?)';
                    $params[] = $like;
                }
                if (customerHasPorTable($pdo)) {
                    $where .= " OR EXISTS (SELECT 1 FROM customer_pors cp WHERE cp.customer_id = customers.id AND " . customerUtf8LikeExpr('cp.por_value') . " LIKE ?)";
                    $params[] = $like;
                }

                $limit = clmsQueryLimit($_GET['limit'] ?? null, 20, 50);
                $stmt = $pdo->prepare("SELECT " . customerLookupSelectColumns($pdo) . " FROM customers WHERE ($where) ORDER BY name LIMIT " . $limit);
                $stmt->execute($params);
                jsonResponse(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            }
            if ($id === 'search') {
                $q = clmsNormalizeSearchQuery($_GET['q'] ?? '');
                if (strlen($q) < 1) {
                    jsonResponse(['data' => []]);
                }
                $like = clmsSearchLike($q);
                $cols = ['id', 'code', 'name'];
                $hasDefaultShippingCode = customerTableHas($pdo, 'customers', 'default_shipping_code');
                if ($hasDefaultShippingCode) {
                    $cols[] = 'default_shipping_code';
                }
                $hasPhone = false;
                $hasEmail = false;
                try {
                    $chk = $pdo->query("SHOW COLUMNS FROM customers WHERE Field IN ('phone','email')");
                    if ($chk) {
                        while ($r = $chk->fetch(PDO::FETCH_ASSOC)) {
                            if ($r['Field'] === 'phone') $hasPhone = true;
                            if ($r['Field'] === 'email') $hasEmail = true;
                        }
                    }
                } catch (Throwable $e) {
                }
                $sel = implode(', ', $cols);
                $where = '(' . customerUtf8LikeExpr('name') . ' LIKE ?) OR (' . customerUtf8LikeExpr('code') . ' LIKE ?)';
                $params = [$like, $like];
                if ($hasDefaultShippingCode) {
                    $where .= ' OR (' . customerUtf8LikeExpr('default_shipping_code') . ' LIKE ?)';
                    $params[] = $like;
                }
                if (customerHasPorTable($pdo)) {
                    $where .= " OR EXISTS (SELECT 1 FROM customer_pors cp WHERE cp.customer_id = customers.id AND " . customerUtf8LikeExpr('cp.por_value') . " LIKE ?)";
                    $params[] = $like;
                }
                if ($hasPhone) { $where .= " OR (" . customerUtf8LikeExpr('phone') . " LIKE ?)"; $params[] = $like; }
                if ($hasEmail) { $where .= " OR (" . customerUtf8LikeExpr('email') . " LIKE ?)"; $params[] = $like; }
                $scope = clmsCustomerVisibilityClause($pdo, 'customers');
                $limit = clmsQueryLimit($_GET['limit'] ?? null, 20, 50);
                $stmt = $pdo->prepare("SELECT $sel FROM customers WHERE ($where) AND {$scope['sql']} ORDER BY name LIMIT " . $limit);
                $stmt->execute(array_merge($params, $scope['params']));
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as &$row) {
                    $row['por'] = loadCustomerPorValues($pdo, (int) ($row['id'] ?? 0));
                }
                unset($row);
                jsonResponse(['data' => $rows]);
            }
            if ($id === null) {
                $q = clmsNormalizeSearchQuery($_GET['q'] ?? '');
                $scope = clmsCustomerVisibilityClause($pdo, 'customers');
                $sql = "SELECT * FROM customers";
                $params = [];
                if (strlen($q) >= 1) {
                    $like = clmsSearchLike($q);
                    $hasPhone = false;
                    $hasEmail = false;
                    $hasDefaultShippingCode = false;
                    try {
                        $colChk = $pdo->query("SHOW COLUMNS FROM customers WHERE Field IN ('phone','email','default_shipping_code')");
                        if ($colChk) {
                            while ($r = $colChk->fetch(PDO::FETCH_ASSOC)) {
                                if ($r['Field'] === 'phone') $hasPhone = true;
                                if ($r['Field'] === 'email') $hasEmail = true;
                                if ($r['Field'] === 'default_shipping_code') $hasDefaultShippingCode = true;
                            }
                        }
                    } catch (Throwable $e) {
                    }
                    $sql .= " WHERE ((" . customerUtf8LikeExpr('name') . " LIKE ?) OR (" . customerUtf8LikeExpr('code') . " LIKE ?)";
                    $params = [$like, $like];
                    if ($hasPhone) { $sql .= " OR (" . customerUtf8LikeExpr('phone') . " LIKE ?)"; $params[] = $like; }
                    if ($hasEmail) { $sql .= " OR (" . customerUtf8LikeExpr('email') . " LIKE ?)"; $params[] = $like; }
                    if ($hasDefaultShippingCode) { $sql .= " OR (" . customerUtf8LikeExpr('default_shipping_code') . " LIKE ?)"; $params[] = $like; }
                    if (customerHasPorTable($pdo)) {
                        $sql .= " OR EXISTS (SELECT 1 FROM customer_pors cp WHERE cp.customer_id = customers.id AND " . customerUtf8LikeExpr('cp.por_value') . " LIKE ?)";
                        $params[] = $like;
                    }
                    $sql .= ") AND {$scope['sql']}";
                    $params = array_merge($params, $scope['params']);
                } else {
                    $sql .= " WHERE {$scope['sql']}";
                    $params = $scope['params'];
                }
                $limit = clmsQueryLimit($_GET['limit'] ?? null, 50, 100);
                $offset = clmsQueryOffset($_GET['offset'] ?? null);
                $countStmt = $params ? $pdo->prepare("SELECT COUNT(*) FROM ($sql) customers_filtered") : $pdo->query("SELECT COUNT(*) FROM ($sql) customers_filtered");
                if ($params) $countStmt->execute($params);
                $total = (int) $countStmt->fetchColumn();
                $sql .= " ORDER BY name LIMIT " . ($limit + 1) . " OFFSET " . $offset;
                $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
                if ($params) $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $hasMore = count($rows) > $limit;
                if ($hasMore) $rows = array_slice($rows, 0, $limit);
                [$shipping, $pors] = loadCustomerPageRelations($pdo, array_column($rows, 'id'));
                foreach ($rows as &$r) {
                    $r['contacts'] = $r['contacts'] ? json_decode($r['contacts'], true) : [];
                    $r['addresses'] = $r['addresses'] ? json_decode($r['addresses'], true) : [];
                    $r['payment_links'] = isset($r['payment_links']) && $r['payment_links'] ? json_decode($r['payment_links'], true) : [];
                    $r['country_shipping'] = $shipping[(int)$r['id']] ?? [];
                    $r['por'] = $pors[(int)$r['id']] ?? [];
                    $r['revision']=CustomerWriteService::revision($r,$r['country_shipping'],$r['por']);
                }
                jsonResponse(['data' => $rows, 'meta' => ['limit' => $limit, 'offset' => $offset, 'has_more' => $hasMore, 'total' => $total]]);
            }
            if ($action === 'lookup') {
                $row = customerLookupRow($pdo, (int) $id);
                if (!$row) {
                    jsonError('Customer not found', 404);
                }
                jsonResponse(['data' => $row]);
            }
            clmsRequireCustomerAccess($pdo, (int) $id);
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                jsonError('Customer not found', 404);
            }
            $row['contacts'] = $row['contacts'] ? json_decode($row['contacts'], true) : [];
            $row['addresses'] = $row['addresses'] ? json_decode($row['addresses'], true) : [];
            $row['payment_links'] = isset($row['payment_links']) && $row['payment_links'] ? json_decode($row['payment_links'], true) : [];
            $row['country_shipping'] = loadCountryShipping($pdo, (int) $id);
            $row['por'] = loadCustomerPorValues($pdo, (int) $id);
            $row['revision']=CustomerWriteService::revision($row,$row['country_shipping'],$row['por']);
            if ($action === 'next-item-no') {
                $shippingCode = trim($_GET['shipping_code'] ?? '');
                if ($shippingCode === '') {
                    jsonError('shipping_code required', 400);
                }
                require_once dirname(__DIR__,2).'/services/OrderItemNumberingService.php';
                $supplierId=OrderWriteService::number($_GET['supplier_id']??null,'Supplier',true,0,4294967295);
                $items=OrderItemNumberingService::assignItemNumbers([['shipping_code'=>$shippingCode,'supplier_id'=>$supplierId]],$shippingCode,$supplierId,OrderItemNumberingService::fetchNumberingHistory($pdo,(int)$id));
                jsonResponse(['data'=>['next'=>$items[0]['item_no'],'preview_only'=>true]]);
            }
            if ($action === 'deposits') {
                $stmt2 = $pdo->prepare("SELECT * FROM customer_deposits WHERE customer_id = ? ORDER BY created_at DESC");
                $stmt2->execute([$id]);
                $row['deposits'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                jsonResponse(['data' => $row]);
            }
            if ($action === 'balance') {
                $stmt2 = $pdo->prepare("SELECT currency, SUM(amount) as total FROM customer_deposits WHERE customer_id = ? GROUP BY currency");
                $stmt2->execute([$id]);
                $bals = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                $balance = [];
                foreach ($bals as $b) $balance[$b['currency']] = (float) $b['total'];
                jsonResponse(['data' => $balance]);
            }
            jsonResponse(['data' => $row]);

        case 'POST':
            if ($id === 'import') {
                requirePermission('customers.import', ['ChinaAdmin', 'SuperAdmin']);
                if(!is_string($input['csv']??$input['data']??null))jsonError('CSV data must be text',422);
                $csv = trim($input['csv'] ?? $input['data'] ?? '');
                if (!$csv) jsonError('No CSV data provided', 400);
                if(strlen($csv)>2097152)jsonError('Customer CSV exceeds 2 MB',422);
                $claim=OperationReplayService::claim($pdo,'customer_import',$input,(int)getAuthUserId());
                if($claim['previous_data']!==null)jsonResponse(['data'=>$claim['previous_data']['result'],'idempotent_replay'=>true]);
                CustomerWriteService::lockNamespace($pdo);$pdo->beginTransaction();register_shutdown_function(static function()use($pdo){if($pdo->inTransaction())$pdo->rollBack();});
                try{$lines=CsvTableService::parse($csv);}catch(InvalidArgumentException $e){jsonError($e->getMessage(),422);}
                $header=array_map('trim',array_shift($lines)??[]);
                $hLower = array_map('strtolower', $header);
                if(!in_array('name',$hLower,true)||count(array_unique($hLower))!==count($hLower))jsonError('CSV requires a name column and unique headers',422);
                $codeIdx = array_search('code', $hLower) !== false ? array_search('code', $hLower) : null;
                $shipIdx = array_search('default_shipping_code', $hLower) !== false ? array_search('default_shipping_code', $hLower) : null;
                $nameIdx = array_search('name', $hLower) !== false ? array_search('name', $hLower) : 1;
                $phoneIdx = array_search('phone', $hLower) !== false ? array_search('phone', $hLower) : null;
                $emailIdx = array_search('email', $hLower) !== false ? array_search('email', $hLower) : null;
                $addressIdx = array_search('address', $hLower) !== false ? array_search('address', $hLower) : null;
                $termsIdx = array_search('payment_terms', $hLower) !== false ? array_search('payment_terms', $hLower) : null;
                $created = 0;
                $skipped = 0;
                $errors = [];
                $warnings=[];$createdIds=[];
                $hasPhone = (bool) @$pdo->query("SHOW COLUMNS FROM customers LIKE 'phone'")->rowCount();
                $hasAddress = (bool) @$pdo->query("SHOW COLUMNS FROM customers LIKE 'address'")->rowCount();
                $hasPaymentLinks = (bool) @$pdo->query("SHOW COLUMNS FROM customers LIKE 'payment_links'")->rowCount();
                $hasEmail = (bool) @$pdo->query("SHOW COLUMNS FROM customers LIKE 'email'")->rowCount();
                $hasDefaultShippingCode = (bool) @$pdo->query("SHOW COLUMNS FROM customers LIKE 'default_shipping_code'")->rowCount();
                $hasCreatedBy = customerTableHas($pdo, 'customers', 'created_by');
                $importUserId = getAuthUserId() ?? 1;
                foreach ($lines as $i => $line) {
                    $row = $line;
                    if(count($row)!==count($header))jsonError('CSV row '.($i+2).' does not match its headers',422);
                    $code = $codeIdx !== null ? trim($row[$codeIdx] ?? '') : '';
                    $defaultShippingCode = $shipIdx !== null ? trim($row[$shipIdx] ?? '') : '';
                    $name = trim($row[$nameIdx] ?? $row[1] ?? '');
                    if (!$name) {
                        jsonError('CSV row '.($i+2).' requires a customer name',422);
                    }
                    $code = $code ?: ($defaultShippingCode ?: null);
                    if (!$code) {
                        $code = preg_replace('/[^a-zA-Z0-9]+/', '_', substr($name, 0, 30)) ?: 'cust';
                        $code = $code . '_' . bin2hex(random_bytes(2));
                    }
                    $phone = $phoneIdx !== null && isset($row[$phoneIdx]) ? trim($row[$phoneIdx]) : null;
                    $email = $hasEmail && $emailIdx !== null && isset($row[$emailIdx]) ? trim($row[$emailIdx]) : null;
                    $address = $addressIdx !== null && isset($row[$addressIdx]) ? trim($row[$addressIdx]) : null;
                    $paymentTerms = $termsIdx !== null && isset($row[$termsIdx]) ? trim($row[$termsIdx]) : null;
                    CustomerWriteService::normalize($pdo,['code'=>$code,'name'=>$name,'default_shipping_code'=>$defaultShippingCode,'phone'=>$phone,'email'=>$email,'address'=>$address,'payment_terms'=>$paymentTerms]);
                    $existingCode=$pdo->prepare('SELECT id FROM customers WHERE code=?');$existingCode->execute([$code]);if($existingCode->fetchColumn()){$skipped++;continue;}
                    if($defaultShippingCode!==''&&($duplicate=findDuplicateCustomerShippingCode($pdo,$defaultShippingCode))){$message=customerDuplicateShippingCodeMessage($pdo,$defaultShippingCode,$duplicate);if(getBusinessSetting($pdo,'SHIPPING_CODE_DUPLICATE_ACTION','warn')==='block')jsonError($message,409);$warnings[]=$message;}
                    try {
                        $cols = ['code', 'name', 'contacts', 'addresses', 'payment_terms'];
                        $vals = [$code, $name, null, null, $paymentTerms];
                        if ($hasPaymentLinks) {
                            $cols[] = 'payment_links';
                            $vals[] = null;
                        }
                        if ($hasPhone) {
                            $cols[] = 'phone';
                            $vals[] = $phone;
                        }
                        if ($hasAddress) {
                            $cols[] = 'address';
                            $vals[] = $address;
                        }
                        if ($hasDefaultShippingCode) {
                            $cols[] = 'default_shipping_code';
                            $vals[] = $defaultShippingCode ?: null;
                        }
                        if ($hasEmail) {
                            $cols[] = 'email';
                            $vals[] = $email;
                        }
                        if ($hasCreatedBy) {
                            $cols[] = 'created_by';
                            $vals[] = $importUserId;
                        }
                        $ph = implode(',', array_fill(0, count($vals), '?'));
                        $pdo->prepare("INSERT INTO customers (" . implode(',', $cols) . ") VALUES ($ph)")->execute($vals);
                        $createdId=(int)$pdo->lastInsertId();$createdIds[]=$createdId;
                        $pdo->prepare("INSERT INTO audit_log(entity_type,entity_id,action,new_value,user_id) VALUES ('customer',?,'import_create',?,?)")->execute([$createdId,json_encode(['code'=>$code,'name'=>$name,'row'=>$i+2]),$importUserId]);
                        $created++;
                    } catch (PDOException $e) {
                        $pdo->rollBack();throw $e;
                    }
                }
                $result=['created'=>$created,'skipped'=>$skipped,'errors'=>[],'warnings'=>$warnings];OperationReplayService::record($pdo,'customer_import',0,$claim,['result'=>$result,'customer_ids'=>$createdIds],$importUserId);$pdo->commit();
                jsonResponse(['data'=>$result]);
            }
            if ($id && $action === 'deposits') {
                requirePermission('customers.finance');
                clmsRequireCustomerAccess($pdo, (int) $id);
                $userId = getAuthUserId() ?? 1;
                $data=CustomerDepositService::normalize($input);
                $claim=OperationReplayService::claim($pdo,'customer_deposit',array_merge($input,['customer_id'=>(int)$id]),$userId);
                $newId=$claim['previous_id'];
                if(!$newId){
                    $pdo->beginTransaction();register_shutdown_function(static function()use($pdo){if($pdo->inTransaction())$pdo->rollBack();});
                    try{$newId=CustomerDepositService::insert($pdo,(int)$id,$data,$userId);OperationReplayService::record($pdo,'customer_deposit',$newId,$claim,$data+['customer_id'=>(int)$id],$userId);$pdo->commit();}
                    catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
                }
                $stmt = $pdo->prepare("SELECT * FROM customer_deposits WHERE id = ?");
                $stmt->execute([$newId]);
                jsonResponse(['data' => $stmt->fetch(PDO::FETCH_ASSOC)], 201);
            }
            requirePermission('customers.create', customerCreateRoles());
            if($id!==null)jsonError('Invalid customer action',400);
            $input=CustomerWriteService::normalize($pdo,$input);
            $claim=OperationReplayService::claim($pdo,'customer',$input,(int)getAuthUserId());
            if($claim['previous_id']){$s=$pdo->prepare('SELECT * FROM customers WHERE id=?');$s->execute([$claim['previous_id']]);$saved=$s->fetch(PDO::FETCH_ASSOC);if(!$saved)jsonError('Original customer was removed; use a new request',409);jsonResponse(['data'=>customerApiRow($pdo,$saved),'idempotent_replay'=>true]);}
            CustomerWriteService::lockNamespace($pdo);
            $name = trim($input['name'] ?? '');
            if (!$name) {
                jsonError('Missing required fields', 400, ['name' => 'Required']);
            }
            $countryShipping = is_array($input['country_shipping'] ?? null) ? $input['country_shipping'] : [];
            $porValues = normalizeCustomerPorInput($input['por'] ?? []);
            $defaultShippingCode = isset($input['default_shipping_code']) ? (trim((string) $input['default_shipping_code']) ?: null) : null;
            $code = trim($input['code'] ?? '');
            if ($code === '') {
                $code = generateCustomerCode($pdo, $name, $defaultShippingCode, $countryShipping);
            }
            $contacts = isset($input['contacts']) ? json_encode($input['contacts']) : null;
            $addresses = isset($input['addresses']) ? json_encode($input['addresses']) : null;
            $paymentTerms = $input['payment_terms'] ?? null;
            $paymentLinks = isset($input['payment_links']) && is_array($input['payment_links'])
                ? json_encode(array_values(array_map(function ($p) {
                    return ['name' => trim($p['name'] ?? ''), 'value' => trim($p['value'] ?? '')];
                }, array_filter($input['payment_links'], function ($p) {
                    return !empty(trim($p['name'] ?? ''));
                }))))
                : null;
            $phone = !empty($input['phone']) ? trim($input['phone']) : null;
            $address = !empty($input['address']) ? trim($input['address']) : null;
            $email = !empty($input['email']) ? trim($input['email']) : null;
            [$priorityLevel, $priorityNote] = normalizeCustomerPriority($input);
            $hasPhone = false;
            $hasAddress = false;
            $hasPriority = false;
            $hasPriorityNote = false;
            $hasDefaultShippingCode = false;
            $hasEmail = false;
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM customers WHERE Field IN ('phone','address','priority_level','priority_note','default_shipping_code','email')");
                $colsExist = $chk ? array_column($chk->fetchAll(PDO::FETCH_ASSOC), 'Field') : [];
                $hasPhone = in_array('phone', $colsExist, true);
                $hasAddress = in_array('address', $colsExist, true);
                $hasPriority = in_array('priority_level', $colsExist, true);
                $hasPriorityNote = in_array('priority_note', $colsExist, true);
                $hasDefaultShippingCode = in_array('default_shipping_code', $colsExist, true);
                $hasEmail = in_array('email', $colsExist, true);
            } catch (Throwable $e) {
            }
            $duplicateWarning = null;
            $allShippingCodes = array_filter(array_merge(
                $defaultShippingCode ? [$defaultShippingCode] : [],
                array_map(function ($cs) { return trim($cs['shipping_code'] ?? ''); }, $countryShipping)
            ));
            foreach ($allShippingCodes as $sc) {
                if ($sc === '') continue;
                $duplicate = findDuplicateCustomerShippingCode($pdo, $sc);
                if ($duplicate) {
                    $duplicateWarning = customerDuplicateShippingCodeMessage($pdo, $sc, $duplicate);
                    $duplicateAction = getBusinessSetting($pdo, 'SHIPPING_CODE_DUPLICATE_ACTION', 'warn');
                    if ($duplicateAction === 'block') {
                        jsonError($duplicateWarning, 409);
                    }
                    break;
                }
            }
            $cols = ['code', 'name', 'contacts', 'addresses', 'payment_terms'];
            $vals = [$code, $name, $contacts, $addresses, $paymentTerms];
            $hasPaymentLinks = false;
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM customers LIKE 'payment_links'");
                $hasPaymentLinks = $chk && $chk->rowCount() > 0;
            } catch (Throwable $e) {
            }
            if ($hasPaymentLinks) {
                $cols[] = 'payment_links';
                $vals[] = $paymentLinks;
            }
            if ($hasPhone) {
                $cols[] = 'phone';
                $vals[] = $phone;
            }
            if ($hasAddress) {
                $cols[] = 'address';
                $vals[] = $address;
            }
            if ($hasPriority) {
                $cols[] = 'priority_level';
                $vals[] = $priorityLevel;
            }
            if ($hasPriorityNote) {
                $cols[] = 'priority_note';
                $vals[] = $priorityNote;
            }
            if ($hasDefaultShippingCode) {
                $cols[] = 'default_shipping_code';
                $vals[] = $defaultShippingCode;
            }
            if ($hasEmail) {
                $cols[] = 'email';
                $vals[] = $email;
            }
            $actingUserId = getAuthUserId() ?? 1;
            if (customerTableHas($pdo, 'customers', 'created_by')) {
                $cols[] = 'created_by';
                $vals[] = $actingUserId;
            }
            $ph = implode(',', array_fill(0, count($vals), '?'));
            $colStr = implode(', ', $cols);
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO customers ($colStr) VALUES ($ph)");
                $stmt->execute($vals);
                $newId = (int) $pdo->lastInsertId();
                persistCountryShipping($pdo, $newId, $countryShipping);
                persistCustomerPorValues($pdo, $newId, $porValues);
                $usedSpecialAccess = !hasAnyRole(['SuperAdmin'])
                    && clmsUserHasPermissionOverride('customers.create', $actingUserId, $pdo);
                OperationReplayService::record($pdo,'customer',$newId,$claim,['name'=>$name,'code'=>$code,'special_access'=>$usedSpecialAccess],$actingUserId);
                logClms('customer_created', [
                    'customer_id' => $newId,
                    'user_id' => $actingUserId,
                    'special_access' => $usedSpecialAccess,
                ]);
                $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
                $stmt->execute([$newId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $pdo->commit();
                $row['contacts'] = $row['contacts'] ? json_decode($row['contacts'], true) : [];
                $row['addresses'] = $row['addresses'] ? json_decode($row['addresses'], true) : [];
                $row['payment_links'] = isset($row['payment_links']) && $row['payment_links'] ? json_decode($row['payment_links'], true) : [];
                $row['country_shipping'] = loadCountryShipping($pdo, $newId);
                $row['por'] = loadCustomerPorValues($pdo, $newId);
                $row['revision']=CustomerWriteService::revision($row,$row['country_shipping'],$row['por']);
                jsonResponse(array_filter(['data' => $row, 'warning' => $duplicateWarning]), 201);
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e->getCode() == 23000) {
                    jsonError('Customer code already exists', 409);
                }
                throw $e;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

        case 'PUT':
            if (!$id) {
                jsonError('ID required', 400);
            }
            clmsRequireCustomerAccess($pdo, (int) $id);
            CustomerWriteService::lockNamespace($pdo);$pdo->beginTransaction();register_shutdown_function(static function()use($pdo){if($pdo->inTransaction())$pdo->rollBack();});
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                jsonError('Customer not found', 404);
            }
            $existing=customerApiRow($pdo,$existing);
            if(!is_string($input['revision']??null)||!hash_equals($existing['revision'],$input['revision']))jsonError('Customer changed or revision is missing; reopen before saving',409);
            $input=CustomerWriteService::normalize($pdo,array_replace($existing,$input));
            $code = trim($input['code'] ?? '');
            if ($code === '') {
                $code = $existing['code'];
            }
            $name = trim($input['name'] ?? '');
            if (!$name) {
                jsonError('Missing required fields', 400);
            }
            $contacts = isset($input['contacts']) ? json_encode($input['contacts']) : null;
            $addresses = isset($input['addresses']) ? json_encode($input['addresses']) : null;
            $paymentTerms = $input['payment_terms'] ?? null;
            $porValues = normalizeCustomerPorInput($input['por'] ?? []);
            $paymentLinks = isset($input['payment_links']) && is_array($input['payment_links'])
                ? json_encode(array_values(array_map(function ($p) {
                    return ['name' => trim($p['name'] ?? ''), 'value' => trim($p['value'] ?? '')];
                }, array_filter($input['payment_links'], function ($p) {
                    return !empty(trim($p['name'] ?? ''));
                }))))
                : null;
            $phone = isset($input['phone']) ? trim($input['phone']) : null;
            $address = isset($input['address']) ? trim($input['address']) : null;
            $email = array_key_exists('email', $input) ? (trim((string) ($input['email'] ?? '')) ?: null) : null;
            [$priorityLevel, $priorityNote] = normalizeCustomerPriority($input);
            $defaultShippingCode = array_key_exists('default_shipping_code', $input) ? (trim((string) $input['default_shipping_code']) ?: null) : null;
            $countryShipping = is_array($input['country_shipping'] ?? null) ? $input['country_shipping'] : null;
            $hasPhone = false;
            $hasAddress = false;
            $hasPriority = false;
            $hasPriorityNote = false;
            $hasDefaultShippingCode = false;
            $hasEmail = false;
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM customers WHERE Field IN ('phone','address','priority_level','priority_note','default_shipping_code','email')");
                while ($r = $chk->fetch(PDO::FETCH_ASSOC)) {
                    if ($r['Field'] === 'phone') $hasPhone = true;
                    if ($r['Field'] === 'address') $hasAddress = true;
                    if ($r['Field'] === 'priority_level') $hasPriority = true;
                    if ($r['Field'] === 'priority_note') $hasPriorityNote = true;
                    if ($r['Field'] === 'default_shipping_code') $hasDefaultShippingCode = true;
                    if ($r['Field'] === 'email') $hasEmail = true;
                }
            } catch (Throwable $e) {
            }
            $duplicateWarning = null;
            $allShippingCodes = array_filter(array_merge(
                $defaultShippingCode ? [$defaultShippingCode] : [],
                is_array($countryShipping) ? array_map(function ($cs) { return trim($cs['shipping_code'] ?? ''); }, $countryShipping) : []
            ));
            foreach ($allShippingCodes as $sc) {
                if ($sc === '') continue;
                $duplicate = findDuplicateCustomerShippingCode($pdo, $sc, (int) $id);
                if ($duplicate) {
                    $duplicateWarning = customerDuplicateShippingCodeMessage($pdo, $sc, $duplicate);
                    $duplicateAction = getBusinessSetting($pdo, 'SHIPPING_CODE_DUPLICATE_ACTION', 'warn');
                    if ($duplicateAction === 'block') {
                        jsonError($duplicateWarning, 409);
                    }
                    break;
                }
            }
            $sets = ['code=?', 'name=?', 'contacts=?', 'addresses=?', 'payment_terms=?'];
            $vals = [$code, $name, $contacts, $addresses, $paymentTerms];
            $hasPaymentLinks = false;
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM customers LIKE 'payment_links'");
                $hasPaymentLinks = $chk && $chk->rowCount() > 0;
            } catch (Throwable $e) {
            }
            if ($hasPaymentLinks) {
                $sets[] = 'payment_links=?';
                $vals[] = $paymentLinks;
            }
            if ($hasPhone) {
                $sets[] = 'phone=?';
                $vals[] = $phone;
            }
            if ($hasAddress) {
                $sets[] = 'address=?';
                $vals[] = $address;
            }
            if ($hasPriority) {
                $sets[] = 'priority_level=?';
                $vals[] = $priorityLevel;
            }
            if ($hasPriorityNote) {
                $sets[] = 'priority_note=?';
                $vals[] = $priorityNote;
            }
            if ($hasDefaultShippingCode) {
                $sets[] = 'default_shipping_code=?';
                $vals[] = $defaultShippingCode;
            }
            if ($hasEmail) {
                $sets[] = 'email=?';
                $vals[] = $email;
            }
            $vals[] = $id;
            try {
                $pdo->prepare("UPDATE customers SET " . implode(', ', $sets) . " WHERE id=?")->execute($vals);
                if (is_array($countryShipping)) {
                    persistCountryShipping($pdo, (int) $id, $countryShipping);
                }
                if (array_key_exists('por', $input)) {
                    persistCustomerPorValues($pdo, (int) $id, $porValues);
                }
                $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $pdo->prepare("INSERT INTO audit_log(entity_type,entity_id,action,old_value,new_value,user_id) VALUES ('customer',?,'update',?,?,?)")->execute([$id,json_encode($existing),json_encode($input),getAuthUserId()]);
                $pdo->commit();
                $row['contacts'] = $row['contacts'] ? json_decode($row['contacts'], true) : [];
                $row['addresses'] = $row['addresses'] ? json_decode($row['addresses'], true) : [];
                $row['payment_links'] = isset($row['payment_links']) && $row['payment_links'] ? json_decode($row['payment_links'], true) : [];
                $row['country_shipping'] = loadCountryShipping($pdo, (int) $id);
                $row['por'] = loadCustomerPorValues($pdo, (int) $id);
                $row['revision']=CustomerWriteService::revision($row,$row['country_shipping'],$row['por']);
                jsonResponse(array_filter(['data' => $row, 'warning' => $duplicateWarning]));
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

        case 'DELETE':
            if (!$id) {
                jsonError('ID required', 400);
            }
            clmsRequireCustomerAccess($pdo, (int) $id);
            CustomerWriteService::lockNamespace($pdo);$pdo->beginTransaction();register_shutdown_function(static function()use($pdo){if($pdo->inTransaction())$pdo->rollBack();});
            $s=$pdo->prepare('SELECT * FROM customers WHERE id=? FOR UPDATE');$s->execute([$id]);$old=$s->fetch(PDO::FETCH_ASSOC);if(!$old)jsonError('Customer not found',404);$old=customerApiRow($pdo,$old);
            if(!is_string($input['revision']??null)||!hash_equals($old['revision'],$input['revision']))jsonError('Customer changed or revision is missing; reopen before deleting',409);
            foreach(['orders','customer_deposits','expenses','customer_portal_tokens','internal_messages','item_number_reservations','shipment_financial_entries'] as $table){$s=$pdo->prepare("SELECT id FROM $table WHERE customer_id=? LIMIT 1 FOR UPDATE");$s->execute([$id]);if($s->fetchColumn())jsonError('Customer has operational or financial records and cannot be deleted',409);}
            $s=$pdo->prepare("SELECT id FROM design_attachments WHERE entity_type='customer' AND entity_id=? LIMIT 1 FOR UPDATE");$s->execute([$id]);if($s->fetchColumn())jsonError('Remove customer attachments before deleting the customer',409);
            $s=$pdo->prepare("SELECT id FROM balance_transactions WHERE party_type='customer' AND party_id=? LIMIT 1 FOR UPDATE");$s->execute([$id]);if($s->fetchColumn())jsonError('Customer has a financial history and cannot be deleted',409);
            $stmt = $pdo->prepare("DELETE FROM customers WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                jsonError('Customer not found', 404);
            }
            $pdo->prepare("INSERT INTO audit_log(entity_type,entity_id,action,old_value,user_id) VALUES ('customer',?,'delete',?,?)")->execute([$id,json_encode($old),getAuthUserId()]);$pdo->commit();
            jsonResponse(['message' => 'Deleted']);

        default:
            jsonError('Method not allowed', 405);
    }
};
