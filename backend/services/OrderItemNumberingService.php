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
        $supplierSequences = [];
        $supplierItemCounts = [];
        $nextSupplierSequence = 1;

        foreach ($items as $index => $item) {
            $supplierId = !empty($item['supplier_id']) ? (int) $item['supplier_id'] : (int) ($defaultSupplierId ?: 0);
            $supplierKey = $supplierId > 0 ? 'supplier:' . $supplierId : 'supplier:none';
            if (!isset($supplierSequences[$supplierKey])) {
                $supplierSequences[$supplierKey] = $nextSupplierSequence++;
                $supplierItemCounts[$supplierKey] = 0;
            }

            $supplierItemCounts[$supplierKey]++;
            $shippingCode = OrderCountryService::normalizeShippingCode((string) ($item['shipping_code'] ?? '')) ?: $defaultShippingCode;
            $items[$index]['shipping_code'] = $shippingCode;
            $items[$index]['item_no'] = $shippingCode
                ? sprintf(
                    '%s-%d-%d',
                    $shippingCode,
                    $supplierSequences[$supplierKey],
                    $supplierItemCounts[$supplierKey]
                )
                : null;
        }

        return $items;
    }
}
