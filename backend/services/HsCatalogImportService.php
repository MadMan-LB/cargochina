<?php
require_once __DIR__.'/MasterDataImportService.php';

final class HsCatalogImportService
{
    public static function run(PDO $pdo,string $csv,string $source,array $input,int $userId): array
    {
        try{$rows=CsvTableService::parse($csv,50001,16777216);}catch(InvalidArgumentException $e){jsonError($e->getMessage(),422);}
        $header=array_map(static fn($v)=>trim(strtolower(preg_replace('/[^a-zA-Z0-9]+/','_',trim($v))),'_'),array_shift($rows)??[]);
        $aliases=['english_name'=>'name_en','chinese_name'=>'name_zh'];$header=array_map(static fn($v)=>$aliases[$v]??$v,$header);
        $limits=['hs_code'=>20,'name'=>500,'name_en'=>500,'name_zh'=>500,'category'=>255,'tariff_rate'=>50,'vat'=>50,'parent_directory_code'=>20,'parent_directory_name'=>255,'section_code'=>20,'section_name'=>255];
        if(!$rows||array_diff(['hs_code','name'],$header)||array_diff($header,array_keys($limits))||count(array_unique($header))!==count($header))jsonError('Catalog requires unique supported headers and at least one data row',422);
        $records=[];$codes=[];
        foreach($rows as $index=>$values){
            if(count($values)!==count($header))jsonError('Catalog row '.($index+2).' does not match headers',422);
            $record=array_combine($header,array_map('trim',$values));
            foreach($record as $field=>$value)if(mb_strlen($value)>$limits[$field])jsonError('Catalog row '.($index+2).': '.$field.' exceeds its maximum length',422);
            $code=$record['hs_code'];$normalized=preg_replace('/[.\- ]/','',$code);
            if(!preg_match('/^[0-9][0-9.\- ]*$/D',$code)||$record['name']===''||isset($codes[$normalized]))jsonError('Catalog contains an invalid or duplicate HS code or empty name',422);
            $codes[$normalized]=true;$records[]=$record;
        }
        $input=['idempotency_key'=>$input['idempotency_key']??null,'source'=>$source,'content_sha256'=>hash('sha256',$csv)];
        $claim=OperationReplayService::claim($pdo,'hs_catalog_import',$input,$userId);
        if($claim['previous_data']!==null)return $claim['previous_data']['result'];
        MasterDataImportService::lock($pdo,'hs_catalog');
        $pdo->beginTransaction();register_shutdown_function(static function()use($pdo){if($pdo->inTransaction())$pdo->rollBack();});
        try{
            $preserved=[];
            foreach($pdo->query('SELECT hs_code,name,name_en,name_zh,translated_at FROM hs_code_tariff_catalog FOR UPDATE') as $row)$preserved[$row['hs_code']."\0".$row['name']]=$row;
            $pdo->exec('DELETE FROM hs_code_tariff_catalog');
            $columns=array_keys($limits);$stmt=$pdo->prepare('INSERT INTO hs_code_tariff_catalog ('.implode(',',$columns).',source_file,translated_at) VALUES ('.implode(',',array_fill(0,count($columns)+2,'?')).')');
            foreach($records as $row){
                $old=$preserved[$row['hs_code']."\0".$row['name']]??[];
                foreach(['name_en','name_zh'] as $field)$row[$field]=($row[$field]??'')?:($old[$field]??null);
                $values=array_map(static fn($field)=>($row[$field]??'')?:null,$columns);$values[]=$source;$values[]=($row['name_en']||$row['name_zh'])?($old['translated_at']??date('Y-m-d H:i:s')):null;$stmt->execute($values);
            }
            $result=['imported'=>count($records),'file'=>$source];
            OperationReplayService::record($pdo,'hs_catalog_import',0,$claim,['result'=>$result],$userId);$pdo->commit();return $result;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}
