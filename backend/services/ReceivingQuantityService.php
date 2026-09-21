<?php

/** Shared item ledger rules for UI/API receipts and import previews. No writes. */
final class ReceivingQuantityService
{
    public static function quantityUnit(array $item): string
    {
        $unit=(string)($item['unit']??'pieces');
        $packing=(float)($item['order_qty_per_carton']??$item['qty_per_carton']??0);
        $cartons=(float)($item['order_cartons']??$item['cartons']??0);
        if(in_array($unit,['carton','cartons','ctn'],true) && $packing>0 && $cartons>0
            && abs(self::ordered($item)-$packing*$cartons)<.0001)return 'pieces';
        return $unit;
    }
    public static function legacyQuantitySql(): string
    {
        return "COALESCE(wri.actual_quantity, wri.actual_cartons * COALESCE(NULLIF(oi.order_qty_per_carton,0),NULLIF(oi.qty_per_carton,0)), CASE WHEN oi.unit IN ('carton','cartons','ctn') THEN wri.actual_cartons END)";
    }
    public static function number($value, string $field, bool $integer = false, bool $required = false): ?float
    {
        if ($value === null || $value === '') {
            if ($required) throw new InvalidArgumentException("$field is required");
            return null;
        }
        if (!is_scalar($value) || is_bool($value) || !is_numeric($value) || !is_finite((float) $value)
            || (float) $value < 0 || ($integer && floor((float) $value) !== (float) $value)) {
            throw new InvalidArgumentException("$field must be a finite, non-negative " . ($integer ? 'integer' : 'number'));
        }
        $scale = str_ends_with($field,'actual_cbm') ? 6 : 4;
        $maximum = str_ends_with($field,'order_item_id') ? 4294967295 : ($scale===6 ? 999999.999999 : 99999999.9999);
        $number = round((float)$value, $integer ? 0 : $scale);
        if ($number > $maximum) throw new InvalidArgumentException("$field exceeds the supported storage range");
        return $number;
    }

    public static function ordered(array $item): float
    {
        return (float) ($item['quantity'] ?? 0) > 0 ? (float) $item['quantity']
            : (float) ($item['order_cartons'] ?? $item['cartons'] ?? 0) * (float) ($item['order_qty_per_carton'] ?? $item['qty_per_carton'] ?? 0);
    }

    public static function normalize(array $input, array $items, array $prior): array
    {
        $known = array_column($items, null, 'id');
        $rows = $input['items'] ?? [];
        if (!is_array($rows)) throw new InvalidArgumentException('items must be an array');
        if (!$rows && count($items) === 1) {
            $rows = [array_merge($input, ['order_item_id' => $items[0]['id']])];
        }
        if (!$rows) throw new InvalidArgumentException('Allocate received goods to their order items');
        $seen = []; $normalized = []; $totals = ['actual_cartons'=>0.0, 'actual_cbm'=>0.0, 'actual_weight'=>0.0, 'actual_quantity'=>0.0];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) throw new InvalidArgumentException("items.$index must be an object");
            if (isset($row['notes']) && !is_string($row['notes'])) throw new InvalidArgumentException('Receipt item notes must be text');
            $id = (int) self::number($row['order_item_id'] ?? null, "items.$index.order_item_id", true, true);
            if (!isset($known[$id]) || isset($seen[$id])) throw new InvalidArgumentException('Invalid or duplicate receiving order item');
            $seen[$id] = true; $item = $known[$id];
            foreach (['actual_cartons','actual_quantity','actual_pieces_per_carton','actual_cbm','actual_weight','weight_per_carton','actual_height','actual_width','actual_length','unit_price','total_amount'] as $field) {
                $row[$field] = self::number($row[$field] ?? null, "items.$index.$field", $field === 'actual_cartons');
            }
            $condition = $row['condition'] ?? 'good';
            if (!in_array($condition, ['good','partial','damaged'], true)) throw new InvalidArgumentException('Invalid receipt item condition');
            $splits = $row['packaging_splits'] ?? $row['splits'] ?? [];
            if (!is_array($splits)) throw new InvalidArgumentException('Packaging splits must be an array');
            if ($splits) {
                $splitTotals = ['cartons'=>0.0,'quantity'=>0.0];
                foreach ($splits as &$split) {
                    if (!is_array($split)) throw new InvalidArgumentException('Invalid packaging split');
                    foreach (['cartons','pieces_per_carton','quantity','unit_price','total_amount'] as $field) $split[$field] = self::number($split[$field] ?? null, "split.$field", $field === 'cartons');
                    $split['quantity'] ??= ($split['cartons'] ?? 0) * ($split['pieces_per_carton'] ?? 0);
                    if ($split['cartons'] !== null && $split['pieces_per_carton'] !== null && abs($split['quantity'] - $split['cartons'] * $split['pieces_per_carton']) > 0.0001) throw new InvalidArgumentException('Packaging split quantity must equal cartons × pieces per carton');
                    foreach ($splitTotals as $field => $_) $splitTotals[$field] += $split[$field] ?? 0;
                }
                unset($split);
                foreach ($splitTotals as $field => $total) {
                    if ($row['actual_'.$field] !== null && abs($row['actual_'.$field] - $total) > 0.0001) throw new InvalidArgumentException('Packaging split totals must match receipt item');
                    $row['actual_'.$field] = $total;
                }
                $row['packaging_splits'] = $splits;
            }
            $row['actual_cartons'] ??= 0;
            $pieces = $row['actual_pieces_per_carton'] ?? ($item['order_qty_per_carton'] ?? $item['qty_per_carton'] ?? null);
            if ($row['actual_quantity'] === null) {
                if ($pieces > 0) $row['actual_quantity'] = round($row['actual_cartons'] * $pieces, 4);
                elseif (in_array(strtolower((string) ($item['unit'] ?? '')), ['carton','cartons','ctn'], true)) $row['actual_quantity'] = $row['actual_cartons'];
                else throw new InvalidArgumentException('Received item quantity is required');
            }
            if (!$splits && $row['actual_pieces_per_carton'] !== null && abs($row['actual_quantity'] - $row['actual_cartons'] * $row['actual_pieces_per_carton']) > 0.0001) throw new InvalidArgumentException('Received quantity must equal cartons × pieces per carton');
            $received = (float) ($prior[$id]['quantity'] ?? 0);
            if (!empty($prior[$id]['unknown_quantity'])) throw new InvalidArgumentException('Prior receipt quantity is unknown; reconcile receipt history before receiving more');
            if ($row['actual_quantity'] > max(0, self::ordered($item) - $received) + 0.0001) throw new InvalidArgumentException('Received quantity exceeds remaining quantity for item #' . $id);
            if ($row['actual_cbm'] === null && $row['actual_height'] !== null && $row['actual_width'] !== null && $row['actual_length'] !== null) $row['actual_cbm'] = round($row['actual_cartons'] * $row['actual_height'] * $row['actual_width'] * $row['actual_length'] / 1000000, 6);
            if ($row['actual_weight'] === null && $row['weight_per_carton'] !== null) $row['actual_weight'] = round($row['actual_cartons'] * $row['weight_per_carton'], 4);
            if ($row['actual_quantity'] == 0) {
                if (($row['actual_cartons'] ?? 0) != 0 || ($row['actual_cbm'] ?? 0) != 0 || ($row['actual_weight'] ?? 0) != 0) throw new InvalidArgumentException('An unreceived item cannot add physical stock');
                continue;
            }
            if (($row['actual_cbm'] ?? 0) <= 0 || $row['actual_weight'] === null) throw new InvalidArgumentException('Received items require positive CBM and a measured weight');
            foreach ($totals as $field => $_) $totals[$field] += $row[$field];
            $normalized[] = $row;
        }
        if (!$normalized) throw new InvalidArgumentException('Receipt must contain a positive received quantity');
        foreach (['actual_cartons','actual_cbm','actual_weight'] as $field) {
            $totals[$field] = self::number($totals[$field],$field,$field==='actual_cartons',true);
            $header = self::number($input[$field] ?? null, $field, $field === 'actual_cartons');
            if ($header !== null && abs($header - $totals[$field]) > ($field === 'actual_cbm' ? 0.000001 : 0.0001)) throw new InvalidArgumentException('Item totals must match receipt ' . $field);
            $input[$field] = $totals[$field];
        }
        $deltas = array_column($normalized, 'actual_quantity', 'order_item_id');
        $remaining = 0.0; $ordered = 0.0; $received = 0.0;
        foreach ($items as $item) {
            $quantity = self::ordered($item); $ordered += $quantity;
            $cumulative = (float) ($prior[$item['id']]['quantity'] ?? 0) + (float) ($deltas[$item['id']] ?? 0);
            $received += $cumulative; $remaining += max(0, $quantity - $cumulative);
        }
        $input['items'] = $normalized;
        $input['quantity_totals'] = ['ordered'=>$ordered,'current'=>$totals['actual_quantity'],'received'=>$received,'remaining'=>$remaining];
        $input['is_partial'] = $remaining > 0.0001;
        return $input;
    }
}
