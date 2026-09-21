<?php
/** Reject oversized or externally linked workbook packages before invoking a reader. */
final class WorkbookImportGuard
{
    public static function inspect(string $path,string $extension,int $maxBytes): void
    {
        if(!is_file($path)||filesize($path)>$maxBytes)throw new InvalidArgumentException('Import file exceeds the supported size');
        if($extension!=='xlsx')return;
        $zip=new ZipArchive();if($zip->open($path)!==true)throw new InvalidArgumentException('Invalid XLSX workbook');
        try{
            if($zip->numFiles>10000)throw new InvalidArgumentException('Workbook contains too many package entries');$total=0;
            for($i=0;$i<$zip->numFiles;$i++){$entry=$zip->statIndex($i);$size=(int)$entry['size'];$total+=$size;
                if($size>33554432||$total>134217728||($size>1048576&&$size/max(1,(int)$entry['comp_size'])>1024))throw new InvalidArgumentException('Workbook expands beyond supported limits');
                $name=str_replace('\\','/',$entry['name']);if(str_contains($name,'../')||str_starts_with($name,'/'))throw new InvalidArgumentException('Invalid workbook package path');
                if(str_ends_with($name,'.rels')){$xml=$zip->getFromIndex($i);if(preg_match('/TargetMode\s*=\s*["\']External["\']/i',$xml))throw new InvalidArgumentException('Externally linked workbooks are not supported; remove external links before importing');}
            }
        }finally{$zip->close();}
    }
}
