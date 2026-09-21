<?php
final class SupplierWriteService
{
    public static function normalize(array $input): array
    {
        foreach(['code'=>50,'name'=>255,'store_id'=>100,'phone'=>50,'fax'=>50,'factory_location'=>255,'address'=>65535,'notes'=>65535] as $field=>$max){if(!array_key_exists($field,$input))continue;$value=$input[$field];if($value!==null&&!is_string($value))jsonError("$field must be text",422);$value=trim($value??'');if(mb_strlen($value)>$max)jsonError("$field exceeds its supported length",422);$input[$field]=$value?:null;}
        if(empty($input['name']))jsonError('Supplier name is required',422);
        if(!empty($input['phone'])&&!preg_match('/^[\d\s\+\-\(\)\.]{6,50}$/',$input['phone']))jsonError('Invalid supplier phone format',422);
        return $input;
    }
}
