<?php
/** Streaming libsodium authenticated archive; recovery private key stays off the application server. */
final class EncryptedBackupService
{
    private const MAGIC="CLMSBK01";
    private const CHUNK=1048576;
    private static function write($stream,string $data):void{while($data!==''){$n=fwrite($stream,$data);if(!$n)throw new RuntimeException('Backup write failed');$data=substr($data,$n);}}
    private static function read($stream,int $size):string{$data='';while(strlen($data)<$size&&!feof($stream)){$chunk=fread($stream,$size-strlen($data));if($chunk===false)throw new RuntimeException('Backup read failed');$data.=$chunk;}return $data;}
    public static function encrypt($input,$output,string $recipient):void
    {
        if(!extension_loaded('sodium')||strlen($recipient)!==SODIUM_CRYPTO_BOX_PUBLICKEYBYTES)throw new RuntimeException('Backup encryption configuration unavailable');
        $key=sodium_crypto_secretstream_xchacha20poly1305_keygen();
        [$state,$header]=sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $sealed=sodium_crypto_box_seal($key,$recipient);sodium_memzero($key);
        self::write($output,self::MAGIC.$sealed.$header);
        while(!feof($input)){$chunk=self::read($input,self::CHUNK);if($chunk==='')break;$encrypted=sodium_crypto_secretstream_xchacha20poly1305_push($state,$chunk,self::MAGIC);self::write($output,pack('N',strlen($encrypted)).$encrypted);}
        $final=sodium_crypto_secretstream_xchacha20poly1305_push($state,'',self::MAGIC,SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);self::write($output,pack('N',strlen($final)).$final);sodium_memzero($state);
    }
    public static function decrypt($input,$output,string $keypair):void
    {
        if(!extension_loaded('sodium')||strlen($keypair)!==SODIUM_CRYPTO_BOX_KEYPAIRBYTES)throw new RuntimeException('Backup recovery configuration unavailable');
        if(self::read($input,8)!==self::MAGIC)throw new RuntimeException('Invalid backup format');
        $sealed=self::read($input,SODIUM_CRYPTO_BOX_SEALBYTES+SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
        $key=sodium_crypto_box_seal_open($sealed,$keypair);if($key===false)throw new RuntimeException('Backup recipient mismatch');
        $header=self::read($input,SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);$state=sodium_crypto_secretstream_xchacha20poly1305_init_pull($header,$key);sodium_memzero($key);
        $final=false;
        while(!$final){$length=self::read($input,4);if(strlen($length)!==4)throw new RuntimeException('Truncated backup');$n=unpack('N',$length)[1];if($n<17||$n>self::CHUNK+17)throw new RuntimeException('Invalid backup frame');$cipher=self::read($input,$n);if(strlen($cipher)!==$n)throw new RuntimeException('Truncated backup');$result=sodium_crypto_secretstream_xchacha20poly1305_pull($state,$cipher,self::MAGIC);if($result===false)throw new RuntimeException('Backup authentication failed');[$plain,$tag]=$result;if($output!==null)self::write($output,$plain);$final=$tag===SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;}
        if(self::read($input,1)!=='')throw new RuntimeException('Unexpected trailing backup data');sodium_memzero($state);
    }
}
