<?php
// Targeted, transactional tests. No production data or external image requests.
require_once dirname(__DIR__).'/includes/asset_url.php';
if (($argv[1] ?? '') === '--asset') { echo clmsAssetUrl($argv[2]); exit; }
require_once dirname(__DIR__).'/backend/api/handlers/customers.php';
require_once dirname(__DIR__).'/backend/api/handlers/procurement-drafts.php';
require_once dirname(__DIR__).'/backend/services/ItemClassificationService.php';
require_once dirname(__DIR__).'/backend/services/OrderExcelService.php';
$pdo = getDb();
function perfCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
perfCheck($pdo->query('SELECT DATABASE()')->fetchColumn() === 'clms_hardening_20260919', 'Disposable DB required');
$pdo->beginTransaction();
$files = [];
register_shutdown_function(static function() use ($pdo, &$files) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($files as $file) if (is_file($file)) unlink($file);
});
$ids = $pdo->query('SELECT id FROM customers ORDER BY id LIMIT 50')->fetchAll(PDO::FETCH_COLUMN);
[$shipping, $pors] = loadCustomerPageRelations($pdo, $ids);
foreach ($ids as $id) {
    perfCheck(($shipping[$id] ?? []) === loadCountryShipping($pdo, (int)$id), 'Shipping batch differs from detail');
    perfCheck(($pors[$id] ?? []) === loadCustomerPorValues($pdo, (int)$id), 'POR batch differs from detail');
}
$pdo->exec("INSERT INTO procurement_drafts(name,created_by) VALUES ('Autumn replenishment',1)");
$draft = (int)$pdo->lastInsertId();
foreach ([2,0,1] as $sort) $pdo->prepare('INSERT INTO procurement_draft_items(draft_id,quantity,notes,sort_order) VALUES (?,12,?,?)')->execute([$draft, '竹托盘 / صينية', $sort]);
$header = $pdo->query('SELECT * FROM procurement_drafts WHERE id='.$draft)->fetch(PDO::FETCH_ASSOC);
$items = $pdo->query('SELECT * FROM procurement_draft_items WHERE draft_id='.$draft.' ORDER BY sort_order,id')->fetchAll(PDO::FETCH_ASSOC);
$revision = procurementDraftLoadedRevision($header, $items);
perfCheck($revision === procurementDraftRevision($pdo,$draft), 'Batched revision incompatible with save/delete');
$pdo->exec('UPDATE procurement_draft_items SET quantity=13 WHERE draft_id='.$draft);
perfCheck($revision !== procurementDraftRevision($pdo,$draft), 'Revision retained stale item data');
$service = new ItemClassificationService($pdo);
$itemIds = $pdo->query('SELECT id FROM order_items ORDER BY id LIMIT 220')->fetchAll(PDO::FETCH_COLUMN);
$batch = $service->getMany('order_item', $itemIds);
foreach ($itemIds as $id) perfCheck(($batch[$id] ?? null) === $service->get('order_item',(int)$id), 'Classification batch changed values');
perfCheck($service->getMany('order_item',[]) === [], 'Empty classification batch');

$tag = bin2hex(random_bytes(6));
$asset = dirname(__DIR__).'/frontend/qa-asset-'.$tag.'.js'; $files[] = $asset;
$url = '/cargochina/frontend/'.basename($asset).'?v=old&mode=1#anchor';
$version = static function() use ($url) {
    $p = proc_open([PHP_BINARY,__FILE__,'--asset',$url],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    perfCheck(proc_close($p)===0 && $err==='', 'Asset worker failed'); return $out;
};
file_put_contents($asset,'version one'); touch($asset,1700000000); $v1=$version();
file_put_contents($asset,'version two'); touch($asset,1700000000); $v2=$version();
perfCheck($v1!==$v2 && strpos($v2,'mode=1')!==false && substr($v2,-7)==='#anchor', 'Preserved mtime deployment failed cache busting');
perfCheck(clmsAssetUrl('/cargochina/backend/media.php?path=x')==='/cargochina/backend/media.php?path=x', 'Private URL versioned as public');
perfCheck(clmsAssetUrl('https://example.invalid/frontend/a.js')==='https://example.invalid/frontend/a.js', 'External asset rewritten');

$image = dirname(__DIR__).'/backend/uploads/qa-fresh-'.$tag.'.png'; $files[]=$image;
$excel = new OrderExcelService($pdo); $hashes=[];
foreach ([[200,20,20],[20,20,200]] as $n=>$rgb) {
    $im=imagecreatetruecolor(240,80); imagefill($im,0,0,imagecolorallocate($im,...$rgb)); imagepng($im,$image); imagedestroy($im); touch($image,1700000000);
    $file=sys_get_temp_dir().'/clms-fresh-'.$tag.'-'.$n.'.xlsx'; $files[]=$file;
    $excel->saveOrderXlsx(['id'=>987654,'customer_name'=>'بيروت'],array_fill(0,3,['item_no'=>'AUTO-01','item_number'=>'007','description_en'=>'竹托盘','quantity'=>1,'cartons'=>1,'image_paths'=>['uploads/'.basename($image)]]),$file);
    $book=\PhpOffice\PhpSpreadsheet\IOFactory::load($file);
    $drawings=$book->getActiveSheet()->getDrawingCollection(); perfCheck(count($drawings)===3,'Repeated image missing');
    $anchors=[]; $rowHashes=[];
    foreach ($drawings as $d) { $anchors[]=$d->getCoordinates(); $rowHashes[]=hash_file('sha256',$d->getPath()); }
    perfCheck(count(array_unique($anchors))===3 && count(array_unique($rowHashes))===1,'Repeated image anchors/content incorrect');
    $hashes[]=$rowHashes[0]; $book->disconnectWorksheets();
}
perfCheck($hashes[0]!==$hashes[1],'Reused export service returned stale image after replacement');
echo "PASS: batched canonical relations/revisions/classifications, deployment cache busting, independent image anchors and same-service image freshness\n";
