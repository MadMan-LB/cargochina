<?php
require_once __DIR__.'/OwnerAccessService.php';
require_once __DIR__.'/AuditService.php';
require_once __DIR__.'/SessionPolicyService.php';
require_once __DIR__.'/OwnerSecondFactorService.php';
/** No password appears in exceptions, audit data or ordinary user serializers. */
final class CredentialEscrowService
{
    public static function safeDatabaseGrants(array $grants,string $database): bool
    {
        foreach($grants as $grant){
            if(str_contains(strtoupper($grant),'WITH GRANT OPTION'))return false;
            if(preg_match('/^GRANT USAGE ON \*\.\* TO /i',$grant))continue;
            if(!preg_match('/^GRANT [A-Z_, ]+ ON `'.preg_quote($database,'/').'`\.\* TO /i',$grant))return false;
        }return count($grants)>0;
    }
    private static function requireKeyBoundary(PDO $pdo,array $cfg):void
    {
        if(getenv('APP_ENV')==='testing')return;
        if(empty($cfg['key_isolation_verified'])||!self::safeDatabaseGrants($pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN),(string)$pdo->query('SELECT DATABASE()')->fetchColumn()))throw new RuntimeException('Escrow requires a restricted database identity and verified key isolation');
    }
    public static function ready(PDO $pdo,array $cfg,int $actor):bool
    {
        try{
            if(empty($cfg['owner_verified'])||empty($cfg['escrow_enabled'])||empty($cfg['immutable_audit_verified']))return false;
            $keys=self::keys($cfg);if(strlen($keys[$cfg['active_key_id']??'']??'')!==32)return false;
            $factor=base64_decode($cfg['owner_totp_keys'][(string)$actor]??'',true);if($factor===false||strlen($factor)<20)return false;
            self::requireKeyBoundary($pdo,$cfg);return true;
        }catch(Throwable $e){return false;}
    }
    public static function seal(string $password,int $id,string $fingerprint,string $key,string $keyId): array
    {
        if(strlen($key)!==32||$id<1||!preg_match('/^[a-f0-9]{64}$/',$fingerprint))throw new RuntimeException('Invalid escrow configuration');
        $nonce=random_bytes(12);$tag='';$aad='clms-credential-v1|'.$id.'|'.$fingerprint.'|'.$keyId;
        $cipher=openssl_encrypt($password,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,$aad,16);
        if($cipher===false)throw new RuntimeException('Credential encryption failed');
        return ['v'=>1,'key_id'=>$keyId,'nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),'ciphertext'=>base64_encode($cipher)];
    }
    public static function open(array $envelope,int $id,string $fingerprint,array $keys): string
    {
        $key=$keys[$envelope['key_id']??'']??'';$nonce=base64_decode($envelope['nonce']??'',true);$tag=base64_decode($envelope['tag']??'',true);$cipher=base64_decode($envelope['ciphertext']??'',true);
        if(($envelope['v']??null)!==1||strlen($key)!==32||$nonce===false||strlen($nonce)!==12||$tag===false||strlen($tag)!==16||$cipher===false)throw new RuntimeException('Credential recovery unavailable');
        $plain=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,'clms-credential-v1|'.$id.'|'.$fingerprint.'|'.$envelope['key_id']);
        if($plain===false)throw new RuntimeException('Credential recovery unavailable');return $plain;
    }
    private static function keys(array $cfg): array
    {
        $keys=[];foreach(($cfg['escrow_keys']??[]) as $id=>$encoded){$key=base64_decode($encoded,true);if($key===false||strlen($key)!==32)throw new RuntimeException('Invalid escrow configuration');$keys[$id]=$key;}return $keys;
    }
    public static function store(PDO $pdo,int $id,string $password,string $hash): void
    {
        $cfg=OwnerAccessService::config();
        $s=$pdo->prepare('SELECT 1 FROM credential_escrow WHERE user_id=?');$s->execute([$id]);$exists=(bool)$s->fetchColumn();
        $s=$pdo->prepare('SELECT credential_recovery_required FROM users WHERE id=?');$s->execute([$id]);$required=(bool)$s->fetchColumn();
        $eligible=in_array($id,$cfg['escrow_user_ids']??[],true)&&!in_array($id,$cfg['owner_ids']??[],true);
        if(!$eligible&&!$exists&&!$required)return;
        if(!$pdo->inTransaction())throw new LogicException('Escrow must share credential transaction');
        if(!$eligible||empty($cfg['escrow_enabled']))throw new RuntimeException('Credential escrow must be configured before changing this credential');
        if($required){$ready=false;foreach($cfg['owner_ids']??[] as $ownerId)if(OwnerAccessService::allowed($pdo,(int)$ownerId)&&self::ready($pdo,$cfg,(int)$ownerId)){$ready=true;break;}if(!$ready)throw new RuntimeException('Verified owner recovery must be available before changing this credential');}
        self::requireKeyBoundary($pdo,$cfg);
        if(!password_verify($password,$hash))throw new RuntimeException('Credential verification failed');
        $keys=self::keys($cfg);$keyId=$cfg['active_key_id']??'';$fingerprint=hash('sha256',$hash);
        $envelope=self::seal($password,$id,$fingerprint,$keys[$keyId]??'',$keyId);
        $pdo->prepare('INSERT INTO credential_escrow(user_id,envelope,credential_fingerprint) VALUES (?,?,?) ON DUPLICATE KEY UPDATE envelope=VALUES(envelope),credential_fingerprint=VALUES(credential_fingerprint)')->execute([$id,json_encode($envelope,JSON_THROW_ON_ERROR),$fingerprint]);
    }
    public static function reveal(PDO $pdo,int $actor,int $target,string $reauthPassword,array $session,string $reason,string $verificationCode,?callable $testAudit=null): string
    {
        OwnerAccessService::requireOwner($pdo,$actor);$cfg=OwnerAccessService::config();
        if(!SessionPolicyService::recent($session)||($session['user_id']??0)!==$actor)throw new DomainException('Sign in again before credential recovery',403);
        if(!in_array($target,$cfg['escrow_user_ids']??[],true)||in_array($target,$cfg['owner_ids']??[],true))throw new DomainException('Account is not eligible for credential recovery',403);
        if(strlen(trim($reason))<5||strlen($reason)>200)throw new DomainException('A short recovery reason is required',422);
        if(empty($cfg['escrow_enabled'])||empty($cfg['immutable_audit_verified']))throw new DomainException('Credential recovery is disabled until protected keys and immutable audit delivery are verified',503);
        self::requireKeyBoundary($pdo,$cfg);
        if($testAudit!==null&&!(PHP_SAPI==='cli'&&getenv('APP_ENV')==='testing'))throw new LogicException('Test audit unavailable');
        $s=$pdo->prepare('SELECT email,password_hash,session_version FROM users WHERE id=? AND is_active=1');$s->execute([$actor]);$owner=$s->fetch();
        if(!$owner||!SessionPolicyService::validate($session,$owner,['SuperAdmin']))throw new DomainException('Re-authentication failed',403);
        require_once __DIR__.'/AuthenticationService.php';
        try{(new AuthenticationService($pdo))->login($owner['email'],$reauthPassword,$_SERVER['REMOTE_ADDR']??'cli',getenv('APP_ENV')?:'production');}catch(AuthenticationException $e){throw new DomainException('Re-authentication failed or temporarily throttled',$e->httpStatus);}
        OwnerSecondFactorService::verify($cfg,$actor,$verificationCode);
        $s=$pdo->prepare('SELECT e.envelope,e.credential_fingerprint,u.password_hash FROM credential_escrow e JOIN users u ON u.id=e.user_id WHERE e.user_id=? AND u.is_active=1');$s->execute([$target]);$row=$s->fetch();
        if(!$row||!hash_equals(hash('sha256',$row['password_hash']),$row['credential_fingerprint']))throw new DomainException('No current recoverable credential; enrollment requires a verified sign-in or approved rotation',409);
        // An audited attempt precedes disclosure. A failed sink prevents any decryption/output.
        $event=['event_id'=>bin2hex(random_bytes(16)),'event'=>'credential_reveal','phase'=>'disclosure_authorized','actor'=>$actor,'target'=>$target,'timestamp'=>gmdate('c'),'session_fingerprint'=>hash('sha256',session_id()),'ip'=>substr($_SERVER['REMOTE_ADDR']??'cli',0,64),'reason_code'=>'employee_recovery'];
        AuditService::begin($pdo);AuditService::record($pdo,'user',$target,'credential_reveal_attempt',null,$event,$actor);$pdo->commit();
        if($testAudit!==null)$testAudit($event);else self::immutableAudit($cfg,$event);
        // External acknowledgement can take seconds. Recheck revocation and target
        // credential changes before decrypting; no business lock is held over I/O.
        OwnerAccessService::requireOwner($pdo,$actor);$freshCfg=OwnerAccessService::config();
        if(!self::ready($pdo,$freshCfg,$actor)||!in_array($target,$freshCfg['escrow_user_ids']??[],true))throw new DomainException('Credential recovery authorization changed',409);
        $s=$pdo->prepare('SELECT password_hash,session_version FROM users WHERE id=? AND is_active=1');$s->execute([$actor]);$currentOwner=$s->fetch();
        if(!$currentOwner||!SessionPolicyService::validate($session,$currentOwner,['SuperAdmin']))throw new DomainException('Owner session was revoked',403);
        $s=$pdo->prepare('SELECT password_hash FROM users WHERE id=? AND is_active=1');$s->execute([$target]);$currentHash=$s->fetchColumn();
        if(!$currentHash||!hash_equals($row['credential_fingerprint'],hash('sha256',$currentHash)))throw new DomainException('Target credential changed; reopen recovery',409);
        $plain=self::open(json_decode($row['envelope'],true,32,JSON_THROW_ON_ERROR),$target,$row['credential_fingerprint'],self::keys($cfg));
        if(!password_verify($plain,$row['password_hash']))throw new RuntimeException('Credential recovery unavailable');
        AuditService::begin($pdo);AuditService::record($pdo,'user',$target,'credential_reveal',null,$event,$actor);$pdo->commit();return $plain;
    }
    private static function immutableAudit(array $cfg,array $event): void
    {
        // Provider contract: append-only service returns an RSA/SHA256 signed event digest.
        $url=$cfg['audit_url']??'';$public=$cfg['audit_receipt_public_key']??'';
        $localTest=getenv('APP_ENV')==='testing'&&parse_url($url,PHP_URL_SCHEME)==='http'&&in_array(parse_url($url,PHP_URL_HOST),['localhost','127.0.0.1'],true);
        if((parse_url($url,PHP_URL_SCHEME)!=='https'&&!$localTest)||!$public)throw new RuntimeException('Immutable audit delivery unavailable');
        $body=json_encode($event,JSON_THROW_ON_ERROR);$c=curl_init($url);
        curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.($cfg['audit_bearer']??'')],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>$localTest?CURLPROTO_HTTP:CURLPROTO_HTTPS]);
        $response=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);
        $receipt=is_string($response)?json_decode($response,true):null;
        $signature=base64_decode($receipt['signature']??'',true);
        $expected=$event['event_id'].'|'.hash('sha256',$body);
        if($status!==201||$signature===false||openssl_verify($expected,$signature,$public,OPENSSL_ALGO_SHA256)!==1)throw new RuntimeException('Immutable audit acknowledgement unavailable');
    }
}
