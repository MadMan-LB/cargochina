<?php

require_once dirname(__DIR__, 2) . '/includes/sidebar_permissions.php';
require_once dirname(__DIR__, 2) . '/includes/customer_visibility.php';

final class NotificationTargetService
{
    public function __construct(private PDO $pdo) {}

    public function resolve(array $notification, int $userId, array $roles): array
    {
        [$type,$id,$legacy] = $this->structuredOrLegacyTarget($notification);
        if ($type === null || $id <= 0) {
            return ['available'=>false,'reason'=>clmsT('This notification does not have a safely linkable target.'),'legacy'=>true];
        }
        $resolved = $this->resolveKnownTarget($type,$id,$userId,$roles);
        $resolved['target_type']=$type;$resolved['target_id']=$id;$resolved['legacy']=$legacy;
        return $resolved;
    }

    private function structuredOrLegacyTarget(array $notification): array
    {
        $type=strtolower(trim((string)($notification['target_type']??'')));
        $id=(int)($notification['target_id']??0);
        if($type!==''&&$id>0)return[$type,$id,false];
        $event=(string)($notification['type']??'');$title=(string)($notification['title']??'');
        $orderEvents=['order_created','order_submitted','order_approved','order_received','variance_confirmation','cross_supplier_price_difference','order_declined'];
        if(in_array($event,$orderEvents,true)&&preg_match('/^Order #(\d+)\b/u',$title,$m))return['order',(int)$m[1],true];
        if($event==='shipment_finalized'&&preg_match('/^Shipment draft #(\d+)\b/u',$title,$m))return['shipment_draft',(int)$m[1],true];
        if($event==='container_arrival'&&preg_match('/^Container\s+(.+?)\s+—/u',$title,$m)){
            $stmt=$this->pdo->prepare('SELECT id FROM containers WHERE code=? LIMIT 2');$stmt->execute([trim($m[1])]);$ids=$stmt->fetchAll(PDO::FETCH_COLUMN);
            if(count($ids)===1)return['container',(int)$ids[0],true];
        }
        return[null,0,true];
    }

    private function pageAllowed(string $pageId,int $userId,array $roles): bool
    {
        return clmsCanRolesAccessPage($roles,$pageId,$this->pdo,$userId);
    }

    private function unavailable(string $reason): array
    {
        return ['available'=>false,'reason'=>clmsT($reason),'url'=>null];
    }

    private function resolveKnownTarget(string $type,int $id,int $userId,array $roles): array
    {
        switch($type){
            case 'order':
                $stmt=$this->pdo->prepare('SELECT id,order_type,customer_id FROM orders WHERE id=?');$stmt->execute([$id]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
                if(!$row)return $this->unavailable('The related record no longer exists.');
                $draft=($row['order_type']??'')==='draft_procurement';$page=$draft?'procurement_drafts':'orders';
                if(!$this->pageAllowed($page,$userId,$roles)||!clmsCanAccessCustomer($this->pdo,(int)$row['customer_id'],$userId,$roles))return $this->unavailable('You no longer have permission to open this record.');
                return ['available'=>true,'reason'=>null,'url'=>$draft?'/cargochina/procurement_drafts.php?order_id='.$id:'/cargochina/orders.php?order_id='.$id];
            case 'procurement_draft':
                if(!$this->pageAllowed('procurement_drafts',$userId,$roles))return $this->unavailable('You no longer have permission to open this record.');
                $stmt=$this->pdo->prepare('SELECT id FROM procurement_drafts WHERE deleted_at IS NULL AND id=?');$stmt->execute([$id]);
                return $stmt->fetchColumn()?['available'=>true,'reason'=>null,'url'=>'/cargochina/procurement_drafts.php?legacy_draft_id='.$id]:$this->unavailable('The related record no longer exists.');
            case 'shipment_draft':
                if(!$this->pageAllowed('consolidation',$userId,$roles))return $this->unavailable('You no longer have permission to open this record.');
                $stmt=$this->pdo->prepare('SELECT id FROM shipment_drafts WHERE deleted_at IS NULL AND id=?');$stmt->execute([$id]);
                return $stmt->fetchColumn()?['available'=>true,'reason'=>null,'url'=>'/cargochina/consolidation.php?shipment_draft_id='.$id]:$this->unavailable('The related record no longer exists.');
            case 'container':
                if(!$this->pageAllowed('containers',$userId,$roles))return $this->unavailable('You no longer have permission to open this record.');
                $stmt=$this->pdo->prepare('SELECT id FROM containers WHERE id=?');$stmt->execute([$id]);
                return $stmt->fetchColumn()?['available'=>true,'reason'=>null,'url'=>'/cargochina/containers.php?container_id='.$id]:$this->unavailable('The related record no longer exists.');
            case 'receiving_record':
                if(!$this->pageAllowed('receiving',$userId,$roles))return $this->unavailable('You no longer have permission to open this record.');
                $stmt=$this->pdo->prepare('SELECT wr.id,wr.order_id,o.customer_id FROM warehouse_receipts wr JOIN orders o ON o.id=wr.order_id WHERE wr.id=?');$stmt->execute([$id]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
                if(!$row)return $this->unavailable('The related record no longer exists.');
                if(!clmsCanAccessCustomer($this->pdo,(int)$row['customer_id'],$userId,$roles))return $this->unavailable('You no longer have permission to open this record.');
                return ['available'=>true,'reason'=>null,'url'=>'/cargochina/receiving.php?receipt_id='.$id.'&order_id='.(int)$row['order_id']];
            case 'customer':
                if(!$this->pageAllowed('customers',$userId,$roles)||!clmsCanAccessCustomer($this->pdo,$id,$userId,$roles))return $this->unavailable('You no longer have permission to open this record.');
                return ['available'=>true,'reason'=>null,'url'=>'/cargochina/customers.php?customer_id='.$id];
            case 'supplier':
                if(!$this->pageAllowed('suppliers',$userId,$roles))return $this->unavailable('You no longer have permission to open this record.');
                $stmt=$this->pdo->prepare('SELECT id FROM suppliers WHERE id=?');$stmt->execute([$id]);
                return $stmt->fetchColumn()?['available'=>true,'reason'=>null,'url'=>'/cargochina/suppliers.php?supplier_id='.$id]:$this->unavailable('The related record no longer exists.');
            case 'supplier_payment':
            case 'payment':
                if(!$this->pageAllowed('suppliers',$userId,$roles))return $this->unavailable('You no longer have permission to open this record.');
                $stmt=$this->pdo->prepare('SELECT id,supplier_id FROM supplier_payments WHERE id=?');$stmt->execute([$id]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
                return $row?['available'=>true,'reason'=>null,'url'=>'/cargochina/suppliers.php?supplier_id='.(int)$row['supplier_id'].'&payment_id='.$id]:$this->unavailable('The related record no longer exists.');
            case 'balance_transaction':
                if(!$this->pageAllowed('balances',$userId,$roles))return $this->unavailable('You no longer have permission to open this record.');
                $stmt=$this->pdo->prepare('SELECT id FROM balance_transactions WHERE id=?');$stmt->execute([$id]);
                return $stmt->fetchColumn()?['available'=>true,'reason'=>null,'url'=>'/cargochina/balances.php?transaction_id='.$id.'#transactions']:$this->unavailable('The related record no longer exists.');
        }
        return $this->unavailable('This notification target type is not supported.');
    }
}
