<?php
require_once __DIR__.'/OrderWriteService.php';

/** Customer identity and shipping metadata rules shared by forms and imports. */
final class CustomerWriteService
{
    public static function lockNamespace(PDO $pdo): void
    {
        $name='customer-write:'.substr(hash('sha256',$pdo->query('SELECT DATABASE()')->fetchColumn()),0,40);
        $s=$pdo->prepare('SELECT GET_LOCK(?,5)');$s->execute([$name]);if(!(int)$s->fetchColumn())jsonError('Customer records are being updated; retry shortly',409);
        register_shutdown_function(static function()use($pdo,$name){$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);});
    }

    public static function normalize(PDO $pdo,array $input): array
    {
        $limits=['code'=>50,'name'=>255,'default_shipping_code'=>50,'phone'=>50,'email'=>255,'address'=>65535,'payment_terms'=>255,'priority_note'=>65535];
        foreach($limits as $field=>$limit){
            if(!array_key_exists($field,$input))continue;
            if($input[$field]!==null&&!is_string($input[$field]))jsonError("$field must be text",422);
            $value=trim($input[$field]??'');if(mb_strlen($value)>$limit)jsonError("$field exceeds its supported length",422);
            $input[$field]=$value!==''?$value:null;
        }
        if(empty($input['name']))jsonError('Customer name is required',422);
        if(!empty($input['email'])&&!filter_var($input['email'],FILTER_VALIDATE_EMAIL))jsonError('Customer email is invalid',422);
        $level=$input['priority_level']??'normal';if(!in_array($level,['normal','medium','high','critical'],true))jsonError('Invalid customer priority',422);
        if($level!=='normal'&&empty($input['priority_note']))jsonError('Priority note is required when priority is not normal',422);
        foreach(['contacts','addresses','payment_links','country_shipping','por'] as $field){
            if(isset($input[$field])&&!is_array($input[$field]))jsonError("$field must be an array",422);
            if(is_array($input[$field]??null)&&count($input[$field])>100)jsonError("Too many $field values",422);
            if(isset($input[$field])&&strlen(json_encode($input[$field],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))>65535)jsonError("$field exceeds its supported size",422);
        }
        foreach($input['payment_links']??[] as $link){
            if(!is_array($link)||!is_string($link['name']??null)||!is_string($link['value']??''))jsonError('Payment details require text labels and values',422);
            if(mb_strlen($link['name'])>255||mb_strlen($link['value']??'')>2000)jsonError('Payment detail is too long',422);
        }
        $seen=[];
        foreach($input['country_shipping']??[] as &$country){
            if(!is_array($country))jsonError('Country shipping entry must be an object',422);
            $id=OrderWriteService::number($country['country_id']??null,'Country',true,0,4294967295);if(!$id||isset($seen[$id]))jsonError('Country shipping requires unique valid countries',422);
            $s=$pdo->prepare('SELECT id FROM countries WHERE id=?');$s->execute([$id]);if(!$s->fetchColumn())jsonError('Shipping country not found',422);$seen[$id]=true;
            $code=$country['shipping_code']??null;if($code!==null&&!is_string($code))jsonError('Shipping code must be text',422);if(mb_strlen(trim($code??''))>50)jsonError('Shipping code exceeds its supported length',422);
        }
        unset($country);
        foreach($input['por']??[] as $value)if(!is_string($value)||mb_strlen(trim($value))>120)jsonError('POR values must be text of at most 120 characters',422);
        return $input;
    }

    public static function revision(array $row,array $countries=[],array $pors=[]): string
    {
        $values=[];foreach(['id','code','name','default_shipping_code','phone','email','address','payment_terms','priority_level','priority_note','created_by'] as $field)$values[$field]=(string)($row[$field]??'');
        foreach(['contacts','addresses','payment_links'] as $field)$values[$field]=is_array($row[$field]??null)?$row[$field]:(json_decode($row[$field]??'[]',true)?:[]);
        $values['country_shipping']=array_map(static fn($r)=>[(string)$r['country_id'],(string)($r['shipping_code']??'')],$countries);sort($values['country_shipping']);$values['por']=$pors;
        return OrderWriteService::requestHash($values);
    }
}
