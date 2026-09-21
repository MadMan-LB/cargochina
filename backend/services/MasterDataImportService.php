<?php
require_once __DIR__.'/CsvTableService.php';
require_once __DIR__.'/OperationReplayService.php';

final class MasterDataImportService
{
    public static function lock(PDO $pdo,string $type): void
    {
        $key='master-write:'.substr(hash('sha256',$pdo->query('SELECT DATABASE()')->fetchColumn().':'.$type),0,48);
        $s=$pdo->prepare('SELECT GET_LOCK(?,5)');$s->execute([$key]);if(!(int)$s->fetchColumn())jsonError('Master data is being updated; retry shortly',409);
        register_shutdown_function(static function()use($pdo,$key){$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$key]);});
    }

    public static function run(PDO $pdo,string $type,array $input,array $required,array $allowed,callable $create): array
    {
        $csv=$input['csv']??$input['data']??null;if(!is_string($csv)||trim($csv)==='')jsonError('CSV text is required',422);
        try{$rows=CsvTableService::parse($csv);}catch(InvalidArgumentException $e){jsonError($e->getMessage(),422);}
        $header=array_map(static fn($v)=>strtolower(trim($v)),array_shift($rows)??[]);
        if(!$rows||array_diff($required,$header)||count(array_unique($header))!==count($header)||array_diff($header,$allowed))jsonError('CSV requires supported unique headers and at least one data row',422);
        $user=(int)getAuthUserId();$claim=OperationReplayService::claim($pdo,$type.'_import',$input,$user);
        if($claim['previous_data']!==null)return $claim['previous_data']['result'];
        self::lock($pdo,$type);$pdo->beginTransaction();register_shutdown_function(static function()use($pdo){if($pdo->inTransaction())$pdo->rollBack();});
        try{
            $result=['created'=>0,'skipped'=>0,'errors'=>[]];$ids=[];
            foreach($rows as $index=>$values){
                if(count($values)!==count($header))jsonError('CSV row '.($index+2).' does not match its headers',422);
                $row=array_combine($header,array_map('trim',$values));$id=$create($row,$index+2);
                if($id===null){$result['skipped']++;continue;}
                $result['created']++;$ids[]=$id;
                $pdo->prepare("INSERT INTO audit_log(entity_type,entity_id,action,new_value,user_id) VALUES (?,?,'import_create',?,?)")->execute([$type,$id,json_encode(['row'=>$index+2],JSON_THROW_ON_ERROR),$user]);
            }
            OperationReplayService::record($pdo,$type.'_import',0,$claim,['result'=>$result,'ids'=>$ids],$user);$pdo->commit();return $result;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}
