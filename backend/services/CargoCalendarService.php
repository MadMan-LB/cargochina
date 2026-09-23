<?php
require_once dirname(__DIR__,2).'/includes/customer_visibility.php';

/** Read-only projections of canonical dates. No independently editable calendar dates. */
final class CargoCalendarService
{
    public const LABELS = [
        'created'=>'Order / draft created','approved'=>'Approved','expected_receipt'=>'Expected ready / receipt',
        'received'=>'Received / Warehouse','fully_received'=>'Fully received','assigned'=>'Container assigned',
        'planned_departure'=>'Planned departure','departed'=>'Departed','eta'=>'ETA','arrived'=>'Arrived',
        'finalized'=>'Shipment finalized',
    ];
    private const MAX_ROWS=2000;

    public static function filters(array $input): array
    {
        $out=[];
        foreach(['from','to'] as $field){
            $v=$input[$field]??'';
            if(!is_string($v)||!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$v,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))throw new DomainException('A valid visible date range is required',422);
            $out[$field]=$v;
        }
        $days=(strtotime($out['to'])-strtotime($out['from']))/86400;
        if($days<1||$days>62)throw new DomainException('Choose a date range of 1–62 days',422);
        foreach(['event_type','customer','order','container','status'] as $field){
            $v=$input[$field]??'';
            if(!is_string($v)||mb_strlen($v)>120)throw new DomainException('Invalid calendar filter',422);
            $out[$field]=trim($v);
        }
        if($out['event_type']!==''&&!isset(self::LABELS[$out['event_type']]))throw new DomainException('Unknown calendar event type',422);
        return $out;
    }

    private static function rows(PDO $pdo,string $sql,array $params): array
    {
        $s=$pdo->prepare($sql.' LIMIT '.(self::MAX_ROWS+1));$s->execute($params);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>self::MAX_ROWS)throw new DomainException('This range has too many events. Choose a week or day to see all events.',422);
        return $rows;
    }

    public static function events(PDO $pdo,array $f,bool $ordersAllowed,bool $containersAllowed): array
    {
        $raw=[];$orderIds=[];$containerIds=[];$range=[$f['from'],$f['to']];
        // Apply visibility and business filters before the source row limit.
        $sourceScope=clmsCustomerVisibilityClause($pdo,'c');$sourceWhere=[$sourceScope['sql']];$sourceParams=$sourceScope['params'];
        if($f['customer']!==''){$sourceWhere[]='(c.name LIKE ? OR c.id=?)';array_push($sourceParams,clmsSearchLike($f['customer']),ctype_digit($f['customer'])?(int)$f['customer']:0);}
        if($f['order']!==''){$sourceWhere[]='CAST(o.id AS CHAR) LIKE ?';$sourceParams[]=clmsSearchLike($f['order']);}
        if($f['status']!==''){$sourceWhere[]='o.status=?';$sourceParams[]=$f['status'];}
        $sourceSql=implode(' AND ',$sourceWhere);
        $want=static fn(string $type)=>$f['event_type']===''||$f['event_type']===$type;
        $add=static function(string $type,string $date,string $record,int $id,string $source,?int $order=null,?int $container=null,array $extra=[])use(&$raw,&$orderIds,&$containerIds,$want):void{
            if(!$want($type))return;
            $raw[]=['event_type'=>$type,'date'=>$date,'record_type'=>$record,'record_id'=>$id,'source'=>$source,'order_id'=>$order,'container_id'=>$container]+$extra;
            if($order)$orderIds[$order]=true;if($container)$containerIds[$container]=true;
            if(count($raw)>self::MAX_ROWS)throw new DomainException('Too many calendar events. Choose a smaller date range.',422);
        };
        if($ordersAllowed){
            foreach(['created'=>'created_at','expected_receipt'=>'expected_ready_date'] as $type=>$field){
                if(!$want($type))continue;
                foreach(self::rows($pdo,"SELECT o.id,o.$field event_date FROM orders o JOIN customers c ON c.id=o.customer_id WHERE o.$field>=? AND o.$field<? AND $sourceSql",array_merge($range,$sourceParams)) as $r)$add($type,$r['event_date'],'order',(int)$r['id'],"orders.$field",(int)$r['id']);
            }
            if($want('received'))foreach(self::rows($pdo,"SELECT wr.id,wr.order_id,wr.received_at,wr.receipt_condition FROM warehouse_receipts wr JOIN orders o ON o.id=wr.order_id JOIN customers c ON c.id=o.customer_id WHERE wr.voided_at IS NULL AND wr.received_at>=? AND wr.received_at<? AND $sourceSql",array_merge($range,$sourceParams)) as $r)
                $add('received',$r['received_at'],'receipt',(int)$r['id'],'warehouse_receipts.received_at',(int)$r['order_id'],null,['condition'=>$r['receipt_condition']]);
            if($want('approved')||$want('fully_received')){
                $audits=self::rows($pdo,"SELECT a.id,a.entity_id,a.action,a.created_at,a.new_value FROM audit_log a JOIN orders o ON o.id=a.entity_id JOIN customers c ON c.id=o.customer_id WHERE a.entity_type='order' AND a.action IN ('approve','receive') AND a.created_at>=? AND a.created_at<? AND $sourceSql",array_merge($range,$sourceParams));
                $receiptIds=[];
                foreach($audits as $a){$j=json_decode($a['new_value']??'',true);if($a['action']==='receive'&&!empty($j['receipt_id']))$receiptIds[(int)$j['receipt_id']]=true;}
                $activeReceipts=[];
                foreach(array_chunk(array_keys($receiptIds),500) as $ids){$ph=implode(',',array_fill(0,count($ids),'?'));foreach(self::rows($pdo,"SELECT id,order_id,received_at FROM warehouse_receipts WHERE voided_at IS NULL AND id IN ($ph)",$ids) as $r)$activeReceipts[(int)$r['id']]=$r;}
                foreach($audits as $a){
                    if($a['action']==='approve')$add('approved',$a['created_at'],'order',(int)$a['entity_id'],'audit_log:'.$a['id'],(int)$a['entity_id']);
                    else {
                        $j=json_decode($a['new_value']??'',true);$r=$activeReceipts[(int)($j['receipt_id']??0)]??null;$q=$j['quantity_totals']??[];
                        if($r&&(int)$r['order_id']===(int)$a['entity_id']&&isset($q['remaining'])&&(float)$q['remaining']===0.0&&(float)($q['ordered']??0)>0)
                            $add('fully_received',$r['received_at'],'receipt',(int)$r['id'],'audit_log:'.$a['id'].' / warehouse_receipts.received_at',(int)$a['entity_id']);
                    }
                }
            }
        }
        if($containersAllowed){
            foreach(['planned_departure'=>'expected_ship_date','departed'=>'actual_departure_date','eta'=>'eta_date','arrived'=>'actual_arrival_date'] as $type=>$field){
                if(!$want($type))continue;
                foreach(self::rows($pdo,"SELECT id,$field event_date FROM containers WHERE $field>=? AND $field<?",$range) as $r)$add($type,$r['event_date'],'container',(int)$r['id'],"containers.$field",null,(int)$r['id']);
            }
        }
        if($ordersAllowed&&$containersAllowed&&($want('assigned')||$want('finalized'))){
            // Audit timestamp is the assignment/finalization event, never physical loading.
            $audits=self::rows($pdo,"SELECT a.id,a.entity_id,a.action,a.created_at,a.new_value,sd.container_id FROM audit_log a JOIN shipment_drafts sd ON sd.id=a.entity_id AND sd.deleted_at IS NULL WHERE a.entity_type='shipment_draft' AND a.action IN ('assign_orders','assign_container','add_orders','finalize') AND a.created_at>=? AND a.created_at<?",$range);
            foreach($audits as $a){$j=json_decode($a['new_value']??'',true);if(!is_array($j))continue;
                $container=(int)($j['container_id']??($a['action']==='finalize'?$a['container_id']:0));
                if(!$container)continue;
                foreach(array_unique(array_filter(is_array($j['order_ids']??null)?$j['order_ids']:[],'is_numeric')) as $order)$add($a['action']==='finalize'?'finalized':'assigned',$a['created_at'],'order',(int)$order,'audit_log:'.$a['id'],(int)$order,$container,['shipment_id'=>(int)$a['entity_id']]);
            }
        }
        // For an order/customer timeline, project container milestones only through
        // genuine current memberships. Do not infer historical cargo from the container date.
        if($ordersAllowed&&$containersAllowed&&($f['customer']!==''||$f['order']!=='')&&$containerIds){
            $cargo=[];
            foreach(array_chunk(array_keys($containerIds),500) as $ids){$ph=implode(',',array_fill(0,count($ids),'?'));
                foreach(self::rows($pdo,"SELECT DISTINCT sd.container_id,so.order_id FROM shipment_drafts sd JOIN shipment_draft_orders so ON so.shipment_draft_id=sd.id WHERE sd.deleted_at IS NULL AND sd.container_id IN ($ph)",$ids) as $r){$cargo[(int)$r['container_id']][]=(int)$r['order_id'];$orderIds[(int)$r['order_id']]=true;}
            }
            $expanded=[];
            foreach($raw as $r){if($r['record_type']==='container')foreach($cargo[$r['container_id']]??[] as $order){$copy=$r;$copy['order_id']=$order;$expanded[]=$copy;}else $expanded[]=$r;}
            if(count($expanded)>self::MAX_ROWS)throw new DomainException('Too many cargo milestones. Choose a smaller date range.',422);
            $raw=$expanded;
        }
        // Resolve all visible order/customer context in bounded batches, not one query per event.
        $orders=[];$scope=clmsCustomerVisibilityClause($pdo,'c');
        foreach(array_chunk(array_keys($orderIds),500) as $ids){
            $ph=implode(',',array_fill(0,count($ids),'?'));$params=$ids;$where=["o.id IN ($ph)",$scope['sql']];array_push($params,...$scope['params']);
            if($f['customer']!==''){$where[]='(c.name LIKE ? OR c.id=?)';array_push($params,clmsSearchLike($f['customer']),ctype_digit($f['customer'])?(int)$f['customer']:0);}
            if($f['order']!==''){$where[]='CAST(o.id AS CHAR) LIKE ?';$params[]=clmsSearchLike($f['order']);}
            if($f['status']!==''){$where[]='o.status=?';$params[]=$f['status'];}
            foreach(self::rows($pdo,'SELECT o.id,o.status,o.order_type,c.id customer_id,c.name customer FROM orders o JOIN customers c ON c.id=o.customer_id WHERE '.implode(' AND ',$where),$params) as $r)$orders[(int)$r['id']]=$r;
        }
        $memberships=[];
        if($containersAllowed)foreach(array_chunk(array_keys($orders),500) as $ids){
            $ph=implode(',',array_fill(0,count($ids),'?'));
            foreach(self::rows($pdo,"SELECT so.order_id,sd.container_id FROM shipment_draft_orders so JOIN shipment_drafts sd ON sd.id=so.shipment_draft_id AND sd.deleted_at IS NULL WHERE so.order_id IN ($ph) AND sd.container_id IS NOT NULL",$ids) as $r){$memberships[(int)$r['order_id']][]=(int)$r['container_id'];$containerIds[(int)$r['container_id']]=true;}
        }
        $containers=[];
        foreach(array_chunk(array_keys($containerIds),500) as $ids){$ph=implode(',',array_fill(0,count($ids),'?'));foreach(self::rows($pdo,"SELECT id,code,status FROM containers WHERE id IN ($ph)",$ids) as $r)$containers[(int)$r['id']]=$r;}
        $events=[];
        foreach($raw as $r){
            $order=$r['order_id']?($orders[$r['order_id']]??null):null;
            if($r['order_id']&&!$order)continue;
            if(!$r['container_id']&&$order&&count($memberships[(int)$order['id']]??[])===1){$r['container_id']=$memberships[(int)$order['id']][0];$r['container_context']='Current reservation';}
            elseif($r['container_id'])$r['container_context']='Event container';
            $container=$r['container_id']?($containers[$r['container_id']]??null):null;
            if($r['container_id']&&!$container)continue;
            if($f['container']!==''&&(!$container||(mb_stripos($container['code'],$f['container'])===false&&(string)$container['id']!==$f['container'])))continue;
            if(!$order){
                // Container-only milestones do not claim a particular customer's cargo was aboard.
                if($f['customer']!==''||$f['order']!=='')continue;
                if($f['status']!==''&&$container['status']!==$f['status'])continue;
                if($f['container']!==''&&mb_stripos($container['code'],$f['container'])===false&&(string)$container['id']!==$f['container'])continue;
            }
            $r['label']=self::LABELS[$r['event_type']];
            if($r['event_type']==='created'&&($order['order_type']??'')==='draft_procurement')$r['label']='Draft order created';
            $r['reference']=$order?'Order #'.$order['id']:$container['code'];
            if($r['record_type']==='container'&&$order)$r['reference']=$container['code'].' — '.$r['reference'];
            $r['customer']=$order['customer']??null;$r['customer_id']=$order['customer_id']??null;
            $r['container']=$container['code']??null;$r['status']=$order['status']??$container['status'];
            if($r['record_type']==='container'){$r['status']=$container['status'];$r['order_status']=$order['status']??null;}
            $r['link']=$r['record_type']==='container'?'/cargochina/containers.php?container_id='.$container['id']:'/cargochina/orders.php?order_id='.$order['id'];
            $r['id']=$r['event_type'].':'.$r['source'].':'.$r['record_id'].':'.($r['order_id']??'');$events[]=$r;
        }
        usort($events,static fn($a,$b)=>[$a['date'],$a['id']]<=>[$b['date'],$b['id']]);
        return $events;
    }
}
