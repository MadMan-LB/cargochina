<?php
/** Targeted disposable-schema regression for migrations 083-086; no source writes. */
require_once dirname(__DIR__).'/backend/config/database.php';
$p=getDb();$db='clms_migration_compat_'.bin2hex(random_bytes(5));
function compatAssert(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function compatMigrate(PDO $p):void {
 foreach(['083_upload_asset_ownership.sql','084_release_policy_controls.sql','085_owner_controls.sql','086_credential_recovery_requirement.sql'] as $file){
  $sql=file_get_contents(dirname(__DIR__).'/backend/migrations/'.$file);
  // COMPACT reproduces the production 767-byte index ceiling on local MariaDB.
  $sql=str_replace('ENGINE=InnoDB DEFAULT','ENGINE=InnoDB ROW_FORMAT=COMPACT DEFAULT',$sql);
  $sql=preg_replace('/^\s*--.*$/m','',$sql);
  foreach(explode(';',$sql) as $s)if(trim($s)!==''){$q=$p->query($s);$q->closeCursor();}
 }
}
$p->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4");
try {
 $p->exec("USE `$db`");$p->exec('CREATE TABLE users(id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');$p->exec('INSERT INTO users VALUES(1)');
 compatMigrate($p);compatMigrate($p);
 $path='uploads/'.str_repeat(json_decode('"\ud83d\udce6"'),240).'.png';
 $insert=$p->prepare('INSERT INTO upload_assets(path,uploader_user_id,byte_size,content_sha256) VALUES(?,1,12,?)');
 $insert->execute([$path,str_repeat('a',64)]);
 $row=$p->query('SELECT path,HEX(path_sha256) digest FROM upload_assets')->fetch();
 compatAssert($row['path']===$path&&strtolower($row['digest'])===hash('sha256',$path),'Unicode path/digest changed');
 $duplicate=false;try{$insert->execute([$path,str_repeat('a',64)]);}catch(PDOException $e){if($e->getCode()!=='23000')throw $e;$duplicate=true;}
 compatAssert($duplicate,'Duplicate path accepted');
 $other=substr($path,0,-4).'a.png';$insert->execute([$other,str_repeat('b',64)]);
 compatAssert((int)$p->query('SELECT COUNT(*) FROM upload_assets')->fetchColumn()===2,'Paths with common index prefix collided');
 $p->prepare('UPDATE upload_assets SET path=? WHERE path=?')->execute(['uploads/renamed.png',$other]);
 compatAssert(strtolower($p->query("SELECT HEX(path_sha256) FROM upload_assets WHERE path='uploads/renamed.png'")->fetchColumn())===hash('sha256','uploads/renamed.png'),'Rename digest not refreshed');
 $p->exec("INSERT INTO owner_incidents(fingerprint,severity,category,workflow,action_name,error_code,safe_message) VALUES(REPEAT('c',64),'LOW','validation','orders','save','INPUT','Safe fixture')");
 $event=$p->query('SELECT first_seen,last_seen FROM owner_incidents')->fetch();compatAssert($event['first_seen']!==null&&$event['last_seen']!==null,'Incident timestamps missing');
 $hold=$p->prepare('INSERT INTO retention_holds(scope_type,scope_reference,reason,approval_reference) VALUES(?,?,?,?)');
 foreach(['a','b'] as $suffix)$hold->execute(['uploads',str_repeat('r',240).$suffix,'fixture','fixture']);
 $holdLookup=$p->prepare('SELECT COUNT(*) FROM retention_holds WHERE scope_type=? AND scope_reference=?');
 $holdLookup->execute(['uploads',str_repeat('r',240).'b']);compatAssert((int)$holdLookup->fetchColumn()===1,'Full retention reference lookup changed');
 $p->exec("UPDATE users SET session_version=7,last_login_at='2026-09-19 12:00:00',credential_recovery_required=1");
 compatMigrate($p);$user=$p->query('SELECT * FROM users')->fetch();compatAssert((int)$user['session_version']===7&&(int)$user['credential_recovery_required']===1&&$user['last_login_at']==='2026-09-19 12:00:00','User values changed on rerun');
 compatAssert($p->query('SELECT first_seen,last_seen FROM owner_incidents')->fetch()===$event,'Existing incident timestamps changed');
 $p->exec('ALTER TABLE users DROP COLUMN last_login_at');compatMigrate($p);compatAssert($p->query('SELECT last_login_at FROM users')->fetchColumn()===null,'Partial migration failed');
 $badOwner=false;try{$p->exec("INSERT INTO upload_assets(path,uploader_user_id,byte_size,content_sha256) VALUES('uploads/no-owner',999,1,REPEAT('d',64))");}catch(PDOException $e){if($e->getCode()!=='23000')throw $e;$badOwner=true;}compatAssert($badOwner,'Owner FK bypassed');
 // Existing pre-fix schema, with one row: exercise upgrade and interrupted ADD COLUMN.
 $p->exec('DROP TABLE upload_assets');
 $p->exec('CREATE TABLE upload_assets(path VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin PRIMARY KEY,uploader_user_id INT UNSIGNED NOT NULL,byte_size BIGINT UNSIGNED NOT NULL,content_sha256 CHAR(64) NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(uploader_user_id) REFERENCES users(id)) ENGINE=InnoDB ROW_FORMAT=DYNAMIC');
 $p->prepare('INSERT INTO upload_assets(path,uploader_user_id,byte_size,content_sha256) VALUES(?,1,12,?)')->execute([$path,str_repeat('a',64)]);
 $p->exec('ALTER TABLE upload_assets ADD COLUMN path_sha256 BINARY(32) NULL');
 compatMigrate($p);compatMigrate($p);
 $row=$p->query('SELECT path,HEX(path_sha256) digest FROM upload_assets')->fetch();compatAssert($row['path']===$path&&strtolower($row['digest'])===hash('sha256',$path),'Upgrade lost existing data');
 echo 'PASS: 083-086 compact-row index limits, fresh/rerun/partial/legacy upgrade, full Unicode paths, duplicate protection, rename hashing, ownership FK, incident timestamps and user preservation. Engine '.$p->getAttribute(PDO::ATTR_SERVER_VERSION).PHP_EOL;
} finally {$p->exec("DROP DATABASE `$db`");echo "Disposable schema removed; source unchanged.\n";}
