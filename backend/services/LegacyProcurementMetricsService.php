<?php

/** Legacy procurement quantities are pieces; catalog measurements may describe a carton. */
final class LegacyProcurementMetricsService
{
    public static function normalize(array $item): array
    {
        $scope = $item['dimensions_scope'] ?? 'piece';
        if (!in_array($scope, ['piece', 'carton'], true)) {
            jsonError('Legacy product has an invalid measurement basis', 422);
        }
        $denominator = $scope === 'carton' ? (float) ($item['pieces_per_carton'] ?? 0) : 1.0;
        if ($denominator <= 0) {
            jsonError('Legacy carton measurements require product pieces per carton before export or conversion', 422);
        }
        $quantity = (float) ($item['quantity'] ?? 0);
        foreach (['cbm' => 6, 'weight' => 4] as $field => $precision) {
            $value = $item[$field] ?? null;
            if ($value !== null && (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0)) {
                jsonError('Legacy product has invalid ' . $field, 422);
            }
            $item[$field] = $value === null ? null : (float) $value / $denominator;
            $item['declared_' . $field] = $value === null ? null : round((float) $value * $quantity / $denominator, $precision);
        }
        $item['dimensions_scope'] = 'piece';
        return $item;
    }
}
