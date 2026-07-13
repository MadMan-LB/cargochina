<?php

final class ItemNumberReservationService
{
    public function __construct(private PDO $pdo) {}

    public static function normalize(string $value): string
    {
        $value = mb_strtoupper(trim($value), 'UTF-8');
        $value = preg_replace('/[\s_‐‑‒–—−]+/u', '-', $value) ?? $value;
        $value = preg_replace('/-+/', '-', $value) ?? $value;
        return trim($value, "- \t\n\r\0\x0B");
    }

    public static function formatControlled(string $value): string
    {
        return self::normalize($value);
    }

    public function reservePersistedOrder(int $orderId, int $userId): array
    {
        if (!$this->pdo->inTransaction()) throw new RuntimeException('Item-number reservation requires an active transaction');
        $stmt = $this->pdo->prepare('SELECT customer_id FROM orders WHERE id=? FOR UPDATE');
        $stmt->execute([$orderId]);
        $customerId = (int) $stmt->fetchColumn();
        if ($customerId <= 0) throw new RuntimeException('Order not found while reserving item numbers');

        $hasSource = $this->tableHasColumn('order_items', 'item_no_source');
        $sourceSelect = $hasSource ? 'item_no_source' : "'generated' AS item_no_source";
        $items = $this->pdo->prepare("SELECT id,item_no,$sourceSelect,shipping_code,supplier_id,shared_carton_enabled,shared_carton_contents FROM order_items WHERE order_id=? ORDER BY id");
        $items->execute([$orderId]);
        $reserved = [];
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
            if (trim((string) ($item['item_no'] ?? '')) !== '') {
                $reserved[] = $this->reserveOne($customerId,$orderId,(int)$item['id'],'order_item','order_item:' . (int)$item['id'],(string)$item['item_no'],(string)($item['shipping_code']??''),!empty($item['supplier_id'])?(int)$item['supplier_id']:null,$userId,(string)($item['item_no_source']??'generated'));
            }
            if (empty($item['shared_carton_enabled']) || empty($item['shared_carton_contents'])) continue;
            $contents = json_decode((string) $item['shared_carton_contents'], true);
            if (!is_array($contents)) continue;
            foreach (array_values($contents) as $index => $content) {
                if (!is_array($content) || trim((string)($content['item_no']??''))==='') continue;
                $reserved[] = $this->reserveOne($customerId,$orderId,(int)$item['id'],'shared_carton_content','order_item:' . (int)$item['id'] . ':content:' . $index,(string)$content['item_no'],(string)($content['shipping_code']??$item['shipping_code']??''),!empty($content['supplier_id'])?(int)$content['supplier_id']:(!empty($item['supplier_id'])?(int)$item['supplier_id']:null),$userId,(string)($content['item_no_source']??'generated'));
            }
        }
        return $reserved;
    }

    public function assertCandidatesAvailable(int $customerId, array $numbers, int $sameOrderId = 0): void
    {
        $seen=[];
        foreach ($numbers as $number) {
            $display=self::formatControlled((string)$number); $normalized=self::normalize($display);
            if($normalized==='') throw new RuntimeException('Generated item number is blank');
            if(isset($seen[$normalized])) throw new RuntimeException('Duplicate normalized item number in this request: '.$display);
            $seen[$normalized]=$display;
            $stmt=$this->pdo->prepare('SELECT r.id,ref.order_id FROM item_number_reservations r LEFT JOIN item_number_references ref ON ref.reservation_id=r.id WHERE r.customer_id=? AND r.normalized_item_no=? LIMIT 1');
            $stmt->execute([$customerId,$normalized]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
            if($row && (int)($row['order_id']??0)!==$sameOrderId) throw new RuntimeException('Item number is already reserved for this customer: '.$display);
        }
    }

    private function reserveOne(int $customerId,int $orderId,?int $orderItemId,string $type,string $referenceKey,string $display,string $prefix,?int $supplierId,int $userId,string $source='generated'): array
    {
        $normalized=self::normalize($display);
        if($normalized==='') throw new RuntimeException('Generated item number is blank');
        $parsed=$this->parse($display);
        try {
            $this->pdo->prepare('INSERT INTO item_number_reservations (customer_id,display_item_no,normalized_item_no,shipping_prefix,supplier_id,supplier_sequence,item_sequence,created_by) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$customerId,$display,$normalized,$parsed['prefix']??($prefix?:null),$supplierId,$parsed['supplier_sequence']??null,$parsed['item_sequence']??null,$userId]);
            $reservationId=(int)$this->pdo->lastInsertId();
        } catch (PDOException $e) {
            if((string)$e->getCode()!=='23000') throw $e;
            $find=$this->pdo->prepare('SELECT id FROM item_number_reservations WHERE customer_id=? AND normalized_item_no=?');
            $find->execute([$customerId,$normalized]); $reservationId=(int)$find->fetchColumn();
            if($reservationId<=0) throw $e;
            $owner=$this->pdo->prepare('SELECT order_id FROM item_number_references WHERE reservation_id=? ORDER BY id LIMIT 1');
            $owner->execute([$reservationId]); $ownerOrder=(int)($owner->fetchColumn()?:0);
            if($ownerOrder>0 && $ownerOrder!==$orderId){
                if($source==='imported')return ['reservation_id'=>null,'display_item_no'=>$display,'normalized_item_no'=>$normalized,'warning'=>'Imported duplicate was preserved but not reserved'];
                throw new RuntimeException('Item number is already reserved for this customer: '.$display);
            }
        }
        $this->pdo->prepare('INSERT INTO item_number_references (reservation_id,order_id,order_item_id,reference_type,reference_key,is_legacy) VALUES (?,?,?,?,?,0) ON DUPLICATE KEY UPDATE order_item_id=VALUES(order_item_id)')
            ->execute([$reservationId,$orderId,$orderItemId,$type,$referenceKey]);
        return ['reservation_id'=>$reservationId,'display_item_no'=>$display,'normalized_item_no'=>$normalized];
    }

    private function tableHasColumn(string $table,string $column): bool
    {
        if(!preg_match('/^[A-Za-z0-9_]+$/',$table)||!preg_match('/^[A-Za-z0-9_]+$/',$column))return false;
        try{$stmt=$this->pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");$stmt->execute([$column]);return(bool)$stmt->fetch();}catch(Throwable $e){return false;}
    }

    private function parse(string $value): ?array
    {
        if(!preg_match('/^(.+)-(\d+)-(\d+)$/',self::formatControlled($value),$m)) return null;
        return ['prefix'=>$m[1],'supplier_sequence'=>(int)$m[2],'item_sequence'=>(int)$m[3]];
    }
}
