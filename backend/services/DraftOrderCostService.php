<?php

require_once __DIR__ . '/TranslationService.php';
require_once __DIR__ . '/ShipmentAccountingService.php';

final class DraftOrderCostService
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function listForOrder(int $orderId): array
    {
        $stmt = $this->pdo->prepare("SELECT dc.*, ct.label_en AS cost_type_label_en, ct.label_zh AS cost_type_label_zh, s.name AS supplier_name
            FROM draft_order_costs dc JOIN draft_order_cost_types ct ON ct.code=dc.cost_type_code
            LEFT JOIN suppliers s ON s.id=dc.supplier_id
            WHERE dc.order_id=? AND dc.is_deleted=0 ORDER BY dc.id");
        $stmt->execute([$orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listArchivedForOrder(int $orderId): array
    {
        $stmt=$this->pdo->prepare("SELECT dc.*,ct.label_en AS cost_type_label_en,ct.label_zh AS cost_type_label_zh,s.name AS supplier_name FROM draft_order_costs dc JOIN draft_order_cost_types ct ON ct.code=dc.cost_type_code LEFT JOIN suppliers s ON s.id=dc.supplier_id WHERE dc.order_id=? AND dc.is_deleted=1 ORDER BY dc.updated_at DESC,dc.id DESC");
        $stmt->execute([$orderId]);return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function summarize(int $orderId): array
    {
        $rows = $this->listForOrder($orderId);
        $byCurrency = [];
        $baseCurrency = null;
        $baseMinor = 0;
        foreach ($rows as $row) {
            $currency = (string) $row['currency'];
            $byCurrency[$currency] = ($byCurrency[$currency] ?? 0) + self::toScaledInt((string) $row['amount'], 4);
            $baseCurrency = $baseCurrency ?: (string) $row['base_currency'];
            if ($baseCurrency === (string) $row['base_currency']) $baseMinor += self::toScaledInt((string) $row['base_amount'], 4);
        }
        $formatted = [];
        foreach ($byCurrency as $currency => $minor) $formatted[$currency] = self::fromScaledInt($minor, 4);
        $accounting = $this->tableExists('shipment_financial_entries')
            ? (new ShipmentAccountingService($this->pdo))->summarizeOrder($orderId)
            : ['order_id'=>$orderId,'totals'=>['provisional'=>[],'finalized'=>[]]];
        return ['lines' => $rows, 'totals_by_currency' => $formatted, 'base_currency' => $baseCurrency, 'base_total' => self::fromScaledInt($baseMinor, 4), 'accounting_treatment' => 'shipment_expense_customer_charge', 'posting'=>$accounting];
    }

    public function create(int $orderId, array $input, int $userId): array
    {
        $this->assertEditableOrder($orderId, true);
        $row = $this->normalize($input,$orderId);
        $key = self::idempotencyKey($input['idempotency_key'] ?? $input['creation_idempotency_key'] ?? null);
        if ($key !== null) {
            $existing=$this->pdo->prepare('SELECT id FROM draft_order_costs WHERE creation_idempotency_key=?');$existing->execute([$key]);
            $existingId=(int)$existing->fetchColumn(); if($existingId>0) return $this->get($existingId);
        }
        $sql = "INSERT INTO draft_order_costs (order_id, cost_type_code, description_en, description_zh, amount, currency, exchange_rate, base_currency, base_amount, supplier_id, service_provider, responsible_payer, allocation_method, accounting_treatment, notes, posting_status, creation_idempotency_key, created_by, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'customer', 'none', 'shipment_expense_customer_charge', ?, 'provisional', ?, ?, ?)";
        $this->pdo->prepare($sql)->execute([$orderId, $row['cost_type_code'], $row['description_en'], $row['description_zh'], $row['amount'], $row['currency'], $row['exchange_rate'], $row['base_currency'], $row['base_amount'], $row['supplier_id'], $row['service_provider'], $row['notes'], $key, $userId, $userId]);
        $id = (int) $this->pdo->lastInsertId();
        $new = $this->get($id);
        $this->history($id, $orderId, 'create', null, $new, $userId);
        (new ShipmentAccountingService($this->pdo))->syncDraftCost($id, $userId);
        $new = $this->get($id);
        return $new;
    }

    public function update(int $id, array $input, int $userId): array
    {
        $old = $this->get($id);
        $this->assertEditableOrder((int) $old['order_id'], true);
        $row = $this->normalize($input,(int)$old['order_id']);
        $expectedVersion=array_key_exists('lock_version',$input)?(int)$input['lock_version']:(int)($old['lock_version']??0);
        $sql = "UPDATE draft_order_costs SET cost_type_code=?, description_en=?, description_zh=?, amount=?, currency=?, exchange_rate=?, base_currency=?, base_amount=?, supplier_id=?, service_provider=?, responsible_payer='customer', allocation_method='none', accounting_treatment='shipment_expense_customer_charge', notes=?, updated_by=?, lock_version=lock_version+1 WHERE id=? AND is_deleted=0 AND lock_version=?";
        $stmt=$this->pdo->prepare($sql);$stmt->execute([$row['cost_type_code'], $row['description_en'], $row['description_zh'], $row['amount'], $row['currency'], $row['exchange_rate'], $row['base_currency'], $row['base_amount'], $row['supplier_id'], $row['service_provider'], $row['notes'], $userId, $id,$expectedVersion]);
        if($stmt->rowCount()!==1) throw new RuntimeException('Cost line changed in another request; reload and try again');
        $new = $this->get($id);
        $this->history($id, (int) $old['order_id'], 'update', $old, $new, $userId);
        (new ShipmentAccountingService($this->pdo))->syncDraftCost($id, $userId);
        $new = $this->get($id);
        return $new;
    }

    public function delete(int $id, int $userId): void
    {
        $old = $this->getAny($id);
        if (!empty($old['is_deleted'])) return;
        $this->assertEditableOrder((int) $old['order_id'], true);
        $this->pdo->prepare('UPDATE draft_order_costs SET is_deleted=1, updated_by=? WHERE id=?')->execute([$userId, $id]);
        (new ShipmentAccountingService($this->pdo))->archiveDraftCost($id, $userId);
        $this->history($id, (int) $old['order_id'], 'delete', $old, null, $userId);
    }

    private function normalize(array $input,int $orderId): array
    {
        $type = strtolower(trim((string) ($input['cost_type_code'] ?? '')));
        $exists = $this->pdo->prepare('SELECT 1 FROM draft_order_cost_types WHERE code=? AND is_active=1');
        $exists->execute([$type]);
        if (!$exists->fetchColumn()) throw new InvalidArgumentException('Invalid or inactive cost type');
        $currency = strtoupper(trim((string) ($input['currency'] ?? '')));
        $baseCurrency = strtoupper(trim((string) ($input['base_currency'] ?? $currency)));
        if (!in_array($currency, ['USD', 'RMB'], true) || !in_array($baseCurrency, ['USD', 'RMB'], true)) throw new InvalidArgumentException('Currency must be USD or RMB');
        $orderCurrencyStmt=$this->pdo->prepare("SELECT COALESCE(NULLIF(currency,''),'USD') FROM orders WHERE id=?");$orderCurrencyStmt->execute([$orderId]);$orderCurrency=strtoupper((string)$orderCurrencyStmt->fetchColumn());
        if($baseCurrency!==$orderCurrency)throw new InvalidArgumentException('Base currency must match the draft order currency');
        $amountInt = self::positiveScaledInt($input['amount'] ?? null, 4, 'Amount');
        $rateInt = $currency === $baseCurrency
            ? self::toScaledInt('1', 8)
            : self::positiveScaledInt($input['exchange_rate'] ?? '1', 8, 'Exchange rate');
        $baseInt = self::multiplyScaled($amountInt, 4, $rateInt, 8, 4);
        $descriptionEn = self::nullableText($input['description_en'] ?? null, 500);
        $descriptionZh = self::nullableText($input['description_zh'] ?? null, 500);
        if ($descriptionEn !== null && $descriptionZh === null) {
            $translated = (new TranslationService($this->pdo))->translateDetailed($descriptionEn, 'en', 'zh');
            $descriptionZh = trim((string) ($translated['translated_text'] ?? '')) ?: null;
        } elseif ($descriptionZh !== null && $descriptionEn === null) {
            $translated = (new TranslationService($this->pdo))->translateDetailed($descriptionZh, 'zh', 'en');
            $descriptionEn = trim((string) ($translated['translated_text'] ?? '')) ?: null;
        }
        return [
            'cost_type_code' => $type,
            'description_en' => $descriptionEn,
            'description_zh' => $descriptionZh,
            'amount' => self::fromScaledInt($amountInt, 4), 'currency' => $currency,
            'exchange_rate' => self::fromScaledInt($rateInt, 8), 'base_currency' => $baseCurrency,
            'base_amount' => self::fromScaledInt($baseInt, 4),
            'supplier_id' => !empty($input['supplier_id']) ? (int) $input['supplier_id'] : null,
            'service_provider' => self::nullableText($input['service_provider'] ?? null, 255),
            'responsible_payer' => 'customer', 'allocation_method' => 'none',
            'notes' => self::nullableText($input['notes'] ?? null, 5000),
        ];
    }

    public function get(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM draft_order_costs WHERE id=? AND is_deleted=0');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Cost line not found');
        return $row;
    }
    private function getAny(int $id): array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM draft_order_costs WHERE id=?');$stmt->execute([$id]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row) throw new RuntimeException('Cost line not found'); return $row;
    }
    private function assertEditableOrder(int $orderId, bool $lock): void
    {
        $sql = "SELECT status, order_type FROM orders WHERE id=?" . ($lock ? ' FOR UPDATE' : '');
        $stmt = $this->pdo->prepare($sql); $stmt->execute([$orderId]); $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['order_type'] !== 'draft_procurement') throw new RuntimeException('Draft order not found');
        if ($row['status'] !== 'Draft') throw new RuntimeException('Costs can only be changed while the order is Draft');
    }
    private function history(int $id, int $orderId, string $action, ?array $old, ?array $new, int $userId): void
    {
        $this->pdo->prepare('INSERT INTO draft_order_cost_history (cost_id, order_id, action, old_value_json, new_value_json, changed_by) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$id, $orderId, $action, $old ? json_encode($old, JSON_UNESCAPED_UNICODE) : null, $new ? json_encode($new, JSON_UNESCAPED_UNICODE) : null, $userId]);
    }
    private static function nullableText($value, int $max): ?string { $v=trim((string) $value); return $v==='' ? null : mb_substr($v,0,$max); }
    private static function positiveScaledInt($value, int $scale, string $label): int { $int=self::toScaledInt((string) $value,$scale); if($int<=0) throw new InvalidArgumentException($label.' must be greater than zero'); return $int; }
    private static function toScaledInt(string $value, int $scale): int
    {
        $value=trim($value); if(!preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/',$value,$m)) throw new InvalidArgumentException('Invalid decimal value');
        $fraction=substr(str_pad($m[3]??'', $scale+1, '0'),0,$scale+1); $round=(int)($fraction[$scale]??'0')>=5;
        $base=((int)$m[2])*(10**$scale)+(int)substr($fraction,0,$scale); if($round)$base++; return ($m[1]??'')==='-' ? -$base : $base;
    }
    private static function fromScaledInt(int $value, int $scale): string { $sign=$value<0?'-':''; $digits=str_pad((string)abs($value),$scale+1,'0',STR_PAD_LEFT); return $sign.substr($digits,0,-$scale).'.'.substr($digits,-$scale); }
    private static function multiplyScaled(int $a,int $as,int $b,int $bs,int $out): int { $divisor=10**($as+$bs-$out); $product=$a*$b; return intdiv($product+intdiv($divisor,2),$divisor); }
    private static function idempotencyKey($value): ?string { $v=trim((string)$value); if($v==='')return null; if(!preg_match('/^[A-Za-z0-9._:-]{8,64}$/',$v))throw new InvalidArgumentException('Invalid cost idempotency key'); return $v; }
    private function tableExists(string $table): bool { $stmt=$this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$stmt->execute([$table]);return (bool)$stmt->fetchColumn(); }
}
