<?php

require_once __DIR__ . '/DecimalMath.php';

/**
 * Approved shipment-level accounting.
 *
 * Every operational cost produces two equal shipment-level effects:
 * a customer charge and a shipment expense. Neither effect changes inventory
 * value or supplier liability. Draft effects are mutable only while provisional;
 * finalized effects are corrected with signed reversal/restoration rows.
 */
final class ShipmentAccountingService
{
    public function __construct(private PDO $pdo)
    {
        DecimalMath::assertAvailable();
    }

    public function syncDraftCost(int $costId, int $userId): void
    {
        $cost = $this->fetchCost($costId, true);
        if (!$cost || !empty($cost['is_deleted'])) return;
        if (!in_array((string)($cost['status']??''),['Draft','Submitted'],true)) throw new RuntimeException('Only Draft or restored Submitted costs can be provisional');

        $max = $this->maxGeneration('draft_order_cost', $costId);
        $generation = $this->currentProvisionalGeneration('draft_order_cost', $costId);
        $kind = $generation !== null ? $this->currentProvisionalKind('draft_order_cost',$costId,$generation) : null;
        if ($generation === null) {
            if ($max > 0 && ($cost['posting_status'] ?? '') !== 'provisional') {
                throw new RuntimeException('Finalized shipment cost cannot be edited');
            }
            $generation = max(1, $max + 1);
            $kind = $max > 0 ? 'restoration' : 'primary';
        }

        foreach (['customer_charge', 'shipment_expense'] as $role) {
            $this->upsertProvisional(
                (int) $cost['order_id'], (int) $cost['customer_id'], 'draft_order_cost', $costId,
                $role, $generation, $kind ?: 'primary',
                (string) $cost['amount'], (string) $cost['currency'], (string) $cost['exchange_rate'],
                (string) $cost['base_currency'], (string) $cost['base_amount'],
                (string) ($cost['description_en'] ?: $cost['description_zh'] ?: $cost['cost_type_code']), $userId
            );
        }
        $this->pdo->prepare("UPDATE draft_order_costs SET responsible_payer='customer', allocation_method='none', accounting_treatment='shipment_expense_customer_charge', posting_status='provisional', finalized_at=NULL, rate_locked_at=NULL, updated_by=? WHERE id=?")
            ->execute([$userId, $costId]);
    }

    public function archiveDraftCost(int $costId, int $userId): void
    {
        $stmt = $this->pdo->prepare("UPDATE shipment_financial_entries SET posting_state='archived', archived_at=NOW(), updated_by=? WHERE source_type='draft_order_cost' AND source_id=? AND posting_state='provisional' AND archived_at IS NULL");
        $stmt->execute([$userId, $costId]);
        $this->pdo->prepare("UPDATE draft_order_costs SET posting_status='archived', updated_by=? WHERE id=?")->execute([$userId, $costId]);
    }

    public function finalizeOrder(int $orderId, int $userId): array
    {
        $order = $this->fetchOrder($orderId, true);
        if (!$order) throw new RuntimeException('Order not found');
        $costIds = $this->pdo->prepare("SELECT id FROM draft_order_costs WHERE order_id=? AND is_deleted=0 ORDER BY id");
        $costIds->execute([$orderId]);
        foreach ($costIds->fetchAll(PDO::FETCH_COLUMN) as $costId) {
            $cost = $this->fetchCost((int) $costId, false);
            if (($cost['posting_status'] ?? '') === 'legacy_unposted') {
                // A13: do not back-post records that predate this approved implementation.
                continue;
            }
            // Provisional entries were synchronized on each draft cost save.
            // Submitted orders are intentionally no longer editable here.
        }

        $stmt = $this->pdo->prepare("UPDATE shipment_financial_entries SET posting_state='finalized', finalized_at=COALESCE(finalized_at,NOW()), updated_by=? WHERE order_id=? AND posting_state='provisional' AND archived_at IS NULL");
        $stmt->execute([$userId, $orderId]);
        $this->pdo->prepare("UPDATE draft_order_costs SET posting_status='finalized', finalized_at=COALESCE(finalized_at,NOW()), rate_locked_at=COALESCE(rate_locked_at,NOW()), updated_by=? WHERE order_id=? AND is_deleted=0 AND posting_status='provisional'")
            ->execute([$userId, $orderId]);
        return $this->summarizeOrder($orderId);
    }

    public function postReceiptFees(int $receiptId, int $userId): void
    {
        $stmt = $this->pdo->prepare("SELECT f.*,o.customer_id,COALESCE(NULLIF(o.currency,''),'USD') order_currency FROM warehouse_receipt_fees f JOIN orders o ON o.id=f.order_id WHERE f.receipt_id=? ORDER BY f.id FOR UPDATE");
        $stmt->execute([$receiptId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fee) {
            $currency = strtoupper(trim((string) ($fee['currency'] ?? $fee['order_currency'])));
            if ($currency !== strtoupper((string) $fee['order_currency'])) {
                throw new RuntimeException('Receiving fee currency must match the order currency');
            }
            foreach (['customer_charge', 'shipment_expense'] as $role) {
                $key = "receipt_fee:{$fee['id']}:$role:1:primary";
                $sql = "INSERT INTO shipment_financial_entries
                    (order_id,customer_id,source_type,source_id,entry_role,generation,entry_kind,posting_state,amount,currency,exchange_rate,base_currency,base_amount,description,idempotency_key,finalized_at,created_by,updated_by)
                    VALUES (?,?, 'receipt_fee',?,?,1,'primary','finalized',?,?,1,?,?,?, ?,NOW(),?,?)
                    ON DUPLICATE KEY UPDATE id=id";
                $this->pdo->prepare($sql)->execute([
                    (int) $fee['order_id'], (int) $fee['customer_id'], (int) $fee['id'], $role,
                    DecimalMath::normalize($fee['amount']), $currency, $currency, DecimalMath::normalize($fee['amount']),
                    (string) $fee['fee_label'], $key, $userId, $userId,
                ]);
            }
            $this->pdo->prepare("UPDATE warehouse_receipt_fees SET posting_status='finalized' WHERE id=?")->execute([(int) $fee['id']]);
        }
    }

    public function reverseOrder(int $orderId, ?int $userId, string $reason): array
    {
        $this->fetchOrder($orderId, true);
        $stmt = $this->pdo->prepare("SELECT e.* FROM shipment_financial_entries e
            WHERE e.order_id=? AND e.posting_state='finalized' AND e.entry_kind IN ('primary','restoration')
              AND e.amount>0 AND NOT EXISTS (SELECT 1 FROM shipment_financial_entries r WHERE r.reverses_entry_id=e.id AND r.entry_kind='reversal')
            ORDER BY e.id FOR UPDATE");
        $stmt->execute([$orderId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $entry) {
            $key = 'reverse:' . (int) $entry['id'];
            $sql = "INSERT INTO shipment_financial_entries
                (order_id,customer_id,source_type,source_id,entry_role,generation,entry_kind,posting_state,amount,currency,exchange_rate,base_currency,base_amount,description,idempotency_key,reverses_entry_id,finalized_at,created_by,updated_by)
                VALUES (?,?,?,?,?,?,'reversal','finalized',?,?,?,?,?,?,?, ?,NOW(),?,?)
                ON DUPLICATE KEY UPDATE id=id";
            $this->pdo->prepare($sql)->execute([
                (int) $entry['order_id'], (int) $entry['customer_id'], $entry['source_type'], (int) $entry['source_id'],
                $entry['entry_role'], (int) $entry['generation'], DecimalMath::subtract('0', $entry['amount']),
                $entry['currency'], $entry['exchange_rate'], $entry['base_currency'], DecimalMath::subtract('0', $entry['base_amount']),
                mb_substr('Reversal: ' . $reason, 0, 500), $key, (int) $entry['id'], $userId, $userId,
            ]);
        }
        $this->pdo->prepare("UPDATE draft_order_costs SET posting_status='reversed', updated_by=? WHERE order_id=? AND posting_status='finalized'")->execute([$userId, $orderId]);
        $this->pdo->prepare("UPDATE warehouse_receipt_fees SET posting_status='reversed' WHERE order_id=? AND posting_status='finalized'")->execute([$orderId]);
        return $this->summarizeOrder($orderId);
    }

    public function restoreOrderCostsAsProvisional(int $orderId, int $userId): void
    {
        $this->fetchOrder($orderId, true);
        $stmt = $this->pdo->prepare("SELECT id FROM draft_order_costs WHERE order_id=? AND is_deleted=0 AND posting_status='reversed' ORDER BY id FOR UPDATE");
        $stmt->execute([$orderId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $costId) {
            $this->pdo->prepare("UPDATE draft_order_costs SET posting_status='provisional', finalized_at=NULL, rate_locked_at=NULL, updated_by=? WHERE id=?")
                ->execute([$userId, (int) $costId]);
            $this->syncDraftCost((int) $costId, $userId);
        }
    }

    public function summarizeOrder(int $orderId): array
    {
        $stmt = $this->pdo->prepare("SELECT posting_state,entry_role,currency,CAST(COALESCE(SUM(amount),0) AS CHAR) total FROM shipment_financial_entries WHERE order_id=? AND archived_at IS NULL GROUP BY posting_state,entry_role,currency ORDER BY posting_state,entry_role,currency");
        $stmt->execute([$orderId]);
        $totals = ['provisional'=>[], 'finalized'=>[]];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $state = (string) $row['posting_state'];
            if (!isset($totals[$state])) continue;
            $totals[$state][(string) $row['entry_role']][(string) $row['currency']] = DecimalMath::normalize($row['total']);
        }
        return ['order_id'=>$orderId, 'totals'=>$totals];
    }

    private function upsertProvisional(int $orderId, int $customerId, string $sourceType, int $sourceId, string $role, int $generation, string $kind, string $amount, string $currency, string $rate, string $baseCurrency, string $baseAmount, string $description, int $userId): void
    {
        $key = "$sourceType:$sourceId:$role:$generation:$kind";
        $sql = "INSERT INTO shipment_financial_entries
            (order_id,customer_id,source_type,source_id,entry_role,generation,entry_kind,posting_state,amount,currency,exchange_rate,base_currency,base_amount,description,idempotency_key,created_by,updated_by)
            VALUES (?,?,?,?,?,?,?,'provisional',?,?,?,?,?,?,?, ?,?)
            ON DUPLICATE KEY UPDATE amount=VALUES(amount),currency=VALUES(currency),exchange_rate=VALUES(exchange_rate),base_currency=VALUES(base_currency),base_amount=VALUES(base_amount),description=VALUES(description),updated_by=VALUES(updated_by)";
        $this->pdo->prepare($sql)->execute([$orderId,$customerId,$sourceType,$sourceId,$role,$generation,$kind,DecimalMath::normalize($amount),$currency,DecimalMath::normalize($rate,8),$baseCurrency,DecimalMath::normalize($baseAmount),$description,$key,$userId,$userId]);
    }

    private function fetchCost(int $costId, bool $lock): ?array
    {
        $stmt = $this->pdo->prepare("SELECT c.*,o.customer_id,o.status FROM draft_order_costs c JOIN orders o ON o.id=c.order_id WHERE c.id=?" . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$costId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function fetchOrder(int $orderId, bool $lock): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE id=?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function maxGeneration(string $sourceType, int $sourceId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(generation),0) FROM shipment_financial_entries WHERE source_type=? AND source_id=?');
        $stmt->execute([$sourceType,$sourceId]);
        return (int) $stmt->fetchColumn();
    }

    private function currentProvisionalGeneration(string $sourceType, int $sourceId): ?int
    {
        $stmt = $this->pdo->prepare("SELECT MAX(generation) FROM shipment_financial_entries WHERE source_type=? AND source_id=? AND posting_state='provisional' AND archived_at IS NULL");
        $stmt->execute([$sourceType,$sourceId]);
        $value = $stmt->fetchColumn();
        return $value === null ? null : (int) $value;
    }
    private function currentProvisionalKind(string $sourceType,int $sourceId,int $generation): ?string
    {
        $stmt=$this->pdo->prepare("SELECT entry_kind FROM shipment_financial_entries WHERE source_type=? AND source_id=? AND generation=? AND posting_state='provisional' AND archived_at IS NULL ORDER BY id LIMIT 1");$stmt->execute([$sourceType,$sourceId,$generation]);$value=$stmt->fetchColumn();return $value===false?null:(string)$value;
    }
}
