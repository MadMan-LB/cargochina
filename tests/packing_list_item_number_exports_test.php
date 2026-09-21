<?php
require_once dirname(__DIR__) . '/backend/services/OrderExcelService.php';
require_once dirname(__DIR__) . '/backend/api/handlers/draft-orders.php';
require_once dirname(__DIR__) . '/backend/services/ReceivingExcelImportService.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
$checks = 0;
function identifierExportAssert(bool $ok, string $name): void {
    global $checks;
    if (!$ok) throw new RuntimeException($name);
    $checks++;
}
$values = ['HZ','HZ','PL-001','00125','A/22',' Mixed Case ','=1+1'];
$items = [];
foreach ($values as $i=>$value) $items[] = [
    'item_no'=>'IIN-' . ($i+1), 'item_number'=>$value, 'description_en'=>'Packing QA ' . $i,
    'quantity'=>20, 'cartons'=>2, 'qty_per_carton'=>10, 'unit_price'=>5,
    'total_amount'=>100, 'declared_cbm'=>0.2, 'declared_weight'=>16,
];
$order = ['id'=>987654, 'customer_name'=>'Identifier Export QA', 'supplier_name'=>'QA Supplier', 'currency'=>'RMB'];
identifierExportAssert(OrderExcelService::identifierSummary($items,'item_number') === implode("\n",$values), 'Summary exports preserve duplicate manual references');
$items[1]['item_no'] = $items[0]['item_no']; // Historical duplicate must not block or be renumbered by export.
identifierExportAssert(OrderExcelService::identifierSummary($items,'item_no') === implode("\n",array_column($items,'item_no')), 'Summary exports preserve duplicate automatic references');
$file = tempnam(sys_get_temp_dir(), 'clms_identifiers_');
try {
    (new OrderExcelService())->saveSelectedOrdersXlsx([['order'=>$order,'items'=>$items]], $file);
    $book = IOFactory::load($file);
    $sheet = $book->getActiveSheet();
    $found = [];
    $automatic = [];
    for ($r=1;$r<=$sheet->getHighestRow();$r++) {
        if (!str_starts_with((string)$sheet->getCell('I'.$r)->getValue(), 'IIN-')) continue;
        $automatic[] = $sheet->getCell('I'.$r)->getValue();
        $cell = $sheet->getCell('AC'.$r);
        $found[] = $cell->getValue();
        identifierExportAssert($cell->getDataType()===DataType::TYPE_STRING, 'Excel references are explicit text, not formulas/numbers');
    }
    identifierExportAssert($found===$values, 'Single/bulk workbook retains all exact references and duplicates');
    identifierExportAssert($automatic===array_column($items,'item_no'), 'Repeated automatic identifiers remain unchanged in downloads');
    $book->disconnectWorksheets();
    $sharedItems = [['id'=>99,'shared_carton_enabled'=>1,'item_number'=>'PARENT','quantity'=>60,'cartons'=>2,'qty_per_carton'=>30,'unit_price'=>5,'total_amount'=>300,'shared_carton_contents'=>json_encode([
        ['item_no'=>'SHARED-1','item_number'=>'HZ','description_en'=>'First contained product'],
        ['item_no'=>'SHARED-2','item_number'=>'HZ','description_en'=>'Second contained product'],
        ['item_no'=>'SHARED-3','item_number'=>'00125','description_en'=>'Third contained product'],
    ])]];
    (new OrderExcelService())->saveOrderXlsx($order, $sharedItems, $file);
    $book = IOFactory::load($file);
    $refs = $book->getSheetByName('Shared carton identifiers');
    identifierExportAssert($refs!==null && $refs->getHighestRow()===4, 'Shared-carton references included without expanding financial rows');
    identifierExportAssert($refs->getCell('D2')->getValue()==='SHARED-1' && $refs->getCell('E2')->getValue()==='HZ' && $refs->getCell('E3')->getValue()==='HZ' && $refs->getCell('E4')->getValue()==='00125', 'All contained identifiers and duplicates exported');
    identifierExportAssert($refs->getCell('E4')->getDataType()===DataType::TYPE_STRING, 'Contained numeric-looking reference exports as text');
    $book->disconnectWorksheets();
    $headers = draftOrderImportHeaderMap(['I.I.N','Item Number','Description','Cartons','Pieces/Carton']);
    $reason = null;
    $imported = draftOrderImportBuildItem(['IIN-1',' 00125 ','Packing QA','2','10'], $headers, $reason);
    identifierExportAssert(($imported['item_number']??null)===' 00125 ', 'Draft import preserves packing-list whitespace/leading zeros');
    identifierExportAssert(($imported['item_no']??null)==='IIN-1', 'Import separates I.I.N from Item Number');
    $historical = draftOrderImportBuildItem(['IIN-1','','Packing QA','2','10'], $headers, $reason);
    identifierExportAssert(($historical['item_number']??null)==='', 'Blank imports do not invent packing-list references');
    $resolver = new ReflectionMethod(ReceivingExcelImportService::class, 'resolveOrderItem');
    $service = new ReceivingExcelImportService();
    $normalizer = new ReflectionMethod(ReceivingExcelImportService::class, 'normalizeSpreadsheetRow');
    $normalized = $normalizer->invoke($service, 1, [' 00125 '], ['item_number'=>0]);
    identifierExportAssert(($normalized['item_number']??null)===' 00125 ', 'Receiving spreadsheet normalization preserves exact reference text');
    $receivable = [['id'=>1,'item_no'=>'IIN-1','item_number'=>'HZ'],['id'=>2,'item_no'=>'IIN-2','item_number'=>'HZ'],['id'=>3,'item_no'=>'IIN-3','item_number'=>'00125']];
    $errors = [];
    $match = $resolver->invokeArgs($service, [['item_number'=>'HZ'], $receivable, [], &$errors]);
    identifierExportAssert($match===null && count($errors)===1 && str_contains($errors[0], 'multiple items'), 'Duplicate references never select an arbitrary receiving item');
    $errors = [];
    $match = $resolver->invokeArgs($service, [['item_number'=>'HZ','item_no'=>'IIN-2'], $receivable, [], &$errors]);
    identifierExportAssert(($match['id']??null)===2 && !$errors, 'I.I.N disambiguates a duplicate packing reference');
    $errors = [];
    $match = $resolver->invokeArgs($service, [['item_number'=>'00125'], $receivable, [], &$errors]);
    identifierExportAssert(($match['id']??null)===3 && !$errors, 'Exact packing reference resolves a receiving item');
    echo "PASS: $checks identifier export/import assertions\n";
} finally { @unlink($file); }
