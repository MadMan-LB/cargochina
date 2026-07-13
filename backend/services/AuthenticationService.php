<?php

final class AuthenticationException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus)
    {
        parent::__construct($message);
    }
}

final class AuthenticationService
{
    public function __construct(private PDO $pdo) {}

    public function login(string $identifier, string $password, string $ipAddress, string $appEnv): array
    {
        $identifier=trim($identifier);
        if ($identifier==='' || $password==='') throw new AuthenticationException('Email and password required',400);
        $key=hash('sha256',mb_strtolower($identifier,'UTF-8').'|'.trim($ipAddress ?: 'unknown'));
        if ($this->isThrottled($key)) throw new AuthenticationException('Too many login attempts. Try again later.',429);

        $stmt=$this->pdo->prepare('SELECT id,password_hash,full_name FROM users WHERE LOWER(TRIM(email))=LOWER(TRIM(?)) AND is_active=1 LIMIT 1');
        $stmt->execute([$identifier]);
        $user=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !password_verify($password,(string)$user['password_hash'])) {
            $this->recordFailure($key);
            throw new AuthenticationException('Invalid email or password',401);
        }
        if (strtolower(trim($appEnv))==='production' && password_verify('password',(string)$user['password_hash'])) {
            $this->recordFailure($key);
            throw new AuthenticationException('This seeded password is disabled in production. An administrator must rotate it offline.',403);
        }
        $this->clearFailures($key);
        if (password_needs_rehash((string)$user['password_hash'],PASSWORD_DEFAULT)) {
            $this->pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),(int)$user['id']]);
        }
        $roles=$this->pdo->prepare('SELECT r.code FROM roles r JOIN user_roles ur ON r.id=ur.role_id WHERE ur.user_id=?');
        $roles->execute([(int)$user['id']]);
        return ['user_id'=>(int)$user['id'],'name'=>(string)$user['full_name'],'roles'=>array_column($roles->fetchAll(PDO::FETCH_ASSOC),'code')];
    }

    private function hasAttemptTable(): bool
    {
        try { return (bool)$this->pdo->query("SHOW TABLES LIKE 'auth_login_attempts'")->rowCount(); }
        catch(Throwable $e) { return false; }
    }
    private function isThrottled(string $key): bool
    {
        if(!$this->hasAttemptTable()) return false;
        $stmt=$this->pdo->prepare('SELECT blocked_until FROM auth_login_attempts WHERE identity_hash=?'); $stmt->execute([$key]);
        $blocked=$stmt->fetchColumn(); return $blocked && strtotime((string)$blocked)>time();
    }
    private function recordFailure(string $key): void
    {
        if(!$this->hasAttemptTable()) return;
        $started=!$this->pdo->inTransaction(); if($started)$this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT IGNORE INTO auth_login_attempts (identity_hash,attempt_count,first_attempt_at,last_attempt_at) VALUES (?,0,NOW(),NOW())')->execute([$key]);
            $stmt=$this->pdo->prepare('SELECT attempt_count,first_attempt_at FROM auth_login_attempts WHERE identity_hash=? FOR UPDATE'); $stmt->execute([$key]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
            $expired=!$row || strtotime((string)$row['first_attempt_at']) < time()-900;
            $count=$expired ? 1 : ((int)$row['attempt_count']+1);
            $first=$expired ? date('Y-m-d H:i:s') : (string)$row['first_attempt_at'];
            $blocked=$count>=5 ? date('Y-m-d H:i:s',time()+900) : null;
            $this->pdo->prepare('UPDATE auth_login_attempts SET attempt_count=?,first_attempt_at=?,last_attempt_at=NOW(),blocked_until=? WHERE identity_hash=?')->execute([$count,$first,$blocked,$key]);
            if($started)$this->pdo->commit();
        } catch(Throwable $e) { if($started&&$this->pdo->inTransaction())$this->pdo->rollBack(); throw $e; }
    }
    private function clearFailures(string $key): void
    {
        if($this->hasAttemptTable())$this->pdo->prepare('DELETE FROM auth_login_attempts WHERE identity_hash=?')->execute([$key]);
    }
}
