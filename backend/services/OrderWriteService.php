<?php
require_once __DIR__.'/SupplierLifecycleService.php';
require_once __DIR__ . '/ReceivingQuantityService.php';

/** Canonical validation shared by standard orders, draft builders and conversion. */
final class OrderWriteService
{
    public static function requireMeasurementSchema(PDO $pdo): void
    {
        $s=$pdo->query("SHOW COLUMNS FROM order_items LIKE 'dimensions_scope'");
        if(!$s->fetch())jsonError('Order measurement migration 082 is required',503);
    }

    /** Catalog measurements use its own packing basis, independently of an order snapshot. */
    public static function productMeasurementMultiplier(array $product, array $item): float
    {
        $quantity = (float) ($item['quantity'] ?? 0);
        if (($product['dimensions_scope'] ?? 'piece') !== 'carton') return $quantity;
        $packing = (float) ($product['pieces_per_carton'] ?? 0);
        if ($packing <= 0) $packing = (float) ($item['qty_per_carton'] ?? $item['pieces_per_carton'] ?? 0);
        return $packing > 0 ? $quantity / $packing : 0;
    }

    public static function lockMutableProcurement(PDO $pdo, int $orderId): array
    {
        if (!$pdo->inTransaction()) throw new LogicException('Procurement mutation requires a transaction');
        $s=$pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');$s->execute([$orderId]);$order=$s->fetch(PDO::FETCH_ASSOC);
        if (!$order) jsonError('Order not found',404);
        if (!in_array($order['status'],['Draft','Submitted'],true)) jsonError('Approved or received procurement is locked',409);
        foreach (['warehouse_receipts','shipment_draft_orders'] as $table) {
            $s=$pdo->prepare("SELECT order_id FROM $table WHERE order_id=? LIMIT 1 FOR UPDATE");$s->execute([$orderId]);
            if ($s->fetchColumn()) jsonError('Received or reserved procurement is locked',409);
        }
        return $order;
    }
    public static function number($value, string $field, bool $integer = false, int $scale = 4, float $maximum = 99999999.9999): ?float
    {
        if ($value === null || $value === '') return null;
        if (!is_scalar($value) || is_bool($value) || !is_numeric($value) || !is_finite((float)$value)
            || (float)$value < 0 || (float)$value > $maximum || ($integer && floor((float)$value) !== (float)$value)) {
            jsonError("$field must be a finite non-negative " . ($integer ? 'integer' : 'number') . ' within the supported range', 422);
        }
        return round((float)$value, $integer ? 0 : $scale);
    }

    public static function validateRawNumbers(array $item): void
    {
        if (isset($item['dimensions_scope']) && !in_array($item['dimensions_scope'], ['piece','carton'], true)) jsonError('Measurement basis must be piece or carton',422);
        foreach(['id','existing_item_id','supplier_id','product_id'] as $field)if(array_key_exists($field,$item))self::number($item[$field],$field,true,0,4294967295);
        foreach(['description_cn','description_en','notes','shipping_code','item_no'] as $field)if(isset($item[$field])&&(!is_string($item[$field])||strlen($item[$field])>65535))jsonError("$field must be text within the supported length",422);
        foreach(['description_entries','shared_carton_contents','photo_paths','image_paths','custom_design_paths'] as $field)if(isset($item[$field])&&!is_array($item[$field]))jsonError("$field must be an array",422);
        foreach (['cartons','quantity','qty_per_carton','pieces_per_carton','quantity_per_carton','declared_cbm','declared_weight','cbm','cbm_per_unit','weight','weight_per_unit','unit_price','sell_price','total_amount','length','width','height','item_length','item_width','item_height'] as $field) {
            if (array_key_exists($field,$item)) self::number($item[$field],$field,$field==='cartons',str_contains($field,'cbm')?6:4,str_contains($field,'cbm')?999999.999999:99999999.9999);
        }
    }

    public static function standardItems(PDO $pdo, $items, ?int $defaultSupplier): array
    {
        self::requireMeasurementSchema($pdo);
        if (!is_array($items) || !$items || count($items)>500) jsonError('Provide between 1 and 500 order items',422);
        foreach ($items as &$item) {
            if (!is_array($item)) jsonError('Each order item must be an object',422);
            self::validateRawNumbers($item);
            foreach (['image_paths','photo_paths','custom_design_paths'] as $field) if (isset($item[$field])) $item[$field]=normalizeStoredUploadPathList($item[$field]);
            $cartons=self::number($item['cartons']??null,'Cartons',true);
            $packing=self::number($item['qty_per_carton']??null,'Pieces per carton');
            $quantity=self::number($item['quantity']??null,'Quantity');
            if ($cartons>0 && $packing>0) {
                $calculated=self::number($cartons*$packing,'Packed quantity');
                if ($quantity!==null && abs($quantity-$calculated)>.0001) jsonError('Quantity must equal cartons × pieces per carton',422);
                $quantity=$calculated;
            }
            if (!$quantity || !in_array($item['unit']??'pieces',['pieces','cartons'],true)) jsonError('Items require positive quantity and a supported unit',422);
            $item['quantity']=$quantity;
            $item['unit']=ReceivingQuantityService::quantityUnit($item);
            $supplier=(int)($item['supplier_id']??$defaultSupplier);
            SupplierLifecycleService::requireActive($pdo,$supplier);
            $contents=$item['shared_carton_contents']??[];if(is_string($contents))$contents=json_decode($contents,true)??[];
            foreach(is_array($contents)?$contents:[] as $content)if(is_array($content))SupplierLifecycleService::requireActive($pdo,(int)($content['supplier_id']??0));
            $product=[];
            if (!empty($item['product_id'])) {
                $s=$pdo->prepare('SELECT supplier_id,description_cn,description_en,dimensions_scope FROM products WHERE id=?');$s->execute([$item['product_id']]);$product=$s->fetch(PDO::FETCH_ASSOC);
                if (!$product) jsonError('Item product not found',422);
                SupplierLifecycleService::requireActive($pdo,(int)($product['supplier_id']??0));
                if ($supplier && !empty($product['supplier_id']) && (int)$product['supplier_id']!==$supplier) jsonError('Selected product belongs to another supplier',422);
                if (empty($item['description_cn']) && empty($item['description_en'])) { $item['description_cn']=$product['description_cn'];$item['description_en']=$product['description_en']; }
            }
            $item['dimensions_scope']=$item['dimensions_scope']??$product['dimensions_scope']??'piece';
            if($item['dimensions_scope']==='carton' && !($cartons>0))jsonError('Carton measurements require a positive carton count',422);
            if (trim((string)($item['description_cn']??''))==='' && trim((string)($item['description_en']??''))==='') jsonError('Each item needs a description',422);
        }
        unset($item);return $items;
    }

    public static function date($value): ?string
    {
        if ($value===null || $value==='') return null;
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$value,$m) || !checkdate((int)$m[2],(int)$m[3],(int)$m[1])) jsonError('Expected ready date must be a valid YYYY-MM-DD date',422);
        return $value;
    }

    public static function requestKey($value): string
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9._:-]{8,64}$/',$value)) jsonError('A valid order idempotency key is required',422);
        return $value;
    }

    public static function requestHash(array $input): string
    {
        unset($input['idempotency_key'],$input['creation_idempotency_key']);
        $sort=static function ($value) use (&$sort) {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value);
            foreach ($value as &$entry) $entry=$sort($entry);
            return $value;
        };
        return hash('sha256',json_encode($sort($input),JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
    }

    public static function replay(PDO $pdo,string $key,array $input,string $type,int $userId): ?int
    {
        $s=$pdo->prepare('SELECT id,order_type,created_by FROM orders WHERE creation_idempotency_key=?'.($pdo->inTransaction()?' FOR UPDATE':''));$s->execute([$key]);$order=$s->fetch(PDO::FETCH_ASSOC);
        if (!$order) return null;
        if (($order['order_type']?:'standard')!==$type || (int)$order['created_by']!==$userId) jsonError('Order idempotency key belongs to another request',409);
        $s=$pdo->prepare("SELECT new_value FROM audit_log WHERE entity_type='order' AND entity_id=? AND action='create' ORDER BY id LIMIT 1".($pdo->inTransaction()?' FOR UPDATE':''));$s->execute([$order['id']]);$audit=json_decode((string)$s->fetchColumn(),true);
        if (empty($audit['request_hash']) || !hash_equals($audit['request_hash'],self::requestHash($input))) jsonError('Order idempotency key payload differs or cannot be verified; use the saved order or a new request',409);
        return (int)$order['id'];
    }

    public static function assertFinancialIdentity(PDO $pdo,array $order,int $customerId,string $currency): void
    {
        if ((int)$order['customer_id']===$customerId && $order['currency']===$currency) return;
        foreach (['balance_transactions','draft_order_costs','shipment_financial_entries','customer_deposits','supplier_payments'] as $table) {
            $s=$pdo->prepare("SELECT id FROM $table WHERE order_id=? LIMIT 1 FOR UPDATE");$s->execute([$order['id']]);
            if ($s->fetchColumn()) jsonError('Customer and currency cannot change after order financial entries exist',409);
        }
    }

    public static function prepareReplacement(PDO $pdo,int $orderId,array $items): array
    {
        $s=$pdo->prepare('SELECT id FROM order_items WHERE order_id=? FOR UPDATE');$s->execute([$orderId]);$existing=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));$retained=[];
        foreach($items as $item){$id=(int)($item['existing_item_id']??$item['id']??0);if(!$id)continue;
            if(!in_array($id,$existing,true)||in_array($id,$retained,true))jsonError('Invalid or duplicated existing order item',409);$retained[]=$id;}
        $s=$pdo->prepare('SELECT reservation_id FROM item_number_references WHERE order_id=? FOR UPDATE');$s->execute([$orderId]);$reservations=$s->fetchAll(PDO::FETCH_COLUMN);
        $pdo->prepare('DELETE FROM item_number_references WHERE order_id=?')->execute([$orderId]);
        foreach(array_diff($existing,$retained) as $id){
            $pdo->prepare("DELETE FROM design_attachments WHERE entity_type='order_item' AND entity_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM item_classifications WHERE entity_type='order_item' AND entity_id=?")->execute([$id]);
            $pdo->prepare('DELETE FROM order_items WHERE id=? AND order_id=?')->execute([$id,$orderId]);
        }
        foreach($reservations as $id)$pdo->prepare('DELETE r FROM item_number_reservations r LEFT JOIN item_number_references ref ON ref.reservation_id=r.id WHERE r.id=? AND ref.id IS NULL')->execute([$id]);
        return $retained;
    }
}
