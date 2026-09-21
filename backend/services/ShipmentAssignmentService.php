<?php
require_once __DIR__.'/CargoMetricsService.php';
require_once __DIR__.'/DecimalMath.php';

class ShipmentAssignmentException extends RuntimeException {}

/** Reservation invariants shared by direct-container and shipment-draft paths. */
final class ShipmentAssignmentService
{
    public static function eligible(array $order):bool
    {
        $cargo=$order['cargo_totals']??[];
        return in_array($order['status']??'',['ReadyForConsolidation','Confirmed'],true)
            && trim((string)($order['confirmation_token']??''))===''
            && !empty($cargo['fully_received']) && !empty($cargo['measured_cargo_complete'])
            && empty($cargo['reservation_count']);
    }

    public static function eligibleSql(PDO $pdo):string
    {
        return "o.status IN ('ReadyForConsolidation','Confirmed') AND COALESCE(o.confirmation_token,'')='' AND EXISTS(SELECT 1 FROM (".CargoMetricsService::orderTotalsSql($pdo).") eligible_cargo WHERE eligible_cargo.order_id=o.id AND eligible_cargo.fully_received=1 AND eligible_cargo.measured_cargo_complete=1 AND eligible_cargo.reservation_count=0)";
    }

    public static function ids($values):array
    {
        if(!is_array($values) || !$values || count($values)>200)throw new ShipmentAssignmentException('Select between 1 and 200 orders');
        foreach($values as $value)if(!is_scalar($value) || is_bool($value) || !ctype_digit((string)$value) || (int)$value<1)throw new ShipmentAssignmentException('Order IDs must be positive integers');
        $ids=array_values(array_unique(array_map('intval',$values)));sort($ids,SORT_NUMERIC);return $ids;
    }

    public static function containerIsOpen(PDO $pdo,array $container,bool $lock=false):bool
    {
        if(!in_array($container['status']??'',['planning','to_go'],true))return false;
        $s=$pdo->prepare("SELECT id FROM shipment_drafts WHERE container_id=? AND status='finalized' LIMIT 1".($lock?' FOR UPDATE':''));$s->execute([$container['id']]);
        return !$s->fetchColumn();
    }

    public static function assertContainerOpen(PDO $pdo,array $container):void
    {
        if(!self::containerIsOpen($pdo,$container,true))throw new ShipmentAssignmentException('Container is locked for assignment by its state or finalized cargo');
    }

    public static function memberships(PDO $pdo,int $orderId):array
    {
        $s=$pdo->prepare('SELECT sdo.shipment_draft_id,sd.container_id,sd.status FROM shipment_draft_orders sdo JOIN shipment_drafts sd ON sd.id=sdo.shipment_draft_id WHERE sdo.order_id=? ORDER BY sd.id FOR UPDATE');$s->execute([$orderId]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>1)throw new ShipmentAssignmentException("Order #$orderId has conflicting reservations; reconcile them before changing assignment");
        return $rows;
    }

    /** Current locking reads, independent of any earlier transaction snapshot. */
    public static function assertReceived(PDO $pdo,int $orderId):void
    {
        if(!$pdo->inTransaction())throw new LogicException('Assignment validation requires a transaction');
        $s=$pdo->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id FOR UPDATE');$s->execute([$orderId]);$items=$s->fetchAll(PDO::FETCH_ASSOC);
        if(!$items)throw new ShipmentAssignmentException("Order #$orderId has no cargo items");
        $itemIds=array_fill_keys(array_column($items,'id'),true);
        $s=$pdo->prepare('SELECT * FROM warehouse_receipts WHERE order_id=? AND voided_at IS NULL ORDER BY id FOR UPDATE');$s->execute([$orderId]);$receipts=$s->fetchAll(PDO::FETCH_ASSOC);
        if(!$receipts)throw new ShipmentAssignmentException("Order #$orderId has no active receipt");
        $quantity=ReceivingQuantityService::legacyQuantitySql();
        $s=$pdo->prepare("SELECT wri.*,($quantity) canonical_quantity FROM warehouse_receipt_items wri JOIN order_items oi ON oi.id=wri.order_item_id WHERE wri.receipt_id=? ORDER BY wri.order_item_id FOR UPDATE");
        $received=[];
        foreach($receipts as $receipt){
            if($receipt['actual_cbm']===null || (float)$receipt['actual_cbm']<=0 || $receipt['actual_weight']===null || (float)$receipt['actual_weight']<0)throw new ShipmentAssignmentException("Order #$orderId has incomplete actual measurements");
            $s->execute([$receipt['id']]);$lines=$s->fetchAll(PDO::FETCH_ASSOC);
            if(!$lines)throw new ShipmentAssignmentException("Order #$orderId has an unallocated receipt; reconciliation is required");
            $totals=['actual_cartons'=>'0','actual_cbm'=>'0','actual_weight'=>'0'];
            foreach($lines as $line){
                if(!isset($itemIds[$line['order_item_id']]))throw new ShipmentAssignmentException("Order #$orderId has a receipt allocated to another order's item");
                if($line['canonical_quantity']===null)throw new ShipmentAssignmentException("Order #$orderId has unknown received quantities");
                $received[$line['order_item_id']]=DecimalMath::add($received[$line['order_item_id']]??'0',$line['canonical_quantity'],4);
                foreach($totals as $key=>$value){if($line[$key]===null)throw new ShipmentAssignmentException("Order #$orderId has incomplete item measurements");$totals[$key]=DecimalMath::add($value,$line[$key],$key==='actual_cbm'?6:4);}
            }
            foreach($totals as $key=>$value)if($receipt[$key]===null || DecimalMath::compare($value,$receipt[$key],$key==='actual_cbm'?6:4)!==0)throw new ShipmentAssignmentException("Order #$orderId receipt and item totals do not reconcile");
        }
        foreach($items as $item){
            $ordered=number_format(ReceivingQuantityService::ordered($item),4,'.','');
            if(DecimalMath::compare($ordered,'0',4)<=0 || DecimalMath::compare($received[$item['id']]??'0',$ordered,4)!==0)throw new ShipmentAssignmentException("Order #$orderId must be fully received without excess quantities before assignment");
        }
    }

    public static function assertFeedbackResolved(array $order):void
    {
        if(trim((string)($order['confirmation_token']??''))!=='')throw new ShipmentAssignmentException('Customer feedback must be resolved before assignment');
    }

    public static function audit(PDO $pdo,string $action,int $entityId,array $old,array $new,int $userId):void
    {
        $pdo->prepare("INSERT INTO audit_log(entity_type,entity_id,action,old_value,new_value,user_id) VALUES ('shipment_draft',?,?,?,?,?)")->execute([$entityId,$action,json_encode($old),json_encode($new),$userId]);
    }
}
