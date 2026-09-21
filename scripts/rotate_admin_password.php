<?php

// Offline-only credential rotation. The scripts directory is denied by .htaccess.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/backend/config/database.php';

$email = trim((string) (getenv('CLMS_ADMIN_EMAIL') ?: 'admin@salameh.com'));
$password = (string) (getenv('CLMS_NEW_ADMIN_PASSWORD') ?: '');
if (strlen($password) < 14
    || !preg_match('/[a-z]/', $password)
    || !preg_match('/[A-Z]/', $password)
    || !preg_match('/\d/', $password)
    || !preg_match('/[^A-Za-z0-9]/', $password)) {
    fwrite(STDERR, "CLMS_NEW_ADMIN_PASSWORD must be at least 14 characters and include upper, lower, number, and symbol.\n");
    exit(2);
}

$pdo=getDb();
require_once dirname(__DIR__).'/backend/services/CredentialEscrowService.php';
AuditService::begin($pdo);
try {
 $s=$pdo->prepare('SELECT id FROM users WHERE email=? AND is_active=1 FOR UPDATE');$s->execute([$email]);$id=(int)$s->fetchColumn();
 if(!$id)throw new RuntimeException('Active account not found');
 $hash=password_hash($password,PASSWORD_DEFAULT);
 CredentialEscrowService::store($pdo,$id,$password,$hash);
 $pdo->prepare('UPDATE users SET password_hash=?,session_version=session_version+1 WHERE id=?')->execute([$hash,$id]);
 AuditService::record($pdo,'user',$id,'offline_credential_rotation',null,['sessions_revoked'=>true,'operator'=>'protected CLI'],null);$pdo->commit();
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,"Credential rotation failed safely; verify protected configuration.\n");exit(3);}
unset($password,$hash);
fwrite(STDOUT,"Administrator password rotated for $email.\n");
