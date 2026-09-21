<?php
/** Existing invariant tests model clients that fetched the latest header. */
function containerTestHandler(PDO $pdo, callable $handler): callable
{
    return static function(string $method,?string $id,?string $action,array $input)use($pdo,$handler){
        if($method==='PUT'&&$id&&!isset($input['revision'])){
            $s=$pdo->prepare('SELECT * FROM containers WHERE id=?');$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);
            if($row)$input['revision']=ContainerWriteService::revision($row);
        }
        return $handler($method,$id,$action,$input);
    };
}
