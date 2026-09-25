<?php
// Explicit disposable benchmark; never run against the application database.
require_once dirname(__DIR__).'/backend/config/database.php';
$p=getDb();if($p->query('SELECT DATABASE()')->fetchColumn()!=='clms_hardening_20260919')throw new RuntimeException('Disposable DB required');
$mode=$argv[1]??'';
if($mode==='setup'){
 foreach(['087_recycle_bin.sql','088_calendar_range_indexes.sql','089_supplier_recovery.sql'] as $file){$sql=preg_replace('/^\s*--.*$/m','',file_get_contents(dirname(__DIR__).'/backend/migrations/'.$file));foreach(array_filter(array_map('trim',explode(';',$sql))) as $q){$s=$p->query($q);if($s){do{$s->fetchAll();}while($s->nextRowset());$s->closeCursor();}}}
 $supplier=(int)$p->query('SELECT id FROM suppliers ORDER BY id LIMIT 1')->fetchColumn();$customer=(int)$p->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn();
 for($i=0;$i<60;$i++){
  $p->prepare("INSERT INTO procurement_drafts(name,supplier_id,created_by) VALUES (?,?,1)")->execute(['Autumn procurement '.$i,$supplier]);$id=(int)$p->lastInsertId();
  for($j=0;$j<3;$j++)$p->prepare('INSERT INTO procurement_draft_items(draft_id,quantity,notes,sort_order) VALUES (?,12,?,?)')->execute([$id,'Bamboo tray '.$j,2-$j]);
  $p->prepare("INSERT INTO orders(customer_id,supplier_id,status,order_type,created_by,expected_ready_date) VALUES (?,?,'Draft','draft_procurement',1,'2026-09-30')")->execute([$customer,$supplier]);$oid=(int)$p->lastInsertId();
  for($j=0;$j<3;$j++)$p->prepare("INSERT INTO order_items(order_id,supplier_id,item_no,item_number,description_en,quantity,cartons,unit,declared_cbm,declared_weight) VALUES (?,?,?,?,'Bamboo trays',12,1,'pieces',.12,6)")->execute([$oid,$supplier,"PERF-$i-$j",'007']);
 }
 echo "60 orders / 180 items; 60 procurement drafts / 180 items\n";exit;
}
if($mode==='api'){
 session_start();$_SESSION=['user_id'=>1,'user_roles'=>['SuperAdmin']];$resource=$argv[2];$_GET=json_decode($argv[3]??'{}',true);
 array_walk_recursive($_GET,static function(&$value){if(is_scalar($value))$value=(string)$value;});
 $before=(int)$p->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];$start=microtime(true);
 register_shutdown_function(static function()use($p,$start,$before){$after=(int)$p->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];fwrite(STDERR,json_encode(['ms'=>round((microtime(true)-$start)*1000,3),'queries'=>$after-$before-1,'peak_mb'=>round(memory_get_peak_usage(true)/1048576,2)]));});
 $h=require dirname(__DIR__).'/backend/api/handlers/'.$resource.'.php';$h('GET',$argv[4]??null,null,[]);exit;
}
if($mode==='xlsx'){
 require_once dirname(__DIR__).'/backend/services/OrderExcelService.php';
 $dir=dirname(__DIR__).'/backend/uploads/qa-performance-export';if(!is_dir($dir))mkdir($dir,0770,true);
 for($i=0;$i<6;$i++){if(is_file("$dir/$i.jpg"))continue;$im=imagecreatetruecolor(1800,1200);for($y=0;$y<1200;$y+=4){$c=imagecolorallocate($im,($y+$i*40)%256,($y*3+80)%256,($y*7+20)%256);imagefilledrectangle($im,0,$y,1800,$y+3,$c);}imagejpeg($im,"$dir/$i.jpg",95);imagedestroy($im);}
 $items=[];$count=(int)($argv[3]??180);for($i=0;$i<$count;$i++)$items[]=['item_no'=>'PERF-'.($i+1),'item_number'=>'007','description_en'=>'Bamboo tray '.$i,'description_cn'=>'竹托盘','supplier_name'=>'Cedar Homewares','quantity'=>12,'cartons'=>1,'qty_per_carton'=>12,'unit'=>'pieces','unit_price'=>3,'total_amount'=>36,'declared_cbm'=>.12,'declared_weight'=>6,'image_paths'=>['uploads/qa-performance-export/'.($i%6).'.jpg']];
 $order=['id'=>900001,'customer_name'=>'عميل بيروت / Beirut','currency'=>'USD','status'=>'Confirmed','expected_ready_date'=>'2026-09-30'];
 $path=sys_get_temp_dir().'/clms-perf-'.preg_replace('/[^a-z0-9-]/i','',$argv[2]??'probe').'.xlsx';$start=microtime(true);(new OrderExcelService($p))->saveOrderXlsx($order,$items,$path);$elapsed=microtime(true)-$start;$peak=memory_get_peak_usage(true);
 $book=\PhpOffice\PhpSpreadsheet\IOFactory::load($path);$sheet=$book->getActiveSheet();$drawings=$sheet->getDrawingCollection();if(count($drawings)!==$count)throw new RuntimeException('Missing drawing anchors');$anchors=[];foreach($drawings as $d){if(isset($anchors[$d->getCoordinates()]))throw new RuntimeException('Duplicate image row');$anchors[$d->getCoordinates()]=true;}
 $values=[];foreach($sheet->toArray(null,false,false,false) as $row)foreach($row as $v)if(is_string($v))$values[]=$v;
 if(count(array_filter($values,static fn($v)=>$v==='007'))!==$count||!in_array('PERF-'.$count,$values,true)||!in_array('竹托盘',$values,true))throw new RuntimeException('Identifiers/Unicode incomplete');
 $z=new ZipArchive();if($z->open($path,ZipArchive::CHECKCONS)!==true)throw new RuntimeException('Invalid ZIP');$media=0;for($i=0;$i<$z->numFiles;$i++)if(str_starts_with($z->getNameIndex($i),'xl/media/'))$media++;$z->close();$book->disconnectWorksheets();
 echo json_encode(['seconds'=>round($elapsed,3),'bytes'=>filesize($path),'peak_mb'=>round($peak/1048576,2),'rows'=>$count,'drawings'=>count($anchors),'media'=>$media,'path'=>$path]);exit;
}
