<?php

require_once __DIR__ . '/OrderCountryService.php';

final class OrderItemNumberingService
{
    public static function isNumberingFrozenStatus(?string $status): bool
    {
        return trim((string) $status) !== '' && trim((string) $status) !== 'Draft';
    }

    public static function prepareItemsForPersistence(array $items, ?string $currentStatus, ?string $defaultShippingCode = null, ?int $defaultSupplierId = null): array
    {
        if (self::isNumberingFrozenStatus($currentStatus)) {
            return $items;
        }

        return self::assignItemNumbers($items, $defaultShippingCode, $defaultSupplierId);
    }

    public static function assignItemNumbers(array $items, ?string $defaultShippingCode = null, ?int $defaultSupplierId = null): array
    {
        $defaultShippingCode = OrderCountryService::normalizeShippingCode($defaultShippingCode);
        $supplierOrder = [];
        $supplierSequences = [];
        $manualSupplierSequences = [];
        $usedSupplierSequences = [];
        $supplierItemCounts = [];

        foreach ($items as $index => $item) {
            $supplierKey = self::buildSupplierKey($item, $defaultSupplierId);
            if (!in_array($supplierKey, $supplierOrder, true)) {
                $supplierOrder[] = $supplierKey;
            }

            $shippingCode = OrderCountryService::normalizeShippingCode((string) ($item['shipping_code'] ?? '')) ?: $defaultShippingCode;
            $items[$index]['shipping_code'] = $shippingCode;

            if (!self::itemHasManualNumber($item)) {
                continue;
            }

            $parsed = self::parseItemNumber((string) ($item['item_no'] ?? ''));
            if (!$parsed || isset($manualSupplierSequences[$supplierKey])) {
                continue;
            }

            $manualSupplierSequences[$supplierKey] = $parsed['supplier_sequence'];
            $usedSupplierSequences[$parsed['supplier_sequence']] = true;
        }

        $nextSupplierSequence = 1;
        foreach ($supplierOrder as $supplierKey) {
            if (isset($manualSupplierSequences[$supplierKey])) {
                $supplierSequences[$supplierKey] = $manualSupplierSequences[$supplierKey];
                continue;
            }

            while (isset($usedSupplierSequences[$nextSupplierSequence])) {
                $nextSupplierSequence++;
            }

            $supplierSequences[$supplierKey] = $nextSupplierSequence;
            $usedSupplierSequences[$nextSupplierSequence] = true;
            $nextSupplierSequence++;
        }

        foreach ($items as $index => $item) {
            $supplierKey = self::buildSupplierKey($item, $defaultSupplierId);
            $supplierSequence = $supplierSequences[$supplierKey] ?? 1;
            $parsed = self::parseItemNumber((string) ($item['item_no'] ?? ''));
            if ($parsed && $parsed['supplier_sequence'] === $supplierSequence) {
                $supplierItemCounts[$supplierKey] = max(
                    $supplierItemCounts[$supplierKey] ?? 0,
                    $parsed['item_sequence']
                );
            }
        }

        foreach ($items as $index => $item) {
            $supplierKey = self::buildSupplierKey($item, $defaultSupplierId);
            $supplierSequence = $supplierSequences[$supplierKey] ?? 1;
            $shippingCode = $items[$index]['shipping_code'] ?? $defaultShippingCode;

            if (self::itemHasManualNumber($item) && trim((string) ($item['item_no'] ?? '')) !== '') {
                continue;
            }

            $supplierItemCounts[$supplierKey] = ($supplierItemCounts[$supplierKey] ?? 0) + 1;
            $items[$index]['item_no'] = $shippingCode
                ? sprintf(
                    '%s-%d-%d',
                    $shippingCode,
                    $supplierSequence,
                    $supplierItemCounts[$supplierKey]
                )
                : null;
        }

        return $items;
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
}
