<?php
require_once __DIR__.'/OrderWriteService.php';

final class ProductWriteService
{
    public static function normalize(PDO $pdo,array $input): array
    {
        foreach(['description_cn'=>500,'description_en'=>500,'hs_code'=>50,'packaging'=>100,'high_alert_note'=>65535,'item_type_code'=>50] as $field=>$max){$value=$input[$field]??null;if($value!==null&&!is_string($value))jsonError("$field must be text",422);if($value!==null&&mb_strlen(trim($value))>$max)jsonError("$field exceeds its supported length",422);if(array_key_exists($field,$input))$input[$field]=trim($value??'')?:null;}
        if(isset($input['description_entries'])){
            if(!is_array($input['description_entries'])||count($input['description_entries'])>100)jsonError('Invalid product description entries',422);
            $cn=[];$en=[];foreach($input['description_entries'] as $entry){if(!is_array($entry))jsonError('Invalid product description entry',422);$text=$entry['description_text']??$entry['text']??'';$translated=$entry['description_translated']??$entry['translated']??'';if(!is_string($text)||!is_string($translated))jsonError('Descriptions must be text',422);if(trim($text)!==''){$cn[]=trim($text);$en[]=trim($translated)?:trim($text);}}
            if($cn){$input['description_cn']=implode(' | ',$cn);$input['description_en']=implode(' | ',$en);if(mb_strlen($input['description_cn'])>500||mb_strlen($input['description_en'])>500)jsonError('Combined product description exceeds 500 characters',422);}
        }
        if(empty($input['description_cn'])&&empty($input['description_en']))jsonError('Product description is required',422);
        foreach(['cbm','weight','unit_price','buy_price','sell_price','length_cm','width_cm','height_cm','pieces_per_carton','supplier_id','item_type_confidence'] as $field){if(array_key_exists($field,$input))$input[$field]=OrderWriteService::number($input[$field],$field,in_array($field,['pieces_per_carton','supplier_id'],true),$field==='cbm'?6:4,match($field){'cbm'=>999999.999999,'length_cm','width_cm','height_cm'=>999999.9999,'pieces_per_carton','supplier_id'=>4294967295,'item_type_confidence'=>1,default=>99999999.9999});}
        $dims=array_map(static fn($f)=>(float)($input[$f]??0),['length_cm','width_cm','height_cm']);
        if(max($dims)>0){if(min($dims)<=0)jsonError('Provide all three positive dimensions',422);$input['cbm']=OrderWriteService::number(array_product($dims)/1000000,'Calculated CBM',false,6,999999.999999);}
        if(($input['cbm']??0)<=0)jsonError('Provide positive CBM or all three dimensions',422);
        if(isset($input['pieces_per_carton'])&&$input['pieces_per_carton']<=0)jsonError('Pieces per carton must be positive',422);
        if(!empty($input['supplier_id'])){$s=$pdo->prepare('SELECT id FROM suppliers WHERE id=?');$s->execute([$input['supplier_id']]);if(!$s->fetchColumn())jsonError('Product supplier not found',422);}
        if(isset($input['dimensions_scope'])&&!in_array($input['dimensions_scope'],['piece','carton'],true))jsonError('Invalid dimensions scope',422);
        foreach(['force_create','item_type_confirmed','required_design'] as $field)if(isset($input[$field])&&!in_array($input[$field],[true,false,0,1,'0','1'],true))jsonError("$field must be a boolean",422);
        if(isset($input['image_paths'])){if(!is_array($input['image_paths'])||count($input['image_paths'])>30)jsonError('Invalid product images',422);$input['image_paths']=normalizeStoredUploadPathList($input['image_paths']);}
        return $input;
    }

    public static function assertDuplicates(PDO $pdo,array $input): void
    {
        if(!empty($input['force_create']))return;
        $search=trim($input['description_cn']??$input['description_en']??$input['hs_code']??'');if(strlen($search)<2)return;
        $like=clmsSearchLike($search);$s=$pdo->prepare('SELECT id,description_cn,description_en,hs_code FROM products WHERE description_cn LIKE ? OR description_en LIKE ? OR hs_code LIKE ? LIMIT 10');$s->execute([$like,$like,$like]);$duplicates=$s->fetchAll(PDO::FETCH_ASSOC);
        foreach($duplicates as $existing){$compare=$existing['description_cn']?:$existing['description_en']?:$existing['hs_code']?:'';similar_text($search,$compare,$pct);if($pct>=70)jsonError('Possible duplicate product. Reuse the existing product or explicitly confirm force_create.',409,['suggested_ids'=>array_column($duplicates,'id')]);}
    }
}
