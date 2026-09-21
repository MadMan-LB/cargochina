<?php
require_once __DIR__.'/OrderWriteService.php';
require_once __DIR__.'/DecimalMath.php';

/** Local deposit ledger only. Callers own the transaction, authorization and replay key. */
final class CustomerDepositService
{
    public static function normalize(array $input): array
    {
        $amount=$input['amount']??null;
        if(!is_scalar($amount)||is_bool($amount)||!is_numeric($amount)||!is_finite((float)$amount))jsonError('Amount must be a finite decimal',422);
        $amount=clmsFinancialDecimal($amount,'Amount');
        if(DecimalMath::compare($amount,'99999999.9999')>0)jsonError('Amount exceeds the supported ledger range',422);
        $currency=$input['currency']??'RMB';if(!in_array($currency,['USD','RMB'],true))jsonError('Currency must be USD or RMB',422);
        $result=['amount'=>$amount,'currency'=>$currency];
        foreach(['payment_method'=>50,'reference_no'=>100,'notes'=>65535] as $field=>$limit){
            $value=$input[$field]??null;if($value!==null&&!is_string($value))jsonError("$field must be text",422);
            $value=trim($value??'');if(mb_strlen($value)>$limit)jsonError("$field exceeds its supported length",422);$result[$field]=$value?:null;
        }
        // Method is optional for historical/manual deposits in both entry points.
        $result['order_id']=OrderWriteService::number($input['order_id']??null,'Order',true,0,4294967295)?:null;
        return $result;
    }

    public static function lockPartyAndOrder(PDO $pdo,int $customerId,?int $orderId): void
    {
        if(!$pdo->inTransaction())throw new LogicException('Deposit requires an active transaction');
        // Order writers lock the order before its customer; keep the same order here.
        if($orderId){$s=$pdo->prepare('SELECT customer_id FROM orders WHERE id=? FOR UPDATE');$s->execute([$orderId]);$owner=$s->fetchColumn();if($owner===false)jsonError('Order not found',404);if((int)$owner!==$customerId)jsonError('Selected order belongs to another customer',422);}
        $s=$pdo->prepare('SELECT id FROM customers WHERE id=? FOR UPDATE');$s->execute([$customerId]);if(!$s->fetchColumn())jsonError('Customer not found',404);
    }

    public static function insert(PDO $pdo,int $customerId,array $input,int $userId): int
    {
        $data=self::normalize($input);self::lockPartyAndOrder($pdo,$customerId,$data['order_id']);
        $pdo->prepare('INSERT INTO customer_deposits(customer_id,order_id,amount,currency,payment_method,reference_no,notes,created_by) VALUES (?,?,?,?,?,?,?,?)')->execute([$customerId,$data['order_id'],$data['amount'],$data['currency'],$data['payment_method'],$data['reference_no'],$data['notes'],$userId]);
        return (int)$pdo->lastInsertId();
    }
}
