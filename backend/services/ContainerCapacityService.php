<?php
require_once __DIR__.'/DecimalMath.php';

class ContainerCapacityException extends RuntimeException
{
    public function __construct(string $message,public array $details=[],public bool $overCapacity=false,public int $httpStatus=409){parent::__construct($message);}
}

/** One capacity rule for direct assignment, draft assignment/addition, and edits. */
final class ContainerCapacityService
{
    public static function limit($value,string $name):string
    {
        if(!is_scalar($value) || is_bool($value) || !is_numeric($value) || !is_finite((float)$value)) throw new ContainerCapacityException("$name must be a finite positive decimal",[],false,400);
        $number=(float)$value;
        if($number<=0 || $number>999999.9999)throw new ContainerCapacityException("$name must be between 0.0001 and 999999.9999",[],false,400);
        $formatted=number_format($number,4,'.','');
        if(abs($number-(float)$formatted)>0.000000001 || (float)$formatted<=0)throw new ContainerCapacityException("$name supports at most four decimal places",[],false,400);
        return $formatted;
    }

    /** Caller holds the container row lock in a transaction. Locking reads avoid
     * an earlier repeatable-read snapshot missing a concurrent committed load. */
    public static function check(PDO $pdo,array $container,array $additionalOrderIds=[]):array
    {
        if(!$pdo->inTransaction())throw new LogicException('Capacity mutation requires a transaction');
        $maxCbm=self::limit($container['max_cbm']??null,'Max CBM');
        $maxWeight=self::limit($container['max_weight']??null,'Max weight');
        $existing=$pdo->prepare('SELECT sdo.order_id FROM shipment_drafts sd JOIN shipment_draft_orders sdo ON sdo.shipment_draft_id=sd.id WHERE sd.container_id=? ORDER BY sdo.order_id FOR UPDATE');
        $existing->execute([$container['id']]);
        $ids=array_values(array_unique(array_map('intval',array_merge($existing->fetchAll(PDO::FETCH_COLUMN),$additionalOrderIds))));sort($ids,SORT_NUMERIC);
        $cbm='0.000000';$weight='0.0000';
        foreach($ids as $orderId){
            $order=$pdo->prepare('SELECT id FROM orders WHERE id=? FOR UPDATE');$order->execute([$orderId]);
            if(!$order->fetchColumn())throw new ContainerCapacityException("Order #$orderId no longer exists");
            $receipts=$pdo->prepare('SELECT id,actual_cbm,actual_weight FROM warehouse_receipts WHERE order_id=? AND voided_at IS NULL ORDER BY id FOR UPDATE');$receipts->execute([$orderId]);$rows=$receipts->fetchAll(PDO::FETCH_ASSOC);
            if(!$rows)throw new ContainerCapacityException("Order #$orderId has no active measured receipt; reconcile cargo before assignment");
            foreach($rows as $receipt){
                if($receipt['actual_cbm']===null || $receipt['actual_weight']===null || (float)$receipt['actual_cbm']<=0 || (float)$receipt['actual_weight']<0)throw new ContainerCapacityException("Order #$orderId has incomplete actual measurements; reconcile cargo before assignment");
                $cbm=DecimalMath::add($cbm,$receipt['actual_cbm'],6);$weight=DecimalMath::add($weight,$receipt['actual_weight'],4);
            }
        }
        $details=['totalCbm'=>(float)$cbm,'totalWeight'=>(float)$weight,'maxCbm'=>(float)$maxCbm,'maxWeight'=>(float)$maxWeight];
        if(DecimalMath::compare($cbm,$maxCbm,6)>0 || DecimalMath::compare($weight,$maxWeight,4)>0)throw new ContainerCapacityException("Capacity exceeded: $cbm / $maxCbm CBM; $weight / $maxWeight kg. Choose another container or reduce the load.",$details,true);
        return ['used_cbm'=>(float)$cbm,'used_weight'=>(float)$weight,'order_count'=>count($ids),'order_ids'=>$ids];
    }
}
