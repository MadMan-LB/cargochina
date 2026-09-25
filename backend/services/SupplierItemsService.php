<?php
require_once dirname(__DIR__,2).'/includes/customer_visibility.php';

final class SupplierItemsService
{
    public static function listing(PDO $pdo,int $supplier,array $query): array
    {
        $kind=$query['kind']??'orders';
        if(!in_array($kind,['orders','products'],true))jsonError('Invalid item view',422);
        requirePermission($kind==='orders'?'orders.read':'products.read');
        $q=clmsNormalizeSearchQuery($query['q']??'');
        $limit=clmsQueryLimit($query['limit']??null,25,100);$offset=clmsQueryOffset($query['offset']??null);
        if($kind==='products'){
            $params=[$supplier];$where='p.supplier_id=?';
            if($q!==''){$where.=' AND (p.description_cn LIKE ? OR p.description_en LIKE ? OR CAST(p.id AS CHAR)=?)';array_push($params,clmsSearchLike($q),clmsSearchLike($q),$q);}
            $from='FROM products p WHERE '.$where;
            $cols='p.id,p.description_cn,p.description_en,p.hs_code';
            $order='p.id DESC';
        }else{
            $params=[$supplier];
            $shared=clmsSharedCartonSupplierPredicate('oi.shared_carton_contents',$pdo,$supplier,$params);
            // Item-level supplier overrides the header. Shared-carton contents are separate links.
            $where="(COALESCE(NULLIF(oi.supplier_id,0),o.supplier_id)=? OR $shared)";
            $scope=clmsCustomerVisibilityClause($pdo,'c');$where.=' AND ('.$scope['sql'].')';$params=array_merge($params,$scope['params']);
            if($q!==''){
                $regex='~'.str_replace(' ','.*',preg_quote($q,'~')).'~isu';
                $matching=clmsSharedCartonMatchingIds($pdo,static function(array $content)use($supplier,$regex):bool{
                    if((string)($content['supplier_id']??'')!==(string)$supplier)return false;
                    foreach(['item_no','item_number','description_cn','description_en','description'] as $key)if(is_string($content[$key]??null)&&preg_match($regex,$content[$key])===1)return true;
                    return false;
                });
                $where.=' AND (oi.description_cn LIKE ? OR oi.description_en LIKE ? OR oi.item_no LIKE ? OR oi.item_number LIKE ? OR c.name LIKE ? OR CAST(o.id AS CHAR)=? OR FIND_IN_SET(oi.id,?)>0)';
                $like=clmsSearchLike($q);array_push($params,$like,$like,$like,$like,$like,$q,implode(',',$matching));
            }
            $from='FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN customers c ON c.id=o.customer_id WHERE '.$where;
            $cols='oi.id,oi.order_id,oi.product_id,oi.item_no,oi.item_number,oi.description_cn,oi.description_en,oi.quantity,oi.cartons,oi.unit,oi.shared_carton_contents,oi.supplier_id,o.supplier_id header_supplier_id,o.status,c.name customer_name';
            $order='o.id DESC,oi.id ASC';
        }
        $s=$pdo->prepare('SELECT COUNT(*) '.$from);$s->execute($params);$total=(int)$s->fetchColumn();
        $s=$pdo->prepare("SELECT $cols $from ORDER BY $order LIMIT $limit OFFSET $offset");$s->execute($params);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
        if($kind==='orders')foreach($rows as &$row){
            $contents=json_decode($row['shared_carton_contents']??'[]',true);$matching=[];
            foreach(is_array($contents)?$contents:[] as $content)if(is_array($content)&&(string)($content['supplier_id']??'')===(string)$supplier)$matching[]=$content;
            $row['linked_contents']=array_map(static fn($c)=>array_intersect_key($c,array_flip(['description_cn','description_en','description','item_no','item_number','quantity','unit'])),$matching);
            $row['shared_carton_link']=count($matching)>0;
            unset($row['shared_carton_contents'],$row['supplier_id'],$row['header_supplier_id']);
        }
        return ['data'=>$rows,'meta'=>['kind'=>$kind,'total'=>$total,'limit'=>$limit,'offset'=>$offset,'can_open_orders'=>hasPageAccess('orders')]];
    }
}
