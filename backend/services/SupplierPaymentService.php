<?php
require_once __DIR__.'/CustomerDepositService.php';

/** Shared local supplier ledger rules. Callers own authorization, transaction and replay. */
final class SupplierPaymentService
{
    public static function lockPartyAndOrder(PDO $pdo,int $supplierId,?int $orderId): void
    {
        if (!$pdo->inTransaction()) throw new LogicException('Supplier payment requires an active transaction');
        if ($orderId) {
            $s=$pdo->prepare('SELECT id FROM orders WHERE id=? FOR UPDATE');$s->execute([$orderId]);if(!$s->fetchColumn())jsonError('Order not found',404);
            $sharedParams=[];
            $shared=clmsSharedCartonSupplierPredicate('oi.shared_carton_contents',$pdo,$supplierId,$sharedParams);
            $s=$pdo->prepare("SELECT 1 FROM orders o WHERE o.id=? AND (o.supplier_id=? OR EXISTS(SELECT 1 FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id WHERE oi.order_id=o.id AND (COALESCE(oi.supplier_id,p.supplier_id)=? OR $shared)))");
            $s->execute(array_merge([$orderId,$supplierId,$supplierId],$sharedParams));
            if(!$s->fetchColumn())jsonError('Selected order does not belong to this supplier',422);
        }
        $s=$pdo->prepare('SELECT id FROM suppliers WHERE id=? FOR UPDATE');$s->execute([$supplierId]);if(!$s->fetchColumn())jsonError('Supplier not found',404);
    }

    public static function insert(PDO $pdo,int $supplierId,array $input,int $userId): int
    {
        $base=CustomerDepositService::normalize($input);
        $row=['supplier_id'=>$supplierId,'order_id'=>$base['order_id'],'amount'=>$base['amount'],'currency'=>$base['currency'],'notes'=>$base['notes']];
        foreach(['payment_channel'=>50,'payment_account_label'=>150,'payment_account_value'=>255,'payment_account_qr_path'=>255,'settlement_note'=>65535] as $field=>$max){
            $value=$input[$field]??null;if($value!==null&&!is_string($value))jsonError("$field must be text",422);
            $value=trim($value??'');if(mb_strlen($value)>$max)jsonError("$field exceeds its supported length",422);$row[$field]=$value?:null;
        }
        if($row['payment_channel']!==null&&!in_array($row['payment_channel'],['WeChat','Alipay','Bank Transfer'],true))jsonError('Unsupported payment channel',422);
        if($row['payment_account_qr_path']!==null){try{$row['payment_account_qr_path']=normalizeStoredUploadPath($row['payment_account_qr_path']);}catch(InvalidArgumentException $e){jsonError('Invalid payment account QR path',422);}}
        $full=$input['marked_full_payment']??false;if(!in_array($full,[true,false,0,1,'0','1'],true))jsonError('Invalid full payment flag',422);
        $row['marked_full_payment']=(int)(bool)$full;$row['marked_by']=$full?$userId:null;
        $type=$input['payment_type']??($full?'full':'partial');if(!in_array($type,['full','partial'],true)||($type==='full')!==(bool)$full)jsonError('Payment type must match full settlement flag',422);$row['payment_type']=$type;
        $invoice=$input['invoice_amount']??null;
        if($invoice!==null && $invoice!=='') {
            if(!is_scalar($invoice)||is_bool($invoice)||!is_numeric($invoice)||!is_finite((float)$invoice))jsonError('Invoice amount must be a finite decimal',422);
            $invoice=DecimalMath::round($invoice);if(DecimalMath::compare($invoice,'0')<0||DecimalMath::compare($invoice,'99999999.9999')>0)jsonError('Invoice amount is outside the supported range',422);
        } else $invoice=null;
        $row['invoice_amount']=$invoice;
        $difference=$invoice!==null&&DecimalMath::compare($invoice,$base['amount'])>0?DecimalMath::subtract($invoice,$base['amount']):'0.0000';
        $row['discount_amount']=$difference;$row['settlement_delta']=$full?$difference:'0.0000';
        $mode=$input['settlement_mode']??null;if(!in_array($mode,[null,'','fully_settled_by_agreement'],true))jsonError('Invalid settlement mode',422);
        if($mode&&!$full)jsonError('Settlement mode requires full settlement',422);
        $row['settlement_mode']=$full&&DecimalMath::compare($difference,'0')>0?'fully_settled_by_agreement':null;
        self::lockPartyAndOrder($pdo,$supplierId,$base['order_id']);
        $pdo->prepare('INSERT INTO supplier_payments('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));
        return (int)$pdo->lastInsertId();
    }
}
