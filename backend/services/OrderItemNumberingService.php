<?php

require_once __DIR__ . '/OrderCountryService.php';

final class OrderItemNumberingService
{
    public static function isNumberingFrozenStatus(?string $status): bool
    {
        return trim((string) $status) !== '' && trim((string) $status) !== 'Draft';
    }

    public static function prepareItemsForPersistence(array $items, ?string $currentStatus, ?string $defaultShippingCode = null, ?int $defaultSupplierId = null, array $numberingHistory = []): array
    {
        if (self::isNumberingFrozenStatus($currentStatus)) {
            return $items;
        }

        return self::assignItemNumbers($items, $defaultShippingCode, $defaultSupplierId, $numberingHistory);
    }

    public static function assignItemNumbers(array $items, ?string $defaultShippingCode = null, ?int $defaultSupplierId = null, array $numberingHistory = []): array
    {
        $defaultShippingCode = OrderCountryService::normalizeShippingCode($defaultShippingCode);
        $history = self::buildNumberingHistoryState($numberingHistory);
        $supplierOrderByPrefix = [];
        $supplierSequences = [];
        $currentManualSupplierSequences = [];
        $currentUsedSupplierSequences = [];
        $currentManualItemCounts = [];
        $supplierItemCounts = [];
        $itemPrefixKeys = [];

        foreach ($items as $index => $item) {
            $supplierKey = self::buildSupplierKey($item, $defaultSupplierId);
            $parsed = self::parseItemNumber((string) ($item['item_no'] ?? ''));

            $shippingCode = OrderCountryService::normalizeShippingCode((string) ($item['shipping_code'] ?? '')) ?: $defaultShippingCode;
            if ($shippingCode === null && $parsed) {
                $shippingCode = OrderCountryService::normalizeShippingCode($parsed['prefix']);
            }
            $items[$index]['shipping_code'] = $shippingCode;
            $prefixKey = self::prefixKey($shippingCode);
            $itemPrefixKeys[$index] = $prefixKey;

            if (!isset($supplierOrderByPrefix[$prefixKey])) {
                $supplierOrderByPrefix[$prefixKey] = [];
            }
            if (!in_array($supplierKey, $supplierOrderByPrefix[$prefixKey], true)) {
                $supplierOrderByPrefix[$prefixKey][] = $supplierKey;
            }

            if (!self::itemHasManualNumber($item) || !$parsed || self::prefixKey($parsed['prefix']) !== $prefixKey) {
                continue;
            }

            $supplierSequence = $parsed['supplier_sequence'];
            $itemSequence = $parsed['item_sequence'];
            if (!isset($currentManualSupplierSequences[$prefixKey][$supplierKey])
                || $supplierSequence > $currentManualSupplierSequences[$prefixKey][$supplierKey]
            ) {
                $currentManualSupplierSequences[$prefixKey][$supplierKey] = $supplierSequence;
            }
            $currentUsedSupplierSequences[$prefixKey][$supplierSequence] = true;
            $currentManualItemCounts[$prefixKey][$supplierKey][$supplierSequence] = max(
                $currentManualItemCounts[$prefixKey][$supplierKey][$supplierSequence] ?? 0,
                $itemSequence
            );
        }

        foreach ($supplierOrderByPrefix as $prefixKey => $supplierOrder) {
            $nextSupplierSequence = max(
                $history['max_supplier_sequence'][$prefixKey] ?? 0,
                self::maxIntKey($currentUsedSupplierSequences[$prefixKey] ?? [])
            ) + 1;
            $assignedSequenceOwners = [];

            foreach ($supplierOrder as $supplierKey) {
                if (isset($currentManualSupplierSequences[$prefixKey][$supplierKey])) {
                    $sequence = $currentManualSupplierSequences[$prefixKey][$supplierKey];
                    $supplierSequences[$prefixKey][$supplierKey] = $sequence;
                    $assignedSequenceOwners[$sequence] = $supplierKey;
                    continue;
                }

                $historicalSequence = $history['supplier_sequences'][$prefixKey][$supplierKey] ?? null;
                if ($historicalSequence !== null
                    && !isset($currentUsedSupplierSequences[$prefixKey][$historicalSequence])
                    && !isset($assignedSequenceOwners[$historicalSequence])
                ) {
                    $supplierSequences[$prefixKey][$supplierKey] = $historicalSequence;
                    $assignedSequenceOwners[$historicalSequence] = $supplierKey;
                    continue;
                }

                while (isset($history['used_supplier_sequences'][$prefixKey][$nextSupplierSequence])
                    || isset($currentUsedSupplierSequences[$prefixKey][$nextSupplierSequence])
                    || isset($assignedSequenceOwners[$nextSupplierSequence])
                ) {
                    $nextSupplierSequence++;
                }

                $supplierSequences[$prefixKey][$supplierKey] = $nextSupplierSequence;
                $assignedSequenceOwners[$nextSupplierSequence] = $supplierKey;
                $currentUsedSupplierSequences[$prefixKey][$nextSupplierSequence] = true;
                $nextSupplierSequence++;
            }
        }

        foreach ($items as $index => $item) {
            $supplierKey = self::buildSupplierKey($item, $defaultSupplierId);
            $prefixKey = $itemPrefixKeys[$index] ?? self::prefixKey($items[$index]['shipping_code'] ?? null);
            $supplierSequence = $supplierSequences[$prefixKey][$supplierKey] ?? 1;
            $supplierItemCounts[$prefixKey][$supplierKey] = max(
                $supplierItemCounts[$prefixKey][$supplierKey] ?? 0,
                $history['item_counts'][$prefixKey][$supplierKey][$supplierSequence] ?? 0,
                $currentManualItemCounts[$prefixKey][$supplierKey][$supplierSequence] ?? 0
            );

            $parsed = self::parseItemNumber((string) ($item['item_no'] ?? ''));
            if ($parsed
                && self::prefixKey($parsed['prefix']) === $prefixKey
                && $parsed['supplier_sequence'] === $supplierSequence
            ) {
                $supplierItemCounts[$prefixKey][$supplierKey] = max(
                    $supplierItemCounts[$prefixKey][$supplierKey] ?? 0,
                    $parsed['item_sequence']
                );
            }
        }

        foreach ($items as $index => $item) {
            $supplierKey = self::buildSupplierKey($item, $defaultSupplierId);
            $prefixKey = $itemPrefixKeys[$index] ?? self::prefixKey($items[$index]['shipping_code'] ?? null);
            $supplierSequence = $supplierSequences[$prefixKey][$supplierKey] ?? 1;
            $shippingCode = $items[$index]['shipping_code'] ?? $defaultShippingCode;

            if (self::itemHasManualNumber($item) && trim((string) ($item['item_no'] ?? '')) !== '') {
                continue;
            }

            $supplierItemCounts[$prefixKey][$supplierKey] = ($supplierItemCounts[$prefixKey][$supplierKey] ?? 0) + 1;
            $items[$index]['item_no'] = $shippingCode
                ? sprintf(
                    '%s-%d-%d',
                    $shippingCode,
                    $supplierSequence,
                    $supplierItemCounts[$prefixKey][$supplierKey]
                )
                : null;
        }

        return $items;
    }

    public static function fetchNumberingHistory(PDO $pdo, int $customerId, ?int $excludeOrderId = null): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $hasItemSupplier = self::tableHasColumn($pdo, 'order_items', 'supplier_id');
        $hasShippingCode = self::tableHasColumn($pdo, 'order_items', 'shipping_code');
        $hasSharedCartons = self::tableHasColumn($pdo, 'order_items', 'shared_carton_enabled')
            && self::tableHasColumn($pdo, 'order_items', 'shared_carton_contents');

        $select = [
            'oi.item_no',
            $hasShippingCode ? 'oi.shipping_code' : 'NULL AS shipping_code',
            $hasItemSupplier ? 'COALESCE(oi.supplier_id, o.supplier_id) AS supplier_id' : 'o.supplier_id AS supplier_id',
        ];
        if ($hasSharedCartons) {
            $select[] = 'oi.shared_carton_contents';
        }

        $sql = 'SELECT ' . implode(', ', $select) . '
                FROM order_items oi
                JOIN orders o ON o.id = oi.order_id
                WHERE o.customer_id = ?';
        $params = [$customerId];
        if ($excludeOrderId !== null && $excludeOrderId > 0) {
            $sql .= ' AND o.id <> ?';
            $params[] = $excludeOrderId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $history = [];

        foreach ($rows as $row) {
            if (trim((string) ($row['item_no'] ?? '')) !== '') {
                $history[] = [
                    'item_no' => $row['item_no'],
                    'supplier_id' => $row['supplier_id'] ?? null,
                    'shipping_code' => $row['shipping_code'] ?? null,
                ];
            }

            if (!$hasSharedCartons || empty($row['shared_carton_contents'])) {
                continue;
            }

            $decoded = is_array($row['shared_carton_contents'])
                ? $row['shared_carton_contents']
                : (json_decode((string) $row['shared_carton_contents'], true) ?: []);
            if (!is_array($decoded)) {
                continue;
            }

            foreach ($decoded as $content) {
                if (!is_array($content) || trim((string) ($content['item_no'] ?? '')) === '') {
                    continue;
                }
                $history[] = [
                    'item_no' => $content['item_no'],
                    'supplier_id' => $content['supplier_id'] ?? $row['supplier_id'] ?? null,
                    'shipping_code' => $content['shipping_code'] ?? $row['shipping_code'] ?? null,
                ];
            }
        }

        return $history;
    }

    private static function buildSupplierKey(array $item, ?int $defaultSupplierId): string
    {
        $supplierId = !empty($item['supplier_id']) ? (int) $item['supplier_id'] : (int) ($defaultSupplierId ?: 0);
        if ($supplierId > 0) {
            return 'supplier:' . $supplierId;
        }

        return 'supplier:none';
    }

    private static function itemHasManualNumber(array $item): bool
    {
        return !empty($item['item_no_manual']);
    }

    private static function parseItemNumber(string $itemNo): ?array
    {
        $value = trim($itemNo);
        if ($value === '') {
            return null;
        }

        if (!preg_match('/^(.+)-(\d+)-(\d+)$/', $value, $matches)) {
            return null;
        }

        return [
            'prefix' => trim((string) $matches[1]),
            'supplier_sequence' => (int) $matches[2],
            'item_sequence' => (int) $matches[3],
        ];
    }

    private static function buildNumberingHistoryState(array $rows): array
    {
        $state = [
            'supplier_sequences' => [],
            'used_supplier_sequences' => [],
            'max_supplier_sequence' => [],
            'item_counts' => [],
        ];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $parsed = self::parseItemNumber((string) ($row['item_no'] ?? ''));
            if (!$parsed) {
                continue;
            }

            $prefixKey = self::prefixKey($parsed['prefix']);
            $supplierKey = self::buildSupplierKey($row, null);
            $supplierSequence = $parsed['supplier_sequence'];
            $itemSequence = $parsed['item_sequence'];

            $state['used_supplier_sequences'][$prefixKey][$supplierSequence] = true;
            $state['max_supplier_sequence'][$prefixKey] = max(
                $state['max_supplier_sequence'][$prefixKey] ?? 0,
                $supplierSequence
            );

            if (!isset($state['supplier_sequences'][$prefixKey][$supplierKey])
                || $supplierSequence > $state['supplier_sequences'][$prefixKey][$supplierKey]
            ) {
                $state['supplier_sequences'][$prefixKey][$supplierKey] = $supplierSequence;
            }

            $state['item_counts'][$prefixKey][$supplierKey][$supplierSequence] = max(
                $state['item_counts'][$prefixKey][$supplierKey][$supplierSequence] ?? 0,
                $itemSequence
            );
        }

        return $state;
    }

    private static function prefixKey(?string $prefix): string
    {
        return OrderCountryService::normalizeShippingCode($prefix) ?? '';
    }

    private static function maxIntKey(array $values): int
    {
        $max = 0;
        foreach (array_keys($values) as $key) {
            $max = max($max, (int) $key);
        }
        return $max;
    }

    private static function tableHasColumn(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = spl_object_id($pdo) . ':' . $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            $cache[$key] = false;
            return false;
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
}
