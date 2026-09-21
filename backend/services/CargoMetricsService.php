<?php
require_once __DIR__ . '/ReceivingQuantityService.php';

/** Operational cargo measurements; procurement declarations remain unchanged. */
class CargoMetricsService
{
    public const WAREHOUSE_STATUSES = ['ReceivedAtWarehouse','AwaitingCustomerConfirmation','Confirmed','ReadyForConsolidation','ConsolidatedIntoShipmentDraft','AssignedToContainer','CustomerDeclined','CustomerDeclinedAfterAutoConfirm'];
    private static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = spl_object_id($pdo) . ':' . $table . ':' . $column;
        return $cache[$key] ??= $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($column))->rowCount() > 0;
    }

    public static function activeWhere(PDO $pdo, string $alias): string
    {
        $hasVoided = self::hasColumn($pdo, 'warehouse_receipts', 'voided_at');
        return $hasVoided ? " WHERE $alias.voided_at IS NULL" : '';
    }

    public static function itemTotalsSql(PDO $pdo): string
    {
        $active=self::activeWhere($pdo,'wr');
        $quantity=ReceivingQuantityService::legacyQuantitySql();
        $dimensions=[];
        foreach(['height','width','length'] as $dimension) {
            $column='actual_'.$dimension;
            $dimensions[]=self::hasColumn($pdo,'warehouse_receipt_items',$column)
                ? "CASE WHEN COUNT(DISTINCT wri.$column)=1 AND COUNT(wri.$column)=COUNT(*) THEN MAX(wri.$column) ELSE NULL END $column"
                : "NULL $column";
        }
        return "SELECT wri.order_item_id, SUM(wri.actual_cbm) cbm, SUM(wri.actual_weight) weight,
            SUM(wri.actual_cartons) cartons, CASE WHEN COUNT($quantity)=COUNT(*) THEN SUM($quantity) ELSE NULL END quantity,
            MAX(($quantity) IS NULL) unknown_quantity, COUNT(*) receipt_count,
            CASE WHEN COUNT(wri.total_amount)=COUNT(*) THEN SUM(wri.total_amount) ELSE NULL END amount,
            MAX(wri.receipt_condition='damaged' OR wri.variance_detected=1) has_damage, ".implode(', ',$dimensions)."
            FROM warehouse_receipt_items wri JOIN order_items oi ON oi.id=wri.order_item_id
            JOIN warehouse_receipts wr ON wr.id=wri.receipt_id$active GROUP BY wri.order_item_id";
    }

    /** Receipt projection shared by stock and full cargo totals. Do not join item
     * aggregates when a caller only needs receipt measurements and ledger state. */
    public static function receiptTotalsSql(PDO $pdo): string
    {
        $active = self::activeWhere($pdo, 'wr');
        $mismatch = "EXISTS(SELECT 1 FROM warehouse_receipt_items ai JOIN order_items aoi ON aoi.id=ai.order_item_id WHERE ai.receipt_id=wr.id AND (aoi.order_id<>wr.order_id OR ai.actual_cbm IS NULL OR ai.actual_weight IS NULL OR ai.actual_cartons IS NULL))";
        foreach (['actual_cbm','actual_weight','actual_cartons'] as $metric) $mismatch .= " OR wr.$metric<>(SELECT SUM(ai.$metric) FROM warehouse_receipt_items ai WHERE ai.receipt_id=wr.id)";
        return "SELECT wr.order_id, SUM(wr.actual_cbm) cbm,
            SUM(wr.actual_weight) weight, SUM(wr.actual_cartons) cartons, COUNT(*) receipt_count,
            SUM(NOT EXISTS(SELECT 1 FROM warehouse_receipt_items ai WHERE ai.receipt_id=wr.id)) unallocated_receipts,
            SUM(wr.actual_cbm IS NULL OR wr.actual_cbm<=0 OR wr.actual_weight IS NULL OR wr.actual_weight<0) invalid_measurements,
            SUM($mismatch) invalid_allocations, MAX(wr.receipt_condition='damaged') has_damage
            FROM warehouse_receipts wr$active GROUP BY wr.order_id";
    }

    public static function orderTotalsSql(PDO $pdo): string
    {
        $active = self::activeWhere($pdo, 'wr');
        $itemTotals=self::itemTotalsSql($pdo);
        $receivedStatuses=implode(',',array_map([$pdo,'quote'],array_merge(self::WAREHOUSE_STATUSES,['FinalizedAndPushedToTracking'])));
        $missingLedger="(COALESCE(r.receipt_count,0)=0 AND o.status IN ($receivedStatuses))";
        $quantityKnown="COALESCE(r.unallocated_receipts,0)=0 AND COALESCE(d.unknown_quantity,0)=0 AND NOT $missingLedger";
        $cartons = self::hasColumn($pdo, 'order_items', 'order_cartons') ? 'COALESCE(oi.order_cartons, oi.cartons)' : 'oi.cartons';
        $pieces = self::hasColumn($pdo, 'order_items', 'order_qty_per_carton') ? 'COALESCE(oi.order_qty_per_carton, oi.qty_per_carton)' : 'oi.qty_per_carton';
        $receiptTotals = self::receiptTotalsSql($pdo);
        return "SELECT o.id AS order_id, o.status,
            COALESCE(r.cbm, d.cbm, 0) AS cbm,
            COALESCE(r.weight, d.weight, 0) AS weight,
            COALESCE(r.cartons, d.cartons, 0) AS cartons,
            CASE WHEN NOT ($quantityKnown) THEN NULL WHEN r.receipt_count>0 THEN COALESCE(d.received_quantity,0) ELSE COALESCE(d.ordered_quantity,0) END AS quantity,
            ($quantityKnown) AS quantity_complete,
            ($quantityKnown AND r.receipt_count>0 AND d.item_count>0 AND d.incomplete_items=0 AND COALESCE(r.invalid_allocations,0)=0) AS fully_received,
            (SELECT COUNT(*) FROM shipment_draft_orders sdo WHERE sdo.order_id=o.id) AS reservation_count,
            COALESCE(r.unallocated_receipts,0) AS unallocated_receipts,
            $missingLedger AS missing_receipt_ledger,
            COALESCE(d.ordered_quantity,0) AS ordered_quantity,
            CASE WHEN $quantityKnown THEN COALESCE(d.received_quantity,0) ELSE NULL END AS received_quantity,
            CASE WHEN $quantityKnown THEN GREATEST(0,COALESCE(d.ordered_quantity,0)-COALESCE(d.received_quantity,0)) ELSE NULL END AS remaining_quantity,
            COALESCE(r.has_damage, 0) AS has_damage,
            COALESCE(r.receipt_count, 0) AS receipt_count
            ,(COALESCE(r.receipt_count,0)>0 AND COALESCE(r.invalid_measurements,0)=0) AS measured_cargo_complete
            FROM orders o
            LEFT JOIN ($receiptTotals) r ON r.order_id=o.id
            LEFT JOIN (SELECT oi.order_id, SUM(oi.declared_cbm) cbm,
                SUM(oi.declared_weight) weight, SUM($cartons) cartons,
                SUM(CASE WHEN oi.quantity>0 THEN oi.quantity ELSE $cartons*$pieces END) ordered_quantity,
                COUNT(*) item_count,
                SUM(ABS(COALESCE(ri.quantity,0)-(CASE WHEN oi.quantity>0 THEN oi.quantity ELSE $cartons*$pieces END))>=0.0001 OR (CASE WHEN oi.quantity>0 THEN oi.quantity ELSE $cartons*$pieces END)<=0) incomplete_items,
                SUM(COALESCE(ri.quantity,0)) received_quantity, MAX(COALESCE(ri.unknown_quantity,0)) unknown_quantity
                FROM order_items oi LEFT JOIN ($itemTotals) ri ON ri.order_item_id=oi.id
                GROUP BY oi.order_id) d ON d.order_id=o.id";
    }

    public static function totals(PDO $pdo, array $orderIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if (!$ids) return [];
        $stmt = $pdo->prepare('SELECT * FROM (' . self::orderTotalsSql($pdo) . ') cargo WHERE order_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute($ids);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            foreach (['cbm', 'weight', 'cartons', 'quantity', 'ordered_quantity', 'received_quantity', 'remaining_quantity'] as $field) $row[$field] = $row[$field]===null ? null : (float) $row[$field];
            $row['quantity_complete']=(bool)$row['quantity_complete'];
            $row['receipt_count'] = (int) $row['receipt_count'];
            $result[(int) $row['order_id']] = $row;
        }
        return $result;
    }

    public static function attachOrders(PDO $pdo, array $orders): array
    {
        $totals = self::totals($pdo, array_column($orders, 'id'));
        foreach ($orders as &$order) $order['cargo_totals'] = $totals[(int) $order['id']] ?? null;
        return $orders;
    }

    public static function attachItems(PDO $pdo, array $items): array
    {
        if (!$items) return [];
        $ids = array_column($items, 'id');
        $stmt = $pdo->prepare('SELECT * FROM (' . self::itemTotalsSql($pdo) . ') ri WHERE order_item_id IN (' . implode(',', array_fill(0,count($ids),'?')) . ')');
        $stmt->execute($ids);
        $actuals = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $actuals[(int) $row['order_item_id']] = $row;
        $orderTotals = self::totals($pdo, array_column($items, 'order_id'));
        foreach ($items as &$item) {
            $item['unit'] = ReceivingQuantityService::quantityUnit($item);
            $actual = $actuals[(int) $item['id']] ?? [];
            $orderSummary=$orderTotals[$item['order_id'] ?? 0] ?? [];
            $unallocated=!empty($orderSummary['unallocated_receipts']) || !empty($orderSummary['missing_receipt_ledger']);
            $quantityKnown=!$unallocated && empty($actual['unknown_quantity']);
            $item['receipt_quantity_complete']=$quantityKnown;
            $item['received_cbm'] = $unallocated ? null : (float) ($actual['cbm'] ?? 0);
            $item['received_has_damage'] = (bool) ($actual['has_damage'] ?? false);
            $item['received_total_amount'] = isset($actual['amount']) ? (float) $actual['amount'] : null;
            $item['ordered_quantity'] = ReceivingQuantityService::ordered($item);
            $item['received_quantity'] = $quantityKnown ? (float) ($actual['quantity'] ?? 0) : null;
            $item['received_cartons'] = $unallocated ? null : (float) ($actual['cartons'] ?? 0);
            $item['received_weight'] = $unallocated ? null : (float) ($actual['weight'] ?? 0);
            $item['item_receipt_count'] = (int)($actual['receipt_count'] ?? 0);
            foreach (['height','width','length'] as $dimension) $item['item_actual_'.$dimension] = $unallocated ? null : ($actual['actual_'.$dimension] ?? null);
            $item['remaining_quantity'] = $quantityKnown ? max(0, $item['ordered_quantity'] - $item['received_quantity']) : null;
            // Repacking may change received cartons. Estimate remaining packing
            // from remaining goods, never subtract actual cartons from estimates.
            $estimatedCartons = $item['ordered_quantity'] > 0 ? (float)($item['order_cartons'] ?? $item['cartons'] ?? 0) * $item['remaining_quantity'] / $item['ordered_quantity'] : 0;
            $item['remaining_cartons'] = !$quantityKnown ? null : (abs(round($estimatedCartons)-$estimatedCartons)<0.0001 ? (float)round($estimatedCartons) : 0.0);
            $hasOrderReceipt = !empty($orderSummary['receipt_count']);
            foreach (['cbm' => 'declared_cbm', 'weight' => 'declared_weight', 'cartons' => 'cartons', 'quantity' => 'quantity'] as $key => $declared) {
                $item['cargo_' . $key] = ($unallocated || ($key==='quantity' && !$quantityKnown)) ? null : (float) ($actual[$key] ?? ($hasOrderReceipt ? 0 : ($item[$declared] ?? 0)));
            }
            if (!$unallocated && !$hasOrderReceipt && !isset($actual['cartons'])) $item['cargo_cartons'] = (float) ($item['order_cartons'] ?? $item['cartons'] ?? 0);
            if (!$unallocated && !$hasOrderReceipt && !isset($actual['quantity']) && $item['cargo_quantity'] <= 0) $item['cargo_quantity'] = $item['ordered_quantity'];
        }
        return $items;
    }

    public static function shippingItems(PDO $pdo, array $items): array
    {
        $items = self::attachItems($pdo, $items);
        foreach ($items as &$item) {
            $declaredQuantity = (float) ($item['quantity'] ?? 0);
            foreach (['cbm' => 'declared_cbm', 'weight' => 'declared_weight', 'cartons' => 'cartons', 'quantity' => 'quantity'] as $key => $field) $item[$field] = $item['cargo_' . $key];
            $item['order_cartons'] = $item['cartons'];
            if ($item['quantity']===null) {$item['qty_per_carton']=null;$item['total_amount']=null;}
            elseif ($item['cartons'] > 0) $item['qty_per_carton'] = $item['quantity'] / $item['cartons'];
            $item['order_qty_per_carton'] = $item['qty_per_carton'];
            if ($item['quantity']===null) continue;
            if (isset($item['sell_price']) && $item['sell_price'] !== '') {
                $item['total_amount'] = round($item['quantity'] * (float) $item['sell_price'], 4);
            } elseif (isset($item['received_total_amount'])) {
                $item['total_amount'] = $item['received_total_amount'];
                if ($item['quantity'] > 0) $item['unit_price'] = $item['total_amount'] / $item['quantity'];
            } elseif (isset($item['received_quantity']) && $item['quantity'] !== $declaredQuantity) {
                $item['total_amount'] = round($item['quantity'] * (float) ($item['unit_price'] ?? 0), 4);
            }
        }
        return $items;
    }
}
