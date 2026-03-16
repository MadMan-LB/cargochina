<?php

/**
 * HS code tax rates and estimate API.
 * Internal-only module for lookup, maintenance, and planning estimates.
 */

require_once __DIR__ . '/../helpers.php';

function normalizeHsCodeValue(?string $value): string
{
    $value = strtoupper(trim((string) $value));
    $value = preg_replace('/\s+/', '', $value);
    return preg_replace('/[^A-Z0-9.\-]/', '', $value);
}

function normalizeCountryCodeValue(?string $value): string
{
    $value = strtoupper(trim((string) $value));
    $value = preg_replace('/[^A-Z]/', '', $value);
    return $value !== '' ? substr($value, 0, 10) : 'LB';
}

function normalizeRatePercentValue($value): float
{
    $rate = (float) $value;
    if ($rate < 0) {
        $rate = 0;
    }
    return round($rate, 4);
}

function getEstimateQuantity(array $row): float
{
    $quantity = isset($row['quantity']) ? (float) $row['quantity'] : 0.0;
    if ($quantity > 0) {
        return $quantity;
    }

    $cartons = isset($row['order_cartons']) ? (float) $row['order_cartons'] : (isset($row['cartons']) ? (float) $row['cartons'] : 0.0);
    $qtyPerCarton = isset($row['order_qty_per_carton']) ? (float) $row['order_qty_per_carton'] : (isset($row['qty_per_carton']) ? (float) $row['qty_per_carton'] : 0.0);
    if ($cartons > 0 && $qtyPerCarton > 0) {
        return $cartons * $qtyPerCarton;
    }

    return 1.0;
}

function resolveBasisValue(array $row, ?float $overrideValue, string $valuationMode): array
{
    $overrideValue = $overrideValue !== null && $overrideValue > 0 ? $overrideValue : null;
    if ($overrideValue !== null) {
        return ['value' => $overrideValue, 'source' => 'manual_declared_value'];
    }

    $quantity = getEstimateQuantity($row);
    $buildCandidates = function (array $fields) use ($row, $quantity): array {
        $candidates = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') {
                continue;
            }
            $rawValue = (float) $row[$field];
            if ($rawValue <= 0) {
                continue;
            }
            if ($field === 'total_amount') {
                $candidates[] = ['value' => $rawValue, 'source' => 'total_amount'];
                continue;
            }
            $candidates[] = [
                'value' => $rawValue * max($quantity, 1),
                'source' => $field . '_x_qty',
            ];
        }
        return $candidates;
    };

    $mode = in_array($valuationMode, ['buy', 'sell', 'auto'], true) ? $valuationMode : 'auto';
    if ($mode === 'sell') {
        $candidates = $buildCandidates(['sell_price', 'total_amount', 'unit_price', 'buy_price']);
    } else {
        $candidates = $buildCandidates(['buy_price', 'unit_price', 'total_amount', 'sell_price']);
    }

    foreach ($candidates as $candidate) {
        if ($candidate['value'] > 0) {
            return $candidate;
        }
    }

    return ['value' => 0.0, 'source' => 'no_value_found'];
}

function findApplicableHsRate(PDO $pdo, string $hsCode, string $countryCode): ?array
{
    if ($hsCode === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT *
         FROM hs_code_tax_rates
         WHERE country_code = ?
           AND ? LIKE CONCAT(hs_code, '%')
           AND (effective_from IS NULL OR effective_from <= CURDATE())
         ORDER BY CHAR_LENGTH(hs_code) DESC,
                  CASE WHEN effective_from IS NULL THEN 0 ELSE 1 END DESC,
                  effective_from DESC,
                  id DESC
         LIMIT 1"
    );
    $stmt->execute([$countryCode, $hsCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $row['match_type'] = $row['hs_code'] === $hsCode ? 'exact' : 'prefix';
    }
    return $row ?: null;
}

function buildHsEstimateLine(PDO $pdo, array $row, string $countryCode, string $referenceType, ?float $overrideValue, string $valuationMode): array
{
    $hsCode = normalizeHsCodeValue($row['hs_code'] ?? $row['product_hs_code'] ?? '');
    $rate = findApplicableHsRate($pdo, $hsCode, $countryCode);
    $basis = resolveBasisValue($row, $overrideValue, $valuationMode);
    $ratePercent = $rate ? (float) $rate['rate_percent'] : 0.0;
    $estimatedTax = round($basis['value'] * ($ratePercent / 100), 4);

    return [
        'reference_type' => $referenceType,
        'reference_id' => isset($row['id']) ? (int) $row['id'] : null,
        'order_id' => isset($row['order_id']) ? (int) $row['order_id'] : null,
        'product_id' => isset($row['product_id']) ? (int) $row['product_id'] : null,
        'description' => trim((string) ($row['description_en'] ?? $row['description_cn'] ?? $row['product_name'] ?? ('Record #' . ($row['id'] ?? '0')))),
        'hs_code' => $hsCode,
        'quantity' => getEstimateQuantity($row),
        'basis_value' => round((float) $basis['value'], 4),
        'basis_source' => $basis['source'],
        'matched_rate_id' => $rate ? (int) $rate['id'] : null,
        'matched_rate_hs_code' => $rate['hs_code'] ?? null,
        'matched_rate_percent' => round($ratePercent, 4),
        'rate_match_type' => $rate['match_type'] ?? null,
        'estimated_tax' => $estimatedTax,
        'rate_notes' => $rate['notes'] ?? null,
        'effective_from' => $rate['effective_from'] ?? null,
    ];
}

function summarizeEstimateLines(array $lines): array
{
    $summary = [
        'line_count' => count($lines),
        'matched_count' => 0,
        'unmatched_count' => 0,
        'basis_value_total' => 0.0,
        'estimated_tax_total' => 0.0,
    ];

    foreach ($lines as $line) {
        $summary['basis_value_total'] += (float) ($line['basis_value'] ?? 0);
        $summary['estimated_tax_total'] += (float) ($line['estimated_tax'] ?? 0);
        if (!empty($line['matched_rate_id'])) {
            $summary['matched_count']++;
        } else {
            $summary['unmatched_count']++;
        }
    }

    $summary['basis_value_total'] = round($summary['basis_value_total'], 4);
    $summary['estimated_tax_total'] = round($summary['estimated_tax_total'], 4);
    return $summary;
}

return function (string $method, ?string $id, ?string $action, array $input) {
    $pdo = getDb();

    $tableCheck = @$pdo->query("SHOW TABLES LIKE 'hs_code_tax_rates'");
    if (!$tableCheck || $tableCheck->rowCount() === 0) {
        jsonError('hs_code_tax_rates table not found. Run the foundation migrations first.', 500);
    }

    switch ($method) {
        case 'GET':
            if ($id === 'estimate') {
                $countryCode = normalizeCountryCodeValue($_GET['country_code'] ?? 'LB');
                $valuationMode = strtolower(trim((string) ($_GET['valuation_mode'] ?? 'auto')));
                $valuationMode = in_array($valuationMode, ['auto', 'buy', 'sell'], true) ? $valuationMode : 'auto';
                $declaredValue = isset($_GET['declared_value']) && $_GET['declared_value'] !== ''
                    ? max(0, (float) $_GET['declared_value'])
                    : null;
                $productId = !empty($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
                $orderId = !empty($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
                $containerId = !empty($_GET['container_id']) ? (int) $_GET['container_id'] : 0;
                $manualHsCode = normalizeHsCodeValue($_GET['hs_code'] ?? '');

                $contexts = array_filter([
                    $manualHsCode !== '' ? 'hs_code' : null,
                    $productId > 0 ? 'product' : null,
                    $orderId > 0 ? 'order' : null,
                    $containerId > 0 ? 'container' : null,
                ]);
                if (count($contexts) !== 1) {
                    jsonError('Provide exactly one estimate context: hs_code, product_id, order_id, or container_id.', 400);
                }

                $lines = [];
                $contextType = array_values($contexts)[0];
                if ($contextType === 'hs_code') {
                    $lines[] = buildHsEstimateLine(
                        $pdo,
                        [
                            'id' => 0,
                            'product_id' => null,
                            'description_en' => 'Manual HS code lookup',
                            'hs_code' => $manualHsCode,
                            'quantity' => 1,
                        ],
                        $countryCode,
                        'hs_code',
                        $declaredValue,
                        $valuationMode
                    );
                } elseif ($contextType === 'product') {
                    $stmt = $pdo->prepare("SELECT p.*, COALESCE(NULLIF(p.description_en, ''), NULLIF(p.description_cn, ''), CONCAT('Product #', p.id)) AS product_name FROM products p WHERE p.id = ?");
                    $stmt->execute([$productId]);
                    $product = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$product) {
                        jsonError('Product not found', 404);
                    }
                    $lines[] = buildHsEstimateLine($pdo, $product, $countryCode, 'product', $declaredValue, $valuationMode);
                } elseif ($contextType === 'order') {
                    $stmt = $pdo->prepare(
                        "SELECT oi.*, o.id AS order_id, p.hs_code AS product_hs_code
                         FROM order_items oi
                         JOIN orders o ON o.id = oi.order_id
                         LEFT JOIN products p ON p.id = oi.product_id
                         WHERE o.id = ?
                         ORDER BY oi.id"
                    );
                    $stmt->execute([$orderId]);
                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (empty($items)) {
                        jsonError('Order items not found', 404);
                    }
                    foreach ($items as $item) {
                        $lines[] = buildHsEstimateLine($pdo, $item, $countryCode, 'order_item', null, $valuationMode);
                    }
                } else {
                    $stmt = $pdo->prepare(
                        "SELECT oi.*, o.id AS order_id, p.hs_code AS product_hs_code
                         FROM order_items oi
                         JOIN orders o ON o.id = oi.order_id
                         LEFT JOIN products p ON p.id = oi.product_id
                         WHERE EXISTS (
                             SELECT 1
                             FROM shipment_draft_orders sdo
                             JOIN shipment_drafts sd ON sd.id = sdo.shipment_draft_id
                             WHERE sdo.order_id = o.id
                               AND sd.container_id = ?
                         )
                         ORDER BY o.id, oi.id"
                    );
                    $stmt->execute([$containerId]);
                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (empty($items)) {
                        jsonError('No order items found for this container', 404);
                    }
                    foreach ($items as $item) {
                        $lines[] = buildHsEstimateLine($pdo, $item, $countryCode, 'container_item', null, $valuationMode);
                    }
                }

                jsonResponse([
                    'data' => [
                        'context_type' => $contextType,
                        'country_code' => $countryCode,
                        'valuation_mode' => $valuationMode,
                        'lines' => $lines,
                        'summary' => summarizeEstimateLines($lines),
                    ],
                ]);
            }

            if ($id === null) {
                $q = trim($_GET['q'] ?? '');
                $countryCode = normalizeCountryCodeValue($_GET['country_code'] ?? '');
                $limit = max(1, min(200, (int) ($_GET['limit'] ?? 100)));
                $sql = "SELECT * FROM hs_code_tax_rates WHERE 1=1";
                $params = [];
                $orderBy = " ORDER BY country_code ASC, CHAR_LENGTH(hs_code) DESC, hs_code ASC, effective_from DESC, id DESC LIMIT $limit";
                if ($countryCode !== 'LB' || isset($_GET['country_code'])) {
                    $sql .= " AND country_code = ?";
                    $params[] = $countryCode;
                }
                if ($q !== '') {
                    $normalizedSearch = normalizeHsCodeValue($q);
                    if ($normalizedSearch !== '' && preg_match('/^[0-9.\-\s]+$/', $q) === 1) {
                        $normalizedRateSql = "REPLACE(REPLACE(REPLACE(UPPER(hs_code), '.', ''), '-', ''), ' ', '')";
                        $sql .= " AND {$normalizedRateSql} LIKE ?";
                        $params[] = $normalizedSearch . '%';
                        $orderBy = " ORDER BY country_code ASC, CHAR_LENGTH({$normalizedRateSql}) ASC, hs_code ASC, effective_from DESC, id DESC LIMIT $limit";
                    } else {
                        $like = '%' . preg_replace('/\s+/', '%', $q) . '%';
                        $sql .= " AND (hs_code LIKE ? OR country_code LIKE ? OR notes LIKE ?)";
                        array_push($params, $like, $like, $like);
                    }
                }
                $sql .= $orderBy;
                $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
                if ($params) {
                    $stmt->execute($params);
                }
                jsonResponse(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            }

            $stmt = $pdo->prepare("SELECT * FROM hs_code_tax_rates WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                jsonError('Tax rate not found', 404);
            }
            jsonResponse(['data' => $row]);

        case 'POST':
            $hsCode = normalizeHsCodeValue($input['hs_code'] ?? '');
            $countryCode = normalizeCountryCodeValue($input['country_code'] ?? 'LB');
            $ratePercent = normalizeRatePercentValue($input['rate_percent'] ?? 0);
            $effectiveFrom = trim((string) ($input['effective_from'] ?? '')) ?: null;
            $notes = trim((string) ($input['notes'] ?? '')) ?: null;
            if ($hsCode === '') {
                jsonError('HS code is required', 400);
            }

            $stmt = $pdo->prepare(
                "INSERT INTO hs_code_tax_rates (hs_code, country_code, rate_percent, effective_from, notes)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$hsCode, $countryCode, $ratePercent, $effectiveFrom, $notes]);
            jsonResponse(['message' => 'Tax rate created', 'id' => (int) $pdo->lastInsertId()], 201);

        case 'PUT':
            if (!$id) {
                jsonError('ID is required', 400);
            }
            $hsCode = normalizeHsCodeValue($input['hs_code'] ?? '');
            $countryCode = normalizeCountryCodeValue($input['country_code'] ?? 'LB');
            $ratePercent = normalizeRatePercentValue($input['rate_percent'] ?? 0);
            $effectiveFrom = trim((string) ($input['effective_from'] ?? '')) ?: null;
            $notes = trim((string) ($input['notes'] ?? '')) ?: null;
            if ($hsCode === '') {
                jsonError('HS code is required', 400);
            }

            $stmt = $pdo->prepare(
                "UPDATE hs_code_tax_rates
                 SET hs_code = ?, country_code = ?, rate_percent = ?, effective_from = ?, notes = ?
                 WHERE id = ?"
            );
            $stmt->execute([$hsCode, $countryCode, $ratePercent, $effectiveFrom, $notes, $id]);
            jsonResponse(['message' => 'Tax rate updated']);

        case 'DELETE':
            if (!$id) {
                jsonError('ID is required', 400);
            }
            $stmt = $pdo->prepare("DELETE FROM hs_code_tax_rates WHERE id = ?");
            $stmt->execute([$id]);
            jsonResponse(['message' => 'Tax rate deleted']);
    }

    jsonError('Method not allowed', 405);
};
