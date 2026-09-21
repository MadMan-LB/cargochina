<?php
/** Strict UTF-8 comma-separated records; quoted newlines and doubled quotes are supported. */
final class CsvTableService
{
    public static function parse(string $csv,int $maxRows=1001,int $maxBytes=2097152,string $delimiter=',',bool $preserveBlank=false): array
    {
        if(strlen($csv)>$maxBytes||!mb_check_encoding($csv,'UTF-8'))throw new InvalidArgumentException('CSV must be UTF-8 and within the supported size');
        $csv=preg_replace('/^\xEF\xBB\xBF/','',$csv);$rows=[];$row=[];$field='';$state='start';$length=strlen($csv);
        if(!in_array($delimiter,[',',';',"\t"],true))throw new InvalidArgumentException('Unsupported CSV delimiter');
        $endRow=static function()use(&$rows,&$row,&$field,&$state,$maxRows,$preserveBlank){$row[]=$field;if($preserveBlank||$row!==[''])$rows[]=$row;if(count($rows)>$maxRows)throw new InvalidArgumentException('CSV exceeds the supported row count');$row=[];$field='';$state='start';};
        for($i=0;$i<$length;$i++){
            $c=$csv[$i];
            if($state==='quoted'){if($c==='"'){if(($csv[$i+1]??'')==='"'){$field.='"';$i++;}else $state='closed';}else $field.=$c;continue;}
            if($c===$delimiter){$row[]=$field;$field='';$state='start';continue;}
            if($c==="\r"||$c==="\n"){if($c==="\r"&&($csv[$i+1]??'')==="\n")$i++;$endRow();continue;}
            if($state==='closed')throw new InvalidArgumentException('CSV has unexpected text after a closing quote');
            if($c==='"'){if($state!=='start')throw new InvalidArgumentException('CSV contains an unescaped quote');$state='quoted';continue;}
            $field.=$c;$state='unquoted';
        }
        if($state==='quoted')throw new InvalidArgumentException('CSV has an unterminated quoted field');
        if($row||$field!==''||$state==='closed')$endRow();
        return $rows;
    }

    public static function delimiter(string $sample): string
    {
        $counts=[','=>0,';'=>0,"\t"=>0];$quoted=false;
        for($i=0,$length=strlen($sample);$i<$length;$i++){if($sample[$i]==='"'){if($quoted&&($sample[$i+1]??'')==='"'){$i++;continue;}$quoted=!$quoted;}elseif(!$quoted&&isset($counts[$sample[$i]]))$counts[$sample[$i]]++;}
        arsort($counts);return (string)array_key_first($counts);
    }
}
