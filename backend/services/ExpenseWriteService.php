<?php
require_once __DIR__.'/OperationReplayService.php';
require_once __DIR__.'/SupplierPaymentService.php';

final class ExpenseWriteService
{
    private const FIELDS=['category_id','amount','currency','expense_date','payee','notes','order_id','container_id','customer_id','supplier_id'];
    public static function revision(array $row): string { $values=[];foreach(array_merge(['id'],self::FIELDS) as $key)$values[$key]=isset($row[$key])?(string)$row[$key]:null;return hash('sha256',json_encode($values)); }
    public static function present(array $row): array {$row['revision']=self::revision($row);return $row;}
    public static function save(PDO $pdo,string $method,?int $id,array $input,int $userId): array
    {
        $claim=$method==='POST'?OperationReplayService::claim($pdo,'expense',$input,$userId):null;
        if($claim&&$claim['previous_id']){$s=$pdo->prepare('SELECT * FROM expenses WHERE id=?');$s->execute([$claim['previous_id']]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)jsonError('Original expense no longer exists',409);return self::present($r);}
        $pdo->beginTransaction();register_shutdown_function(static function()use($pdo){if($pdo->inTransaction())$pdo->rollBack();});
        try {
            $old=null;if($method!=='POST'){$s=$pdo->prepare('SELECT * FROM expenses WHERE id=? FOR UPDATE');$s->execute([$id]);$old=$s->fetch(PDO::FETCH_ASSOC);if(!$old){if($method==='DELETE'){$pdo->commit();return ['deleted'=>true];}jsonError('Expense not found',404);}if(!is_string($input['revision']??null)||!hash_equals(self::revision($old),$input['revision']))jsonError('Expense changed; reload before saving',409);}
            $warehouse=hasAnyRole(['WarehouseStaff'])&&!hasAnyRole(['ChinaAdmin','LebanonAdmin','SuperAdmin']);
            if($method==='DELETE'){
                if($warehouse)jsonError('Warehouse staff cannot delete expenses',403);
                $pdo->prepare('DELETE FROM expenses WHERE id=?')->execute([$id]);$new=null;
            } else {
                $data=$input+($old??[]);$new=[];
                foreach(['category_id','order_id','container_id','customer_id','supplier_id'] as $field)$new[$field]=OrderWriteService::number($data[$field]??null,$field,true,0,4294967295)?:null;
                if(!$new['category_id']){
                    if($warehouse)jsonError('Warehouse staff must select a warehouse category',403);
                    $name=$data['category_name']??null;if(!is_string($name)||trim($name)===''||mb_strlen($name)>100)jsonError('A valid expense category is required',422);
                    $new['category_id']=findOrCreateExpenseCategory($pdo,trim($name),$userId);
                }
                $s=$pdo->prepare('SELECT category_type FROM expense_categories WHERE id=? AND is_active=1');$s->execute([$new['category_id']]);$category=$s->fetchColumn();if(!$category)jsonError('Invalid expense category',422);if($warehouse&&$category!=='warehouse')jsonError('Warehouse staff can only use warehouse categories',403);
                $amount=$data['amount']??null;if(!is_scalar($amount)||is_bool($amount)||!is_numeric($amount)||!is_finite((float)$amount))jsonError('Amount must be a finite decimal',422);$new['amount']=clmsFinancialDecimal($amount);if(DecimalMath::compare($new['amount'],'99999999.99')>0)jsonError('Expense amount exceeds the supported range',422);
                $new['currency']=$data['currency']??'USD';if(!in_array($new['currency'],['USD','RMB','EUR'],true))jsonError('Unsupported expense currency',422);
                $date=$data['expense_date']??date('Y-m-d');$parsed=is_string($date)?DateTimeImmutable::createFromFormat('!Y-m-d',$date):false;if(!$parsed||$parsed->format('Y-m-d')!==$date)jsonError('Invalid expense date',422);$new['expense_date']=$date;
                foreach(['payee'=>255,'notes'=>65535] as $field=>$max){$v=$data[$field]??null;if($v!==null&&(!is_string($v)||mb_strlen($v)>$max))jsonError('Invalid '.$field,422);$new[$field]=trim($v??'')?:null;}
                if($new['order_id']){$s=$pdo->prepare('SELECT customer_id FROM orders WHERE id=? FOR UPDATE');$s->execute([$new['order_id']]);$buyer=$s->fetchColumn();if(!$buyer)jsonError('Order not found',404);if($new['customer_id']&&(int)$buyer!==$new['customer_id'])jsonError('Expense customer does not match its order',422);}
                if($new['supplier_id'])SupplierPaymentService::lockPartyAndOrder($pdo,$new['supplier_id'],$new['order_id']);
                foreach(['container_id'=>'containers','customer_id'=>'customers'] as $field=>$table)if($new[$field]){$s=$pdo->prepare("SELECT id FROM $table WHERE id=?");$s->execute([$new[$field]]);if(!$s->fetchColumn())jsonError('Linked '.$field.' not found',404);}
                if($new['order_id']&&$new['container_id']){$s=$pdo->prepare('SELECT 1 FROM shipment_draft_orders sdo JOIN shipment_drafts sd ON sd.deleted_at IS NULL AND sd.id=sdo.shipment_draft_id WHERE sdo.order_id=? AND sd.container_id=?');$s->execute([$new['order_id'],$new['container_id']]);if(!$s->fetchColumn())jsonError('Expense order does not belong to the selected container',422);}
                if($method==='POST'){$new['created_by']=$userId;$pdo->prepare('INSERT INTO expenses('.implode(',',array_keys($new)).') VALUES ('.implode(',',array_fill(0,count($new),'?')).')')->execute(array_values($new));$id=(int)$pdo->lastInsertId();}
                else {$pdo->prepare('UPDATE expenses SET '.implode(',',array_map(fn($k)=>$k.'=?',array_keys($new))).' WHERE id=?')->execute([...array_values($new),$id]);}
                $s=$pdo->prepare('SELECT * FROM expenses WHERE id=?');$s->execute([$id]);$new=$s->fetch(PDO::FETCH_ASSOC);
            }
            if($claim)OperationReplayService::record($pdo,'expense',$id,$claim,$new,$userId);
            else $pdo->prepare('INSERT INTO audit_log(entity_type,entity_id,action,old_value,new_value,user_id) VALUES (?,?,?,?,?,?)')->execute(['expense',$id,$method==='DELETE'?'delete':'update',json_encode($old),$new?json_encode($new):null,$userId]);
            $pdo->commit();return $new?self::present($new):['deleted'=>true];
        } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}
