<?php
/** Single-use bearer links. Plain tokens never enter the audit trail. */
final class CustomerPortalService
{
    public static function consume(PDO $pdo,string $token): ?array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token))return null;
        $pdo->beginTransaction();
        try{
            $s=$pdo->prepare('SELECT cpt.*,c.name customer_name,c.code customer_code FROM customer_portal_tokens cpt JOIN customers c ON c.id=cpt.customer_id WHERE cpt.token_hash=? AND cpt.expires_at>NOW() AND cpt.used_at IS NULL FOR UPDATE');$s->execute([hash('sha256',$token)]);$row=$s->fetch(PDO::FETCH_ASSOC);
            if(!$row){$pdo->rollBack();return null;}
            $pdo->prepare('UPDATE customer_portal_tokens SET used_at=NOW() WHERE id=?')->execute([$row['id']]);
            $pdo->prepare("INSERT INTO audit_log(entity_type,entity_id,action,new_value) VALUES ('customer_portal_token',?,'consume',?)")->execute([$row['id'],json_encode(['customer_id'=>(int)$row['customer_id']])]);
            $pdo->commit();return $row;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}
