<?php

require_once __DIR__ . '/DecimalMath.php';

/**
 * Read-only source-to-output reconciliation for the approved shipment rules.
 */
final class FinancialReconciliationService
{
    public function __construct(private PDO $pdo)
    {
        DecimalMath::assertAvailable();
    }

    public function reconcileOrder(int $orderId): array
    {
        $orderStmt = $this->pdo->prepare("SELECT o.id, o.status, o.order_type, o.customer_id, o.supplier_id,
                COALESCE(NULLIF(o.currency,''),'USD') currency, c.name customer_name, s.name supplier_name
            FROM orders o JOIN customers c ON c.id=o.customer_id
            LEFT JOIN suppliers s ON s.id=o.supplier_id WHERE o.id=?");
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            throw new RuntimeException('Order not found');
        }
        $currency = (string) $order['currency'];

        $itemSql = "SELECT oi.id, oi.quantity,
                CAST(COALESCE(oi.sell_price, oi.unit_price, 0) AS CHAR) sell_unit_price,
                CAST(COALESCE(oi.buy_price, p.buy_price, 0) AS CHAR) buy_unit_price,
                CAST(CASE WHEN oi.total_amount IS NOT NULL THEN oi.total_amount
                          ELSE oi.quantity * COALESCE(oi.sell_price, oi.unit_price, 0) END AS CHAR) sell_total,
                CAST(oi.quantity * COALESCE(oi.buy_price, p.buy_price, 0) AS CHAR) buy_total,
                COALESCE(oi.supplier_id, p.supplier_id, o.supplier_id) effective_supplier_id,
                es.name effective_supplier_name
            FROM order_items oi JOIN orders o ON o.id=oi.order_id
            LEFT JOIN products p ON p.id=oi.product_id
            LEFT JOIN suppliers es ON es.id=COALESCE(oi.supplier_id, p.supplier_id, o.supplier_id)
            WHERE oi.order_id=? ORDER BY oi.id";
        $itemStmt = $this->pdo->prepare($itemSql); $itemStmt->execute([$orderId]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        $customerOrderValue = DecimalMath::sum(array_column($items, 'sell_total'));
        $buyBySupplier = [];
        foreach ($items as &$item) {
            foreach (['sell_unit_price','buy_unit_price','sell_total','buy_total'] as $field) {
                $item[$field] = DecimalMath::normalize($item[$field] ?? '0');
            }
            $supplierId = (int) ($item['effective_supplier_id'] ?? 0);
            if ($supplierId > 0) {
                if (!isset($buyBySupplier[$supplierId])) {
                    $buyBySupplier[$supplierId] = ['supplier_id'=>$supplierId, 'supplier_name'=>(string) ($item['effective_supplier_name'] ?? ''), 'currency'=>$currency, 'order_buy_total'=>'0.0000'];
                }
                $buyBySupplier[$supplierId]['order_buy_total'] = DecimalMath::add($buyBySupplier[$supplierId]['order_buy_total'], $item['buy_total']);
            }
        }
        unset($item);

        $customerDeposits = $this->sumRows("SELECT currency, CAST(SUM(amount) AS CHAR) amount FROM customer_deposits WHERE order_id=? GROUP BY currency", [$orderId]);
        $ledgerRows = $this->tableExists('balance_transactions')
            ? $this->fetchAll("SELECT id, party_type, party_id, transaction_type, direction, CAST(amount AS CHAR) amount, currency, source_table, source_id, transaction_date FROM balance_transactions WHERE order_id=? ORDER BY id", [$orderId])
            : [];
        $standaloneCustomerIncrease = '0.0000';
        $standaloneCustomerReduce = '0.0000';
        foreach ($ledgerRows as &$ledger) {
            $ledger['amount'] = DecimalMath::normalize($ledger['amount']);
            if (($ledger['party_type'] ?? '') === 'customer' && empty($ledger['source_table'])) {
                if (($ledger['direction'] ?? '') === 'increase_balance') $standaloneCustomerIncrease = DecimalMath::add($standaloneCustomerIncrease, $ledger['amount']);
                if (($ledger['direction'] ?? '') === 'reduce_balance') $standaloneCustomerReduce = DecimalMath::add($standaloneCustomerReduce, $ledger['amount']);
            }
        }
        unset($ledger);
        $shipmentEntries=$this->tableExists('shipment_financial_entries')
            ? $this->fetchAll("SELECT id,source_type,source_id,entry_role,generation,entry_kind,posting_state,CAST(amount AS CHAR) amount,currency,CAST(base_amount AS CHAR) base_amount,base_currency,reverses_entry_id FROM shipment_financial_entries WHERE order_id=? AND archived_at IS NULL ORDER BY id",[$orderId]) : [];
        $finalCustomerCharges='0.0000';$pendingCustomerCharges='0.0000';$finalShipmentExpenses='0.0000';
        foreach($shipmentEntries as &$entry){$entry['amount']=DecimalMath::normalize($entry['amount']);$entry['base_amount']=DecimalMath::normalize($entry['base_amount']);if($entry['base_currency']!==$currency)continue;if($entry['entry_role']==='customer_charge'){if($entry['posting_state']==='finalized')$finalCustomerCharges=DecimalMath::add($finalCustomerCharges,$entry['base_amount']);elseif($entry['posting_state']==='provisional')$pendingCustomerCharges=DecimalMath::add($pendingCustomerCharges,$entry['base_amount']);}elseif($entry['entry_role']==='shipment_expense'&&$entry['posting_state']==='finalized')$finalShipmentExpenses=DecimalMath::add($finalShipmentExpenses,$entry['base_amount']);}unset($entry);
        $depositInOrderCurrency = $customerDeposits[$currency] ?? '0.0000';
        $customerDue = DecimalMath::subtract(
            DecimalMath::add(DecimalMath::add($customerOrderValue, $finalCustomerCharges), $standaloneCustomerIncrease),
            DecimalMath::add($depositInOrderCurrency, $standaloneCustomerReduce)
        );

        $supplierPayments = $this->tableExists('supplier_payments')
            ? $this->fetchAll("SELECT sp.id, sp.supplier_id, s.name supplier_name, sp.currency,
                    CAST(sp.amount AS CHAR) amount,
                    CAST(COALESCE(sp.invoice_amount,sp.amount) AS CHAR) invoice_amount,
                    CAST(COALESCE(sp.settlement_delta,sp.discount_amount,0) AS CHAR) settlement
                FROM supplier_payments sp JOIN suppliers s ON s.id=sp.supplier_id
                WHERE sp.order_id=? ORDER BY sp.id", [$orderId])
            : [];
        $supplierLedger = [];
        foreach ($supplierPayments as &$payment) {
            foreach (['amount','invoice_amount','settlement'] as $field) $payment[$field] = DecimalMath::normalize($payment[$field] ?? '0');
            $key = (int) $payment['supplier_id'] . ':' . (string) $payment['currency'];
            if (!isset($supplierLedger[$key])) $supplierLedger[$key] = ['supplier_id'=>(int)$payment['supplier_id'],'supplier_name'=>(string)$payment['supplier_name'],'currency'=>(string)$payment['currency'],'invoice_total'=>'0.0000','paid_total'=>'0.0000','settlement_total'=>'0.0000','balance'=>'0.0000'];
            $supplierLedger[$key]['invoice_total'] = DecimalMath::add($supplierLedger[$key]['invoice_total'], $payment['invoice_amount']);
            $supplierLedger[$key]['paid_total'] = DecimalMath::add($supplierLedger[$key]['paid_total'], $payment['amount']);
            $supplierLedger[$key]['settlement_total'] = DecimalMath::add($supplierLedger[$key]['settlement_total'], $payment['settlement']);
            $supplierLedger[$key]['balance'] = DecimalMath::subtract($supplierLedger[$key]['invoice_total'], DecimalMath::add($supplierLedger[$key]['paid_total'], $supplierLedger[$key]['settlement_total']));
        }
        unset($payment);

        $receipts = $this->tableExists('warehouse_receipts')
            ? $this->fetchAll("SELECT COUNT(*) receipt_count, CAST(COALESCE(SUM(actual_cartons),0) AS CHAR) actual_cartons, CAST(COALESCE(SUM(actual_cbm),0) AS CHAR) actual_cbm, CAST(COALESCE(SUM(actual_weight),0) AS CHAR) actual_weight FROM warehouse_receipts WHERE order_id=?" . ($this->columnExists('warehouse_receipts','voided_at') ? ' AND voided_at IS NULL' : ''), [$orderId])[0]
            : ['receipt_count'=>0,'actual_cartons'=>'0','actual_cbm'=>'0','actual_weight'=>'0'];
        foreach (['actual_cartons','actual_cbm','actual_weight'] as $field) $receipts[$field] = DecimalMath::normalize($receipts[$field] ?? '0');
        $receiptFees = $this->tableExists('warehouse_receipt_fees')
            ? $this->sumRows("SELECT currency, CAST(SUM(amount) AS CHAR) amount FROM warehouse_receipt_fees WHERE order_id=? GROUP BY currency", [$orderId])
            : [];
        $draftCosts = $this->tableExists('draft_order_costs')
            ? $this->fetchAll("SELECT id, cost_type_code, amount, currency, exchange_rate, base_amount, base_currency, responsible_payer, allocation_method, accounting_treatment FROM draft_order_costs WHERE order_id=? AND is_deleted=0 ORDER BY id", [$orderId])
            : [];

        $anomalies = [];
        if (in_array($order['status'], ['CustomerDeclined','CustomerDeclinedAfterAutoConfirm','Cancelled'], true) && (int) $receipts['receipt_count'] > 0) $anomalies[] = 'Declined/cancelled order still has active inventory receipts.';
        if (in_array($order['status'], ['ReadyForConsolidation','Confirmed','ReceivedAtWarehouse'], true) && (int) $receipts['receipt_count'] === 0) $anomalies[] = 'Received/ready status has no active warehouse receipt.';
        foreach ($customerDeposits as $depositCurrency => $_) if ($depositCurrency !== $currency) $anomalies[] = "Linked customer deposit currency $depositCurrency differs from order currency $currency.";

        return [
            'order'=>$order,
            'customer'=>[
                'order_sell_value'=>$customerOrderValue,
                'linked_deposits_by_currency'=>$customerDeposits,
                'standalone_ledger_increase'=>$standaloneCustomerIncrease,
                'finalized_shipment_charges'=>$finalCustomerCharges,
                'pending_shipment_charges'=>$pendingCustomerCharges,
                'standalone_ledger_reduction'=>$standaloneCustomerReduce,
                'reconciled_due_in_order_currency'=>$customerDue,
                'formula'=>'order_sell_value + finalized_shipment_charges + standalone_increases - linked_deposits - standalone_reductions',
            ],
            'suppliers'=>[
                'order_buy_values'=>array_values($buyBySupplier),
                'linked_payment_ledger'=>array_values($supplierLedger),
                'treatment'=>'Supplier liability is sourced only from supplier invoice_amount/payment records. Shipment operational costs never increase the product supplier balance.',
            ],
            'inventory'=>['active_receipts'=>$receipts, 'valuation_status'=>'VERIFIED', 'valuation_method'=>'received quantity at supplier purchase price only; shipment expenses excluded', 'supplier_purchase_total'=>DecimalMath::sum(array_column($items,'buy_total'))],
            'operational_costs'=>['lines'=>$draftCosts, 'treatment'=>'shipment_level_customer_charge_and_expense_no_item_allocation', 'finalized_expense'=>$finalShipmentExpenses,'posting_status'=>'VERIFIED'],
            'receipt_fees'=>['totals_by_currency'=>$receiptFees, 'treatment'=>'shipment_level_customer_charge_and_expense', 'posting_status'=>'VERIFIED'],
            'shipment_financial_entries'=>$shipmentEntries,
            'ledger_transactions'=>$ledgerRows,
            'items'=>$items,
            'anomalies'=>array_values(array_unique($anomalies)),
        ];
    }

    private function sumRows(string $sql, array $params): array
    {
        $map=[];
        foreach ($this->fetchAll($sql,$params) as $row) $map[(string)$row['currency']]=DecimalMath::normalize($row['amount'] ?? '0');
        return $map;
    }
    private function fetchAll(string $sql, array $params): array { $stmt=$this->pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC); }
    private function tableExists(string $table): bool { $stmt=$this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'); $stmt->execute([$table]); return (bool)$stmt->fetchColumn(); }
    private function columnExists(string $table,string $column): bool { $stmt=$this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'); $stmt->execute([$table,$column]); return (bool)$stmt->fetchColumn(); }
}
