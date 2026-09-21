<?php

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/api/helpers.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Drawing as SharedDrawing;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class OrderExcelService
{
    private string $backendDir;
    private ?PDO $pdo;
    private bool $pdoResolutionAttempted = false;
    private array $workbookImageCache = [];
    private array $workbookImageResolutionCache = [];
    private array $workbookContextImageCache = [];
    private array $workbookImageDiagnosticKeys = [];

    public const IMAGE_PIPELINE_VERSION = '2026.08.06.1';

    private const STANDARD_LAST_COL = 'AC';
    private const CONTAINER_LAST_COL = 'W';
    private const PHOTO_COLUMN = 'H';
    private const CONTAINER_PHOTO_COLUMN = 'E';
    private const PHOTO_COLUMN_WIDTH = 21;
    private const PHOTO_ROW_HEIGHT_PT = 90;
    private const PHOTO_IMAGE_SCALE = 0.82;
    private const HEADER_BLUE = '1F4E79';
    private const BORDER_COLOR = 'BFBFBF';
    private const LIGHT_BLUE = 'EAF3FF';
    private const LIGHT_YELLOW = 'FFF2CC';
    private const LIGHT_RED = 'F4CCCC';
    private const SOFT_SECTION = 'EEF5FF';
    private const SOFT_GREEN = 'E2F0D9';
    private const MASTER_TABLE_BLUE = '2563EB';

    public function __construct(?PDO $pdo = null)
    {
        $this->backendDir = dirname(__DIR__);
        $this->pdo = $pdo;
        $this->pdoResolutionAttempted = $pdo instanceof PDO;
    }

    public static function sharedCartonIdentifierRows(array $entries): array
    {
        $rows = [];
        foreach ($entries as $entry) foreach ($entry['items'] ?? [] as $item) {
            if (empty($item['shared_carton_enabled'])) continue;
            $contents = $item['shared_carton_contents'] ?? [];
            if (is_string($contents)) $contents = json_decode($contents, true) ?: [];
            if (!is_array($contents)) continue;
            foreach ($contents as $content) {
                if (!is_array($content)) continue;
                $rows[] = [
                    'order_id' => $entry['order']['id'] ?? null,
                    'parent_item_id' => $item['id'] ?? $item['source_item_id'] ?? null,
                    'carton_code' => $item['shared_carton_code'] ?? '',
                    'item_no' => $content['item_no'] ?? '',
                    'item_number' => $content['item_number'] ?? '',
                    'description' => $content['description_en'] ?? $content['description_cn'] ?? '',
                ];
            }
        }
        return $rows;
    }

    private function appendSharedCartonIdentifiersSheet(Spreadsheet $spreadsheet, array $entries): void
    {
        $rows = self::sharedCartonIdentifierRows($entries);
        if (!$rows) return;
        // Identification only: do not expand/recalculate existing financial/cargo rows.
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Shared carton identifiers');
        $headers = ['Order ID','Order Item ID','Carton Code','I.I.N','Item Number','Description'];
        foreach ($headers as $i => $header) $this->setSafeCell($sheet,Coordinate::stringFromColumnIndex($i+1).'1', $this->tr($header));
        foreach ($rows as $i => $row) foreach (array_values($row) as $j => $value) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($j+1).($i+2), (string) ($value ?? ''), DataType::TYPE_STRING);
        }
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);
        foreach (range('A','F') as $column) $sheet->getColumnDimension($column)->setWidth($column === 'F' ? 45 : 22);
        $sheet->getStyle('A1:F'.(count($rows)+1))->getAlignment()->setWrapText(true);
        $sheet->freezePane('A2');
        $spreadsheet->setActiveSheetIndex(0);
    }

    private function tr(string $text, array $params = []): string
    {
        return function_exists('clmsT') ? clmsT($text, $params) : $text;
    }

    private function trForLocale(string $text, string $locale): string
    {
        return function_exists('clmsT') ? clmsT($text, [], $locale) : $text;
    }

    private function statusText(string $status): string
    {
        return function_exists('clmsStatusLabel') ? clmsStatusLabel($status) : $status;
    }

    public function exportOrder(array $order, array $items, ?string $filename = null): void
    {
        $spreadsheet = $this->buildOrderSpreadsheet($order, $items);
        $outName = $filename ?? ('order_' . (int) ($order['id'] ?? 0) . '_goods_details.xlsx');
        $this->outputXlsx($spreadsheet, $outName);
    }

    public function saveOrderXlsx(array $order, array $items, string $path): void
    {
        $spreadsheet = $this->buildOrderSpreadsheet($order, $items);
        $this->prepareWorkbook($spreadsheet);
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    public function exportSelectedOrders(array $entries, string $filename = 'selected_orders.xlsx'): void
    {
        $this->outputXlsx($this->buildSelectedOrdersSpreadsheet($entries), $filename);
    }

    public function saveSelectedOrdersXlsx(array $entries, string $path): void
    {
        $spreadsheet = $this->buildSelectedOrdersSpreadsheet($entries);
        $this->prepareWorkbook($spreadsheet);
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    private function buildSelectedOrdersSpreadsheet(array $entries): Spreadsheet
    {
        if (!$entries) throw new InvalidArgumentException('No downloadable records were selected.');
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr($this->tr('Selected Orders'), 0, 31));
        $this->setStandardColumnWidths($sheet);
        $row = 1;
        foreach (array_values($entries) as $index => $entry) {
            $order = is_array($entry['order'] ?? null) ? $entry['order'] : [];
            $items = is_array($entry['items'] ?? null) ? $entry['items'] : [];
            if ($index > 0) {
                $row += 2;
                $sheet->setBreak('A' . $row, Worksheet::BREAK_ROW);
            }
            $row = $this->writeCompanyHeader(
                $sheet,
                $row,
                self::STANDARD_LAST_COL,
                $this->tr('Order #{id} Goods Details', ['id' => (int) ($order['id'] ?? 0)]),
                [
                    [$this->tr('Customer') . ':', self::formatCustomerDisplay($order, $items)],
                    [$this->tr('Destination Country') . ':', (string) ($order['destination_country_name'] ?? $order['destination_country_code'] ?? '')],
                    [$this->tr('Expected Ready') . ':', (string) ($order['expected_ready_date'] ?? '')],
                    [$this->tr('Currency') . ':', (string) ($order['currency'] ?? '')],
                ],
                $this->tr('Order Number') . ': ' . (int) ($order['id'] ?? 0) . '    ' . $this->tr('Status') . ': ' . $this->statusText((string) ($order['status'] ?? ''))
            );
            $row = $this->writeStandardColumnHeaders($sheet, $row);
            $row = $this->writeStandardItems($sheet, $items, $row, $order);
            $row = $this->writeStandardReceiptFees($sheet, $row, $order, $items);
            $row = $this->writeStandardOperationalCosts($sheet, $row, $order);
        }
        $sheet->freezePane('A10');
        $this->appendSharedCartonIdentifiersSheet($spreadsheet, $entries);
        return $spreadsheet;
    }

    private function buildOrderSpreadsheet(array $order, array $items): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr($this->tr('Order') . ' ' . (int) ($order['id'] ?? 0), 0, 31));
        $this->setStandardColumnWidths($sheet);

        $row = $this->writeCompanyHeader(
            $sheet,
            1,
            self::STANDARD_LAST_COL,
            $this->tr('Order #{id} Goods Details', ['id' => (int) ($order['id'] ?? 0)]),
            [
                [$this->tr('Customer') . ':', self::formatCustomerDisplay($order, $items)],
                [$this->tr('Destination Country') . ':', (string) ($order['destination_country_name'] ?? $order['destination_country_code'] ?? '')],
                [$this->tr('Expected Ready') . ':', (string) ($order['expected_ready_date'] ?? '')],
                [$this->tr('Currency') . ':', (string) ($order['currency'] ?? '')],
            ],
            $this->tr('Order Number') . ': ' . (int) ($order['id'] ?? 0) . '    ' . $this->tr('Status') . ': ' . $this->statusText((string) ($order['status'] ?? ''))
        );
        $row = $this->writeStandardColumnHeaders($sheet, $row);
        $row = $this->writeStandardItems($sheet, $items, $row, $order);
        $row = $this->writeStandardReceiptFees($sheet, $row, $order, $items);
        $this->writeStandardOperationalCosts($sheet, $row, $order);

        $sheet->freezePane('A10');
        $this->appendSharedCartonIdentifiersSheet($spreadsheet, [['order'=>$order,'items'=>$items]]);
        return $spreadsheet;
    }

    public function exportOrders(array $ordersWithItems, string $filename = 'container_orders.xlsx', array $context = []): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $this->setContainerColumnWidths($sheet);

        $row = $this->writeCompanyHeader(
            $sheet,
            1,
            self::CONTAINER_LAST_COL,
            $this->tr('Container Orders'),
            [
                [$this->tr('Container') . ':', (string) ($context['container_code'] ?? $context['code'] ?? $context['container']['code'] ?? '')],
                [$this->tr('Orders') . ':', (string) count($ordersWithItems)],
                [$this->tr('Generated') . ':', date('Y-m-d H:i:s')],
                [$this->tr('Currency') . ':', (string) ($context['currency'] ?? '')],
                [$this->tr('Cargo CBM') . ':', (string) ($context['cargo_totals']['cbm'] ?? '')],
                [$this->tr('Cargo Weight (kg)') . ':', (string) ($context['cargo_totals']['weight'] ?? '')],
            ],
            $this->tr('Salameh Global / CargoChina')
        );
        $row = $this->writeContainerColumnHeaders($sheet, $row);

        $sections = $this->buildCustomerSections($ordersWithItems);
        $expenses = is_array($context['expenses'] ?? null) ? $context['expenses'] : [];
        $usedExpenseIds = [];
        $overallTotals = [
            'sell' => [],
            'factory' => [],
            'expenses' => [],
            'cartons' => 0.0,
            'quantity' => 0.0,
            'cbm' => 0.0,
            'weight' => 0.0,
        ];

        foreach ($sections as $section) {
            $row = $this->writeContainerSectionHeader($sheet, $section, $row);

            $sectionTotals = [
                'sell' => [],
                'factory' => [],
                'expenses' => [],
                'cartons' => 0.0,
                'quantity' => 0.0,
                'cbm' => 0.0,
                'weight' => 0.0,
            ];

            foreach ($section['orders'] as $entry) {
                $row = $this->writeContainerItems(
                    $sheet,
                    is_array($entry['items'] ?? null) ? $entry['items'] : [],
                    is_array($entry['order'] ?? null) ? $entry['order'] : [],
                    $row,
                    $sectionTotals,
                    $overallTotals
                );
            }

            $sectionExpenses = $this->collectSectionExpenses($expenses, $section, $usedExpenseIds);
            if ($sectionExpenses) {
                $row = $this->writeSectionExpenses($sheet, $sectionExpenses, $row, $sectionTotals, $overallTotals);
            }

            $row = $this->writeSectionTotals($sheet, $section, $sectionTotals, $row);
            $row++;
        }

        $remainingExpenses = array_values(array_filter($expenses, function (array $expense) use ($usedExpenseIds): bool {
            $id = (int) ($expense['id'] ?? 0);
            return $id <= 0 || !isset($usedExpenseIds[$id]);
        }));

        if ($remainingExpenses) {
            $row = $this->writeOverallExpenseBlock($sheet, $remainingExpenses, $row, $overallTotals);
            $row++;
        }

        $this->writeOverallTotals($sheet, $overallTotals, $row);
        $sheet->freezePane('A10');

        $this->appendSharedCartonIdentifiersSheet($spreadsheet, $ordersWithItems);

        $this->outputXlsx($spreadsheet, $filename);
    }

    /** One line per saved item/reference; never deduplicate or renumber on download. */
    public static function identifierSummary(array $items, string $field): string
    {
        if (!in_array($field, ['item_no', 'item_number'], true)) throw new InvalidArgumentException('Invalid identifier.');
        $values = [];
        foreach ($items as $item) {
            $values[] = (string) ($item[$field] ?? '');
            $contents = $item['shared_carton_contents'] ?? [];
            if (is_string($contents)) $contents = json_decode($contents, true);
            foreach (is_array($contents) ? $contents : [] as $content) {
                if (is_array($content)) $values[] = (string) ($content[$field] ?? '');
            }
        }
        return implode("\n", $values);
    }

    public function exportOrdersListSummary(array $rows, string $filename = 'orders_list.xlsx'): void
    {
        $headers = [
            'Order ID',
            'Photo',
            'Order Type',
            'Customer',
            'Supplier',
            'Expected Ready',
            'Status',
            'Deposit Status',
            'Paid Amount',
            'Remaining Balance',
            'Shipment Charges',
            'Total CBM',
            'Total Weight (kg)',
            'Currency',
            'I.I.N',
            'Item Number',
        ];

        $bodyRows = array_map(function (array $row): array {
            $items = is_array($row['items'] ?? null) ? $row['items'] : [];
            $cbm = 0.0;
            $weight = 0.0;
            $supplierNames = [];
            foreach ($items as $item) {
                $cbm += (float) ($item['declared_cbm'] ?? 0);
                $weight += (float) ($item['declared_weight'] ?? 0);
                $supplierName = trim((string) ($item['supplier_name'] ?? ''));
                if ($supplierName !== '') {
                    $supplierNames[$supplierName] = true;
                }
            }

            $supplierDisplay = trim((string) ($row['supplier_name'] ?? ''));
            $cbm = isset($row['cargo_totals']) ? $row['cargo_totals']['cbm'] : $cbm;
            $weight = isset($row['cargo_totals']) ? $row['cargo_totals']['weight'] : $weight;
            if ($supplierNames) {
                $names = array_keys($supplierNames);
                $supplierDisplay = count($names) === 1 ? $names[0] : $this->tr('Multiple ({names})', ['names' => implode(', ', $names)]);
            }

            return [
                (int) ($row['id'] ?? 0),
                $this->collectItemImagePaths($items),
                (string) ($row['order_type'] ?? 'standard'),
                self::formatCustomerDisplay($row, $items),
                $supplierDisplay,
                (string) ($row['expected_ready_date'] ?? ''),
                $this->statusText((string) ($row['status'] ?? '')),
                $this->tr((string) ($row['deposit_status'] ?? 'No Deposit')),
                round((float) ($row['deposit_paid_amount'] ?? 0), 4),
                round((float) ($row['remaining_balance'] ?? 0), 4),
                $this->formatOperationalCostSummary($row),
                $cbm===null?null:round($cbm, 6),
                $weight===null?null:round($weight, 4),
                $row['currency']??'',
                self::identifierSummary($items, 'item_no'),
                self::identifierSummary($items, 'item_number'),
            ];
        }, $rows);

        $this->exportSimpleTable('Orders Export', $headers, $bodyRows, $filename);
    }

    public function exportReceivingQueueSummary(array $rows, string $filename = 'receiving_queue.xlsx'): void
    {
        $headers = [
            'Order ID',
            'Photo',
            'Customer',
            'Supplier',
            'Supplier Phone',
            'Expected Ready',
            'Status',
            'Shipping Codes',
            'Total Cartons',
            'Declared CBM',
            'Declared Weight (kg)',
            'Items Summary',
            'I.I.N',
            'Item Number',
        ];

        $bodyRows = array_map(function (array $row): array {
            $items = is_array($row['items'] ?? null) ? $row['items'] : [];
            $shippingCodes = [];
            $totalCartons = 0;
            $itemsSummary = [];
            foreach ($items as $item) {
                $shippingCode = trim((string) ($item['shipping_code'] ?? ''));
                if ($shippingCode !== '') {
                    $shippingCodes[$shippingCode] = true;
                }
                $totalCartons += (int) ($item['cartons'] ?? 0);
                $summaryParts = [
                    $shippingCode !== '' ? $shippingCode : '-',
                    (string) ((int) ($item['cartons'] ?? 0)) . 'ctn',
                    'HS:' . (trim((string) ($item['hs_code'] ?? '')) !== '' ? (string) $item['hs_code'] : '-'),
                ];
                foreach ([
                    'Brand' => ($item['what_brand'] ?? '') ?: ($item['brand'] ?? ''),
                    'Materials' => $item['materials'] ?? '',
                    'Code' => $item['code'] ?? '',
                    'Express' => $item['express_number'] ?? '',
                    'Size' => $item['size'] ?? '',
                ] as $label => $value) {
                    $value = trim((string) $value);
                    if ($value !== '') {
                        $summaryParts[] = $label . ':' . $value;
                    }
                }
                $height = $item['height'] ?? $item['item_height'] ?? null;
                $width = $item['width'] ?? $item['item_width'] ?? null;
                $length = $item['length'] ?? $item['item_length'] ?? null;
                $dims = array_filter([
                    $height !== null && $height !== '' ? 'H:' . $height : '',
                    $width !== null && $width !== '' ? 'W:' . $width : '',
                    $length !== null && $length !== '' ? 'L:' . $length : '',
                ]);
                if ($dims) {
                    $summaryParts[] = 'Dims ' . implode('/', $dims);
                }
                $itemsSummary[] = trim(implode(' ', $summaryParts));
            }

            return [
                (int) ($row['id'] ?? 0),
                $this->collectItemImagePaths($items),
                self::formatCustomerDisplay($row, $items),
                (string) ($row['supplier_name'] ?? ''),
                (string) ($row['supplier_phone'] ?? ''),
                (string) ($row['expected_ready_date'] ?? ''),
                $this->statusText((string) ($row['status'] ?? '')),
                implode('; ', array_keys($shippingCodes)),
                $totalCartons,
                round((float) ($row['declared_cbm'] ?? 0), 6),
                round((float) ($row['declared_weight'] ?? 0), 4),
                implode('; ', array_filter($itemsSummary)),
                self::identifierSummary($items, 'item_no'),
                self::identifierSummary($items, 'item_number'),
            ];
        }, $rows);

        $this->exportSimpleTable('Receiving Queue', $headers, $bodyRows, $filename);
    }

    public function exportWarehouseStockSummary(array $rows, string $filename = 'warehouse_stock.xlsx'): void
    {
        $headers = [
            'Order ID',
            'Photo',
            'Customer',
            'Supplier',
            'Status',
            'Item',
            'Shipping Code',
            'I.I.N',
            'Item Type',
            'Ordered Quantity',
            'Actual Quantity',
            'Actual Cartons',
            'Declared CBM',
            'Actual CBM',
            'Actual Weight',
            'Actual Height',
            'Actual Width',
            'Actual Length',
            'Item Number',
            'Remaining Quantity',
            'Warehouse State',
            'Reconciliation Required',
        ];

        $bodyRows = array_map(function (array $row): array {
            return [
                (int) ($row['order_id'] ?? 0),
                $row['image_paths'] ?? [],
                (string) ($row['customer_name'] ?? ''),
                (string) ($row['supplier_name'] ?? ''),
                $this->statusText((string) ($row['status'] ?? '')),
                (string) (($row['description_en'] ?? '') ?: ($row['description_cn'] ?? '') ?: ($row['product_desc_en'] ?? '') ?: ($row['product_desc_cn'] ?? '')),
                (string) ($row['shipping_code'] ?? ''),
                (string) ($row['item_no'] ?? ''),
                $this->itemTypeText((string) ($row['item_type_code'] ?? 'unclassified')),
                $row['ordered_quantity'] ?? $row['quantity'] ?? null,
                $row['item_actual_quantity'] ?? null,
                $row['item_actual_cartons'] ?? null,
                $row['declared_cbm'] ?? null,
                $row['item_actual_cbm'] ?? null,
                $row['item_actual_weight'] ?? null,
                $row['item_actual_height'] ?? null,
                $row['item_actual_width'] ?? null,
                $row['item_actual_length'] ?? null,
                (string) ($row['item_number'] ?? ''),
                $row['remaining_quantity'] ?? null,
                $row['warehouse_state'] ?? '',
                !empty($row['reconciliation_required']) ? 'Yes' : 'No',
            ];
        }, $rows);

        foreach ($rows as $row) foreach ($row['item_identifiers'] ?? [] as $content) {
            $reference = array_fill(0, count($headers), '');
            $reference[0] = $row['order_id'] ?? '';
            $reference[1] = [];
            $reference[2] = $row['customer_name'] ?? '';
            $reference[3] = $row['supplier_name'] ?? '';
            $reference[4] = $this->statusText((string) ($row['status'] ?? ''));
            $reference[5] = $this->tr('Contained item') . ': ' . ($content['description_en'] ?? $content['description_cn'] ?? '');
            $reference[7] = $content['item_no'] ?? '';
            $reference[18] = $content['item_number'] ?? '';
            $bodyRows[] = $reference; // No duplicated stock quantities/weights.
        }
        $this->exportSimpleTable('Warehouse Stock', $headers, $bodyRows, $filename);
    }

    public function exportTable(string $title, array $headers, array $rows, string $filename): void
    {
        $this->exportSimpleTable($title, $headers, $rows, $filename);
    }

    private function setStandardColumnWidths($sheet): void
    {
        $widths = [
            'A' => 14,
            'B' => 24,
            'C' => 16,
            'D' => 24,
            'E' => 14,
            'F' => 18,
            'G' => 14,
            'H' => self::PHOTO_COLUMN_WIDTH,
            'I' => 20.14,
            'J' => 40,
            'K' => 34,
            'L' => 12,
            'M' => 12,
            'N' => 12,
            'O' => 15.29,
            'P' => 15.29,
            'Q' => 15.29,
            'R' => 12,
            'S' => 15.29,
            'T' => 15.29,
            'U' => 9,
            'V' => 15.29,
            'W' => 15.29,
            'X' => 15.29,
            'Y' => 18,
            'Z' => 18,
            'AA' => 16,
            'AB' => 32,
            'AC' => 22,
        ];

        foreach ($widths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
    }

    private function setContainerColumnWidths($sheet): void
    {
        $widths = [
            'A' => 5,
            'B' => 14,
            'C' => 18,
            'D' => 14,
            'E' => self::PHOTO_COLUMN_WIDTH,
            'F' => 18,
            'G' => 24,
            'H' => 18,
            'I' => 22,
            'J' => 48,
            'K' => 12,
            'L' => 12,
            'M' => 12,
            'N' => 14,
            'O' => 14,
            'P' => 16,
            'Q' => 12,
            'R' => 12,
            'S' => 9,
            'T' => 12,
            'U' => 18,
            'V' => 18,
            'W' => 22,
        ];

        foreach ($widths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
    }

    private function writeCompanyHeader($sheet, int $startRow, string $lastColumn, string $title = 'Goods Details', array $metadata = [], string $note = ''): int
    {
        $titleRow = $startRow;
        $this->setSafeCell($sheet,'A' . $titleRow, $title);
        $sheet->mergeCells("A{$titleRow}:{$lastColumn}{$titleRow}");
        $sheet->getStyle("A{$titleRow}:{$lastColumn}{$titleRow}")->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 16, 'bold' => true, 'color' => ['rgb' => self::HEADER_BLUE]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::LIGHT_BLUE]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($titleRow)->setRowHeight(28);

        $brandRow = $startRow + 1;
        $this->setSafeCell($sheet,'A' . $brandRow, $this->tr('Salameh Global / CargoChina'));
        $sheet->mergeCells("A{$brandRow}:{$lastColumn}{$brandRow}");
        $sheet->getStyle("A{$brandRow}:{$lastColumn}{$brandRow}")->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => ['rgb' => self::HEADER_BLUE]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        for ($index = 0; $index < 4; $index++) {
            $row = $startRow + 2 + $index;
            $entry = $metadata[$index] ?? ['', ''];
            $label = (string) ($entry[0] ?? '');
            $value = $entry[1] ?? '';
            $this->setSafeCell($sheet,'A' . $row, $label);
            if (is_string($value)
                && preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $value)
                && preg_match('/date|ready|received|created|updated|generated/i', $label)) {
                $excelDate = $this->excelDateValue($value);
                $this->setSafeCell($sheet,'B' . $row, $excelDate ?? $value);
                $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode(str_contains($value, ':') ? 'yyyy-mm-dd hh:mm:ss' : 'yyyy-mm-dd');
            } else {
                $this->setSafeCell($sheet,'B' . $row, $value);
            }
            $sheet->getStyle('A' . $row)->getFont()->setName('Arial')->setBold(true);
            $sheet->getStyle('A' . $row . ':' . $lastColumn . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        }

        $noteRow = $startRow + 6;
        $this->setSafeCell($sheet,'A' . $noteRow, $note);
        $sheet->mergeCells("A{$noteRow}:{$lastColumn}{$noteRow}");
        $sheet->getStyle("A{$noteRow}:{$lastColumn}{$noteRow}")->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => ['rgb' => self::HEADER_BLUE]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3F8FF']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension($noteRow)->setRowHeight(24);

        return $startRow + 7;
    }

    private function writeStandardColumnHeaders($sheet, int $row): int
    {
        $headers = [
            'A' => 'SUPPLIER',
            'B' => 'SUPPLIER NAME',
            'C' => 'BRAND',
            'D' => 'MATERIALS',
            'E' => 'WHAT BRAND',
            'F' => 'GOOD TYPE',
            'G' => 'CODE',
            'H' => 'PHOTO',
            'I' => 'I.I.N',
            'J' => 'ENGLISH DESCRIPTION',
            'K' => 'CHINESE DESCRIPTION',
            'L' => 'HEIGHT',
            'M' => 'WIDTH',
            'N' => 'LENGTH',
            'O' => 'TOTAL CTNS',
            'P' => 'QTY/CTN',
            'Q' => 'TOTAL QTY',
            'R' => 'UNIT',
            'S' => 'UNIT PRICE',
            'T' => 'TOTAL AMOUNT',
            'U' => 'CBM',
            'V' => 'TOTAL CBM',
            'W' => 'GWKG',
            'X' => 'TOTAL GW',
            'Y' => 'EXPRESS NO',
            'Z' => 'SIZE',
            'AA' => 'HS CODE',
            'AB' => 'NOTES',
            'AC' => 'Item Number',
        ];

        $chineseRow = $row + 1;
        foreach ($headers as $col => $label) {
            $this->setSafeCell($sheet,$col . $row, $this->trForLocale($label, 'en'));
            $this->setSafeCell($sheet,$col . $chineseRow, $this->trForLocale($label, 'zh-CN'));
        }

        $this->styleRange($sheet, 'A' . $row . ':' . self::STANDARD_LAST_COL . $chineseRow, [
            'font' => ['name' => 'Arial', 'size' => 11, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::MASTER_TABLE_BLUE]],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(36);
        $sheet->getRowDimension($chineseRow)->setRowHeight(36);

        return $chineseRow + 1;
    }

    private function writeStandardItems($sheet, array $items, int $startRow, array $order = []): int
    {
        $row = $startRow;
        $this->primeCanonicalImageContexts($items, (int) ($order['id'] ?? 0));

        foreach ($this->groupItemsBySupplier($items, $order) as $group) {
            if ($group['supplier_name'] !== '' || $group['supplier_phone'] !== '' || $group['supplier_info'] !== '') {
                $row = $this->writeSupplierGroupHeader(
                    $sheet,
                    $row,
                    $group['supplier_name'],
                    $group['supplier_phone'],
                    $group['supplier_info'],
                    self::STANDARD_LAST_COL
                );
            }

            foreach ($group['items'] as $item) {
                $cartons = (float) ($item['cartons'] ?? 0);
                $qtyPerCarton = (float) ($item['qty_per_carton'] ?? 0);
                $quantity = $this->resolveQuantity($item);
                $unitPrice = $this->resolveUnitPrice($item);
                $scope = strtolower(trim((string) ($item['dimensions_scope'] ?? $item['product_dimensions_scope'] ?? 'piece')));
                $multiplier = $scope === 'carton' && $cartons > 0 ? $cartons : $quantity;
                $cbmPer = $multiplier > 0 ? round((float) ($item['declared_cbm'] ?? 0) / $multiplier, 6) : '';
                $weightPer = $multiplier > 0 ? round((float) ($item['declared_weight'] ?? 0) / $multiplier, 4) : '';

                $supplierName = (string) ($item['supplier_name'] ?? $group['supplier_name'] ?? '');
                $supplierDisplay = trim((string) ($item['supplier_code'] ?? $item['supplier_store_id'] ?? ''));
                $this->setSafeCell($sheet,'A' . $row, $supplierDisplay !== '' ? $supplierDisplay : $supplierName);
                $this->setSafeCell($sheet,'B' . $row, $supplierName);
                $this->setSafeCell($sheet,'C' . $row, $this->itemText($item, 'brand') ?: $this->itemText($item, 'what_brand'));
                $this->setSafeCell($sheet,'D' . $row, $this->itemText($item, 'materials'));
                $this->setSafeCell($sheet,'E' . $row, $this->itemText($item, 'what_brand'));
                $this->setSafeCell($sheet,'F' . $row, $this->copyNormalGoodsText($item));
                $this->setSafeCell($sheet,'G' . $row, $this->itemText($item, 'code'));
                $sheet->setCellValueExplicit('I' . $row, (string) ($item['item_no'] ?? $item['shipping_code'] ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('AC' . $row, (string) ($item['item_number'] ?? ''), DataType::TYPE_STRING);
                $this->setSafeCell($sheet,'J' . $row, trim((string) ($item['description_en'] ?? '')));
                $this->setSafeCell($sheet,'K' . $row, trim((string) ($item['description_cn'] ?? '')));
                $this->setSafeCell($sheet,'L' . $row, $this->dimensionValue($item, 'height', 'item_height'));
                $this->setSafeCell($sheet,'M' . $row, $this->dimensionValue($item, 'width', 'item_width'));
                $this->setSafeCell($sheet,'N' . $row, $this->dimensionValue($item, 'length', 'item_length'));
                $this->setSafeCell($sheet,'O' . $row, $cartons ?: '');
                $this->setSafeCell($sheet,'P' . $row, $qtyPerCarton ?: '');
                $this->setSafeCell($sheet,'Q' . $row, $quantity ?: '');
                $this->setSafeCell($sheet,'R' . $row, $this->itemText($item, 'unit'));
                $this->setSafeCell($sheet,'S' . $row, $unitPrice !== null ? $unitPrice : '');
                $this->setSafeCell($sheet,'T' . $row, ($unitPrice !== null && $quantity > 0) ? round($unitPrice * $quantity, 4) : '');
                $this->setSafeCell($sheet,'U' . $row, $cbmPer);
                $this->setSafeCell($sheet,'V' . $row, round((float) ($item['declared_cbm'] ?? 0), 6));
                $this->setSafeCell($sheet,'W' . $row, $weightPer);
                $this->setSafeCell($sheet,'X' . $row, round((float) ($item['declared_weight'] ?? 0), 4));
                $this->setSafeCell($sheet,'Y' . $row, $this->itemText($item, 'express_number'));
                $this->setSafeCell($sheet,'Z' . $row, $this->resolveItemSize($item));
                $this->setSafeCell($sheet,'AA' . $row, $this->itemText($item, 'hs_code'));
                $this->setSafeCell($sheet,'AB' . $row, $this->itemText($item, 'notes'));

                $this->styleRange($sheet, 'A' . $row . ':' . self::STANDARD_LAST_COL . $row, [
                    'font' => ['name' => 'Arial', 'size' => 11],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFFFF']],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
                ]);
                $sheet->getStyle('L' . $row . ':N' . $row)->getNumberFormat()->setFormatCode('#,##0.####');
                $sheet->getStyle('S' . $row . ':T' . $row)->getNumberFormat()->setFormatCode('#,##0.####');
                $sheet->getStyle('U' . $row . ':V' . $row)->getNumberFormat()->setFormatCode('#,##0.######');
                $sheet->getStyle('W' . $row . ':X' . $row)->getNumberFormat()->setFormatCode('#,##0.####');

                $sheet->getRowDimension($row)->setRowHeight(self::PHOTO_ROW_HEIGHT_PT);
                $this->writePhotoCell($sheet, self::PHOTO_COLUMN . $row, $item['image_paths'] ?? [], [
                    'scope' => 'order',
                    'order_id' => (int) ($order['id'] ?? 0),
                    'order_item_id' => (int) ($item['id'] ?? 0),
                    'product_id' => (int) ($item['product_id'] ?? 0),
                    'item_no' => (string) ($item['item_no'] ?? $item['shipping_code'] ?? ''),
                ]);
                $row++;
            }
        }

        return $row;
    }

    private function writeStandardReceiptFees($sheet, int $startRow, array $order, array $items): int
    {
        $fees = $this->extractReceiptFees($order);
        if (!$fees) {
            return $startRow;
        }

        $row = $startRow + 1;
        $this->setSafeCell($sheet,'A' . $row, $this->tr('Customer-facing receiving fees'));
        $sheet->mergeCells('A' . $row . ':' . self::STANDARD_LAST_COL . $row);
        $this->styleRange($sheet, 'A' . $row . ':' . self::STANDARD_LAST_COL . $row, [
            'font' => ['name' => 'Arial', 'size' => 11, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::LIGHT_BLUE]],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(22);
        $row++;

        foreach ($fees as $fee) {
            $amount = round((float) ($fee['amount'] ?? 0), 4);
            $currency = $this->normalizeCurrency((string) ($fee['currency'] ?? $order['currency'] ?? 'USD'));
            $label = trim((string) ($fee['fee_label'] ?? $fee['label'] ?? 'Warehouse fee'));
            $notes = trim((string) ($fee['notes'] ?? ''));

            $this->setSafeCell($sheet,'A' . $row, $label !== '' ? $label : $this->tr('Warehouse fee'));
            $this->setSafeCell($sheet,'T' . $row, $amount);
            $this->setSafeCell($sheet,'U' . $row, $currency);
            $this->setSafeCell($sheet,'V' . $row, $notes);
            $sheet->mergeCells('A' . $row . ':S' . $row);
            $sheet->mergeCells('V' . $row . ':' . self::STANDARD_LAST_COL . $row);
            $this->styleRange($sheet, 'A' . $row . ':' . self::STANDARD_LAST_COL . $row, [
                'font' => ['name' => 'Arial', 'size' => 10],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFFFF']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
            ]);
            $sheet->getStyle('T' . $row)->getNumberFormat()->setFormatCode('#,##0.####');
            $sheet->getStyle('T' . $row . ':U' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getRowDimension($row)->setRowHeight(22);
            $row++;
        }

        $goodsTotals = $this->calculateStandardSellTotals($items, $order);
        $feeTotals = $this->calculateReceiptFeeTotals($fees, (string) ($order['currency'] ?? 'USD'));
        $summaryRows = [
            ['Goods total', $goodsTotals, self::LIGHT_BLUE],
            ['Receiving fees total', $feeTotals, self::LIGHT_YELLOW],
            ['Total amount with receiving fees', $this->mergeCurrencyTotals($goodsTotals, $feeTotals), 'FFE699'],
        ];

        foreach ($summaryRows as $entry) {
            [$label, $amounts, $fill] = $entry;
            $this->setSafeCell($sheet,'A' . $row, $this->tr($label));
            $this->setSafeCell($sheet,'T' . $row, $this->formatCurrencyBreakdown($amounts));
            $sheet->mergeCells('A' . $row . ':S' . $row);
            $sheet->mergeCells('T' . $row . ':' . self::STANDARD_LAST_COL . $row);
            $this->styleRange($sheet, 'A' . $row . ':' . self::STANDARD_LAST_COL . $row, [
                'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $fill]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
            ]);
            $sheet->getStyle('T' . $row . ':' . self::STANDARD_LAST_COL . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getRowDimension($row)->setRowHeight(22);
            $row++;
        }

        return $row;
    }

    private function writeStandardOperationalCosts($sheet, int $startRow, array $order): int
    {
        $costs = is_array($order['operational_costs'] ?? null) ? $order['operational_costs'] : [];
        $lines = is_array($costs['lines'] ?? null) ? $costs['lines'] : [];
        if (!$lines) {
            return $startRow;
        }

        $row = $startRow + 1;
        $this->setSafeCell($sheet,'A' . $row, $this->tr('Shipment Charges'));
        $sheet->mergeCells('A' . $row . ':' . self::STANDARD_LAST_COL . $row);
        $this->styleRange($sheet, 'A' . $row . ':' . self::STANDARD_LAST_COL . $row, [
            'font' => ['name' => 'Arial', 'size' => 11, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::LIGHT_YELLOW]],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
        ]);
        $row++;

        foreach ($lines as $cost) {
            $type = (string) ($cost['cost_type_label_en'] ?? $cost['cost_type_code'] ?? '');
            $description = (string) ($cost['description_en'] ?? $cost['description_zh'] ?? '');
            $provider = (string) ($cost['supplier_name'] ?? $cost['service_provider'] ?? '');
            $detail = trim(implode(' | ', array_filter([$description, $provider, (string) ($cost['responsible_payer'] ?? ''), (string) ($cost['allocation_method'] ?? '')])));
            $this->setSafeCell($sheet,'A' . $row, $type);
            $this->setSafeCell($sheet,'T' . $row, (float) ($cost['base_amount'] ?? 0));
            $this->setSafeCell($sheet,'U' . $row, (string) ($cost['base_currency'] ?? ''));
            $this->setSafeCell($sheet,'V' . $row, $detail);
            $sheet->mergeCells('A' . $row . ':S' . $row);
            $sheet->mergeCells('V' . $row . ':' . self::STANDARD_LAST_COL . $row);
            $this->styleRange($sheet, 'A' . $row . ':' . self::STANDARD_LAST_COL . $row, [
                'font' => ['name' => 'Arial', 'size' => 10],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFFFF']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
            ]);
            $sheet->getStyle('T' . $row)->getNumberFormat()->setFormatCode('#,##0.0000');
            $row++;
        }

        $this->setSafeCell($sheet,'A' . $row, $this->tr('Shipment Charges Total'));
        $this->setSafeCell($sheet,'T' . $row, (float) ($costs['base_total'] ?? 0));
        $this->setSafeCell($sheet,'U' . $row, (string) ($costs['base_currency'] ?? ''));
        $sheet->mergeCells('A' . $row . ':S' . $row);
        $sheet->mergeCells('U' . $row . ':' . self::STANDARD_LAST_COL . $row);
        $this->styleRange($sheet, 'A' . $row . ':' . self::STANDARD_LAST_COL . $row, [
            'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::LIGHT_YELLOW]],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
        ]);
        $sheet->getStyle('T' . $row)->getNumberFormat()->setFormatCode('#,##0.0000');
        return $row + 1;
    }

    private function formatOperationalCostSummary(array $row): string
    {
        $parts = [];
        foreach (($row['operational_cost_summary']['totals'] ?? []) as $total) {
            $amount = rtrim(rtrim(number_format((float) ($total['amount'] ?? 0), 4, '.', ''), '0'), '.');
            $parts[] = ($amount === '' ? '0' : $amount) . ' ' . (string) ($total['currency'] ?? '');
        }
        return implode(' + ', $parts);
    }

    private function writeContainerColumnHeaders($sheet, int $row): int
    {
        $headers = [
            'B' => 'WHAT BRAND',
            'C' => 'GOOD TYPE',
            'D' => 'CODE',
            'E' => 'PHOTO',
            'F' => 'I.I.N',
            'G' => 'SUPPLIER',
            'H' => 'SUPPLIER PHONE',
            'I' => 'ACCOUNT NB',
            'J' => 'DESCRIPTION',
            'K' => 'TOTAL CTNS',
            'L' => 'QTY/CTN',
            'M' => 'TOTAL QTY',
            'N' => 'UNIT PRICE',
            'O' => 'FACTORY PRICE',
            'P' => 'TOTAL AMOUNT',
            'Q' => 'CBM',
            'R' => 'TOTAL CBM',
            'S' => 'GWKG',
            'T' => 'TOTAL GW',
            'U' => 'express NO',
            'V' => 'size',
            'W' => 'Item Number',
        ];

        $chineseRow = $row + 1;
        foreach ($headers as $col => $label) {
            $this->setSafeCell($sheet,$col . $row, $this->trForLocale($label, 'en'));
            $this->setSafeCell($sheet,$col . $chineseRow, $this->trForLocale($label, 'zh-CN'));
        }

        $this->styleRange($sheet, 'B' . $row . ':' . self::CONTAINER_LAST_COL . $chineseRow, [
            'font' => ['name' => 'Arial', 'size' => 11, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::MASTER_TABLE_BLUE]],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
        ]);
        $this->styleRange($sheet, 'G' . $row . ':I' . $chineseRow, [
            'font' => ['color' => ['rgb' => self::HEADER_BLUE]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::LIGHT_YELLOW]],
        ]);
        $this->styleRange($sheet, 'O' . $row . ':O' . $chineseRow, [
            'font' => ['color' => ['rgb' => self::HEADER_BLUE]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::LIGHT_YELLOW]],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(36);
        $sheet->getRowDimension($chineseRow)->setRowHeight(36);

        return $chineseRow + 1;
    }

    private function buildCustomerSections(array $ordersWithItems): array
    {
        $sections = [];

        foreach ($ordersWithItems as $entry) {
            $order = is_array($entry['order'] ?? null) ? $entry['order'] : [];
            $items = is_array($entry['items'] ?? null) ? $entry['items'] : [];
            $customerDisplay = self::formatCustomerDisplay($order, $items);
            $customerId = (int) ($order['customer_id'] ?? 0);
            $sectionKey = $customerId > 0 ? 'customer:' . $customerId : 'name:' . md5(strtolower($customerDisplay));

            if (!isset($sections[$sectionKey])) {
                $sections[$sectionKey] = [
                    'key' => $sectionKey,
                    'customer_id' => $customerId ?: null,
                    'customer_display' => $customerDisplay,
                    'customer_phone' => (string) ($order['customer_phone'] ?? ''),
                    'order_ids' => [],
                    'orders' => [],
                ];
            }

            $sections[$sectionKey]['orders'][] = [
                'order' => $order,
                'items' => $items,
            ];
            if (!empty($order['id'])) {
                $sections[$sectionKey]['order_ids'][(int) $order['id']] = true;
            }
        }

        return array_values($sections);
    }

    private function writeContainerSectionHeader($sheet, array $section, int $row): int
    {
        $orderIds = array_keys($section['order_ids']);
        $orderLabel = $orderIds
            ? $this->tr('Orders: {orders}', ['orders' => '#' . implode(', #', $orderIds)])
            : $this->tr('Orders: -');

        $this->setSafeCell($sheet,'A' . $row, '##');
        $this->setSafeCell($sheet,'B' . $row, $section['customer_display'] ?: $this->tr('Customer'));
        $this->setSafeCell($sheet,'K' . $row, trim((string) ($section['customer_phone'] ?? '')) !== ''
            ? $this->tr('Phone: {phone}', ['phone' => $section['customer_phone']])
            : $this->tr('Phone: -'));
        $this->setSafeCell($sheet,'N' . $row, $orderLabel);
        $sheet->mergeCells('B' . $row . ':J' . $row);
        $sheet->mergeCells('K' . $row . ':M' . $row);
        $sheet->mergeCells('N' . $row . ':' . self::CONTAINER_LAST_COL . $row);

        $this->styleRange($sheet, 'A' . $row . ':' . self::CONTAINER_LAST_COL . $row, [
            'font' => ['name' => 'Arial', 'size' => 11, 'bold' => true],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::SOFT_SECTION]],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
        ]);
        $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension($row)->setRowHeight(24);

        return $row + 1;
    }

    private function writeContainerItems($sheet, array $items, array $order, int $row, array &$sectionTotals, array &$overallTotals): int
    {
        $this->primeCanonicalImageContexts($items, (int) ($order['id'] ?? 0));
        foreach ($this->groupItemsBySupplier($items, $order) as $group) {
            if ($group['supplier_name'] !== '' || $group['supplier_phone'] !== '' || $group['supplier_info'] !== '') {
                $row = $this->writeSupplierGroupHeader(
                    $sheet,
                    $row,
                    $group['supplier_name'],
                    $group['supplier_phone'],
                    $group['supplier_info'],
                    self::CONTAINER_LAST_COL
                );
            }

            foreach ($group['items'] as $item) {
                $cartons = (float) ($item['cartons'] ?? 0);
                $qtyPerCarton = (float) ($item['qty_per_carton'] ?? 0);
                $quantity = $this->resolveQuantity($item);
                $unitPrice = $this->resolveUnitPrice($item);
                $factoryPrice = $this->resolveFactoryPriceDisplay($item);
                $factoryPriceForTotals = $this->resolveFactoryPriceForTotals($item, $unitPrice);
                $sellTotal = ($unitPrice !== null && $quantity > 0) ? round($unitPrice * $quantity, 4) : 0.0;
                $factoryTotal = ($factoryPriceForTotals !== null && $quantity > 0) ? round($factoryPriceForTotals * $quantity, 4) : 0.0;
                $currency = $this->resolveCurrency($order, $item);
                $scope = strtolower(trim((string) ($item['dimensions_scope'] ?? $item['product_dimensions_scope'] ?? 'piece')));
                $multiplier = $scope === 'carton' && $cartons > 0 ? $cartons : $quantity;
                $totalCbm = isset($item['declared_cbm'])?round((float)$item['declared_cbm'],6):null;
                $totalWeight = isset($item['declared_weight'])?round((float)$item['declared_weight'],4):null;
                $cbmPer = $multiplier > 0 && $totalCbm!==null ? round($totalCbm / $multiplier, 6) : '';
                $weightPer = $multiplier > 0 && $totalWeight!==null ? round($totalWeight / $multiplier, 4) : '';
                $accountNumber = $this->extractSupplierAccountReference($item, $order);
                $supplierPhone = trim((string) ($item['supplier_phone'] ?? $group['supplier_phone'] ?? $order['supplier_phone'] ?? ''));

                $this->setSafeCell($sheet,'B' . $row, $this->itemText($item, 'what_brand'));
                $this->setSafeCell($sheet,'C' . $row, $this->copyNormalGoodsText($item));
                $this->setSafeCell($sheet,'D' . $row, $this->itemText($item, 'code'));
                $sheet->setCellValueExplicit('F' . $row, (string) ($item['item_no'] ?? $item['shipping_code'] ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('W' . $row, (string) ($item['item_number'] ?? ''), DataType::TYPE_STRING);
                $this->setSafeCell($sheet,'G' . $row, (string) ($item['supplier_name'] ?? $group['supplier_name'] ?? $order['supplier_name'] ?? ''));
                $this->setSafeCell($sheet,'H' . $row, $supplierPhone);
                $this->setSafeCell($sheet,'I' . $row, $accountNumber !== '' ? $accountNumber : $group['supplier_info']);
                $this->setSafeCell($sheet,'J' . $row, $this->descriptionText($item));
                $this->setSafeCell($sheet,'K' . $row, $cartons ?: '');
                $this->setSafeCell($sheet,'L' . $row, $qtyPerCarton ?: '');
                $this->setSafeCell($sheet,'M' . $row, $quantity ?: '');
                $this->setSafeCell($sheet,'N' . $row, $unitPrice !== null ? $unitPrice : '');
                $this->setSafeCell($sheet,'O' . $row, $factoryPrice !== null ? $factoryPrice : '');
                $this->setSafeCell($sheet,'P' . $row, $sellTotal ?: '');
                $this->setSafeCell($sheet,'Q' . $row, $cbmPer);
                $this->setSafeCell($sheet,'R' . $row, $totalCbm ?: '');
                $this->setSafeCell($sheet,'S' . $row, $weightPer);
                $this->setSafeCell($sheet,'T' . $row, $totalWeight ?: '');
                $this->setSafeCell($sheet,'U' . $row, $this->itemText($item, 'express_number'));
                $this->setSafeCell($sheet,'V' . $row, $this->resolveItemSize($item));

                $this->styleRange($sheet, 'B' . $row . ':' . self::CONTAINER_LAST_COL . $row, [
                    'font' => ['name' => 'Arial', 'size' => 11],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFFFF']],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
                ]);
                $this->styleRange($sheet, 'G' . $row . ':I' . $row, [
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::LIGHT_YELLOW]],
                ]);
                $this->styleRange($sheet, 'O' . $row, [
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::LIGHT_YELLOW]],
                ]);
                $sheet->getStyle('J' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
                $sheet->getStyle('N' . $row . ':P' . $row)->getNumberFormat()->setFormatCode('#,##0.####');
                $sheet->getStyle('Q' . $row . ':R' . $row)->getNumberFormat()->setFormatCode('#,##0.######');
                $sheet->getStyle('S' . $row . ':T' . $row)->getNumberFormat()->setFormatCode('#,##0.####');
                $sheet->getRowDimension($row)->setRowHeight(self::PHOTO_ROW_HEIGHT_PT);
                $this->writePhotoCell($sheet, self::CONTAINER_PHOTO_COLUMN . $row, $item['image_paths'] ?? [], [
                    'scope' => 'container_order',
                    'order_id' => (int) ($order['id'] ?? 0),
                    'order_item_id' => (int) ($item['id'] ?? 0),
                    'product_id' => (int) ($item['product_id'] ?? 0),
                    'item_no' => (string) ($item['item_no'] ?? $item['shipping_code'] ?? ''),
                ]);

                foreach(['cbm'=>$totalCbm,'weight'=>$totalWeight,'quantity'=>$item['quantity']??null] as $metric=>$value)if($value===null){$sectionTotals[$metric.'_unknown']=true;$overallTotals[$metric.'_unknown']=true;}
                $sectionTotals['cartons'] += $cartons;
                $sectionTotals['quantity'] += $quantity;
                $sectionTotals['cbm'] += $totalCbm;
                $sectionTotals['weight'] += $totalWeight;
                $this->addCurrencyTotal($sectionTotals['sell'], $currency, $sellTotal);
                $this->addCurrencyTotal($sectionTotals['factory'], $currency, $factoryTotal);

                $overallTotals['cartons'] += $cartons;
                $overallTotals['quantity'] += $quantity;
                $overallTotals['cbm'] += $totalCbm;
                $overallTotals['weight'] += $totalWeight;
                $this->addCurrencyTotal($overallTotals['sell'], $currency, $sellTotal);
                $this->addCurrencyTotal($overallTotals['factory'], $currency, $factoryTotal);

                $row++;
            }
        }

        return $row;
    }

    private function writeSupplierGroupHeader($sheet, int $row, string $supplierName, string $supplierPhone, string $supplierInfo, string $lastCol): int
    {
        $this->setSafeCell($sheet,'A' . $row, '@@');
        $this->setSafeCell($sheet,'B' . $row, $this->tr('supplier name and info') . ':');
        $this->setSafeCell($sheet,'C' . $row, $supplierName !== '' ? $supplierName : '-');
        $this->setSafeCell($sheet,'D' . $row, $supplierPhone !== '' ? $supplierPhone : '-');
        $this->setSafeCell($sheet,'E' . $row, $supplierInfo !== '' ? $supplierInfo : '-');

        if ($lastCol > 'E') {
            $sheet->mergeCells('E' . $row . ':' . $lastCol . $row);
        }

        $this->styleRange($sheet, 'A' . $row . ':' . $lastCol . $row, [
            'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::LIGHT_YELLOW]],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
        ]);
        $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension($row)->setRowHeight(20);

        return $row + 1;
    }

    private function collectSectionExpenses(array $expenses, array $section, array &$usedExpenseIds): array
    {
        $customerId = (int) ($section['customer_id'] ?? 0);
        $orderIds = array_map('intval', array_keys($section['order_ids'] ?? []));
        $orderLookup = array_fill_keys($orderIds, true);
        $matched = [];

        foreach ($expenses as $expense) {
            $expenseId = (int) ($expense['id'] ?? 0);
            if ($expenseId > 0 && isset($usedExpenseIds[$expenseId])) {
                continue;
            }

            $matches = false;
            $orderId = (int) ($expense['order_id'] ?? 0);
            if ($orderId > 0 && isset($orderLookup[$orderId])) {
                $matches = true;
            } elseif ($customerId > 0) {
                $expenseCustomerId = (int) ($expense['customer_id'] ?? 0);
                $orderCustomerId = (int) ($expense['order_customer_id'] ?? 0);
                if ($expenseCustomerId === $customerId || $orderCustomerId === $customerId) {
                    $matches = true;
                }
            }

            if ($matches) {
                $matched[] = $expense;
                if ($expenseId > 0) {
                    $usedExpenseIds[$expenseId] = true;
                }
            }
        }

        return $matched;
    }

    private function writeSectionExpenses($sheet, array $expenses, int $row, array &$sectionTotals, array &$overallTotals): int
    {
        foreach ($expenses as $expense) {
            $currency = $this->normalizeCurrency((string) ($expense['currency'] ?? 'USD'));
            $amount = round((float) ($expense['amount'] ?? 0), 4);
            $labelParts = [$this->tr('Expense')];
            $category = trim((string) ($expense['category_name'] ?? ''));
            if ($category !== '') {
                $labelParts[] = $category;
            }
            $supplierName = trim((string) ($expense['supplier_name'] ?? ''));
            if ($supplierName !== '') {
                $labelParts[] = $supplierName;
            }
            $description = trim((string) ($expense['notes'] ?? $expense['description'] ?? $expense['title'] ?? ''));
            if ($description === '') {
                $description = trim((string) ($expense['reference_no'] ?? ''));
            }
            if ($description === '') {
                $description = $this->tr('Container / customer expense');
            }

            $this->setSafeCell($sheet,'B' . $row, implode(' - ', $labelParts));
            $this->setSafeCell($sheet,'J' . $row, $description);
            $this->setSafeCell($sheet,'P' . $row, $this->formatCurrencyBreakdown([$currency => $amount]));
            $sheet->mergeCells('B' . $row . ':I' . $row);
            $sheet->mergeCells('J' . $row . ':O' . $row);
            $sheet->mergeCells('P' . $row . ':' . self::CONTAINER_LAST_COL . $row);

            $this->styleRange($sheet, 'B' . $row . ':' . self::CONTAINER_LAST_COL . $row, [
                'font' => ['name' => 'Arial', 'size' => 10],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::LIGHT_YELLOW]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
            ]);
            $sheet->getStyle('P' . $row . ':' . self::CONTAINER_LAST_COL . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getRowDimension($row)->setRowHeight(22);

            $this->addCurrencyTotal($sectionTotals['expenses'], $currency, $amount);
            $this->addCurrencyTotal($overallTotals['expenses'], $currency, $amount);
            $row++;
        }

        return $row;
    }

    private function writeSectionTotals($sheet, array $section, array $sectionTotals, int $row): int
    {
        $rows = [
            ['Section item sell total', $sectionTotals['sell'], self::LIGHT_YELLOW],
            ['Section factory total', $sectionTotals['factory'], self::LIGHT_YELLOW],
            ['Section expenses total', $sectionTotals['expenses'], self::LIGHT_YELLOW],
            ['Section total need to pay', $this->mergeCurrencyTotals($sectionTotals['factory'], $sectionTotals['expenses']), 'FFE699'],
        ];

        foreach ($rows as $entry) {
            [$label, $amounts, $fill] = $entry;
            $this->setSafeCell($sheet,'B' . $row, $section['customer_display'] . ' - ' . $this->tr($label));
            $this->setSafeCell($sheet,'P' . $row, !empty($sectionTotals['quantity_unknown']) && $label!=='Section expenses total'?'Unknown: reconciliation required':$this->formatCurrencyBreakdown($amounts));
            $sheet->mergeCells('B' . $row . ':O' . $row);
            $sheet->mergeCells('P' . $row . ':' . self::CONTAINER_LAST_COL . $row);
            $this->styleRange($sheet, 'B' . $row . ':' . self::CONTAINER_LAST_COL . $row, [
                'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $fill]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
            ]);
            $sheet->getStyle('P' . $row . ':' . self::CONTAINER_LAST_COL . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getRowDimension($row)->setRowHeight(22);
            $row++;
        }

        return $row;
    }

    private function writeOverallExpenseBlock($sheet, array $expenses, int $row, array &$overallTotals): int
    {
        $this->setSafeCell($sheet,'B' . $row, $this->tr('Container-wide expenses'));
        $sheet->mergeCells('B' . $row . ':' . self::CONTAINER_LAST_COL . $row);
        $this->styleRange($sheet, 'B' . $row . ':' . self::CONTAINER_LAST_COL . $row, [
            'font' => ['name' => 'Arial', 'size' => 11, 'bold' => true],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::SOFT_GREEN]],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(22);
        $row++;

        foreach ($expenses as $expense) {
            $currency = $this->normalizeCurrency((string) ($expense['currency'] ?? 'USD'));
            $amount = round((float) ($expense['amount'] ?? 0), 4);
            $description = trim((string) ($expense['notes'] ?? $expense['description'] ?? $expense['title'] ?? $expense['category_name'] ?? 'Container expense'));
            if ($description === 'Container expense') {
                $description = $this->tr('Container expense');
            }

            $this->setSafeCell($sheet,'B' . $row, $this->tr('Container expense'));
            $this->setSafeCell($sheet,'J' . $row, $description);
            $this->setSafeCell($sheet,'P' . $row, $this->formatCurrencyBreakdown([$currency => $amount]));
            $sheet->mergeCells('B' . $row . ':I' . $row);
            $sheet->mergeCells('J' . $row . ':O' . $row);
            $sheet->mergeCells('P' . $row . ':' . self::CONTAINER_LAST_COL . $row);
            $this->styleRange($sheet, 'B' . $row . ':' . self::CONTAINER_LAST_COL . $row, [
                'font' => ['name' => 'Arial', 'size' => 10],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::SOFT_GREEN]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
            ]);
            $sheet->getStyle('P' . $row . ':' . self::CONTAINER_LAST_COL . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getRowDimension($row)->setRowHeight(22);

            $this->addCurrencyTotal($overallTotals['expenses'], $currency, $amount);
            $row++;
        }

        return $row;
    }

    private function writeOverallTotals($sheet, array $overallTotals, int $row): void
    {
        $rows = [
            ['Overall item sell total', $overallTotals['sell']],
            ['Overall factory total', $overallTotals['factory']],
            ['Overall expenses total', $overallTotals['expenses']],
            ['Overall total need to pay', $this->mergeCurrencyTotals($overallTotals['factory'], $overallTotals['expenses'])],
            ['Overall CBM', ['METRIC' => !empty($overallTotals['cbm_unknown'])?'Unknown: reconciliation required':round($overallTotals['cbm'], 6)]],
            ['Overall weight', ['METRIC' => !empty($overallTotals['weight_unknown'])?'Unknown: reconciliation required':round($overallTotals['weight'], 4)]],
        ];

        foreach ($rows as $entry) {
            [$label, $amounts] = $entry;
            $value = array_key_exists('METRIC', $amounts)
                ? (string) $amounts['METRIC']
                : $this->formatCurrencyBreakdown($amounts);
            $this->setSafeCell($sheet,'B' . $row, $this->tr($label));
            $this->setSafeCell($sheet,'P' . $row, !array_key_exists('METRIC',$amounts) && !empty($overallTotals['quantity_unknown']) && $label!=='Overall expenses total'?'Unknown: reconciliation required':$value);
            $sheet->mergeCells('B' . $row . ':O' . $row);
            $sheet->mergeCells('P' . $row . ':' . self::CONTAINER_LAST_COL . $row);
            $this->styleRange($sheet, 'B' . $row . ':' . self::CONTAINER_LAST_COL . $row, [
                'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::LIGHT_RED]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
            ]);
            $sheet->getStyle('P' . $row . ':' . self::CONTAINER_LAST_COL . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getRowDimension($row)->setRowHeight(22);
            $row++;
        }
    }

    private function excelDateValue(string $value): ?float
    {
        $normalized = str_replace('T', ' ', trim($value));
        $format = str_contains($normalized, ':')
            ? (substr_count($normalized, ':') >= 2 ? '!Y-m-d H:i:s' : '!Y-m-d H:i')
            : '!Y-m-d';
        $date = DateTimeImmutable::createFromFormat($format, $normalized);
        return $date instanceof DateTimeImmutable ? ExcelDate::dateTimeToExcel($date) : null;
    }

    private function writePhotoCell($sheet, string $cell, $imagePaths, array $context = []): void
    {
        $providedPaths = $this->normalizeImagePaths($imagePaths);
        $sheet->getStyle($cell)->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 11],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFFFF']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
        ]);

        $photoColumn = preg_replace('/\d+$/', '', $cell) ?: self::PHOTO_COLUMN;
        $columnWidth = (float) $sheet->getColumnDimension($photoColumn)->getWidth();
        try {
            $defaultFont = $sheet->getParent()->getDefaultStyle()->getFont();
            $columnWidthPx = SharedDrawing::cellDimensionToPixels($columnWidth, $defaultFont);
            $heightPx = SharedDrawing::pointsToPixels(self::PHOTO_ROW_HEIGHT_PT);
        } catch (Throwable $e) {
            // PhpSpreadsheet has changed this helper signature across releases.
            $columnWidthPx = max(64, (int) round(max(1.0, $columnWidth) * 7 + 5));
            $heightPx = max(64, (int) round(self::PHOTO_ROW_HEIGHT_PT * 96 / 72));
            $this->logWorkbookImageDiagnostic(
                'excel_image_runtime_fallback',
                $context,
                'drawing_dimension_api_fallback',
                count($providedPaths)
            );
        }
        $imageWidthPx = max(32, (int) floor($columnWidthPx * self::PHOTO_IMAGE_SCALE));
        $imageHeightPx = max(32, (int) floor($heightPx * self::PHOTO_IMAGE_SCALE));
        $offsetX = max(0, (int) floor(($columnWidthPx - $imageWidthPx) / 2));
        $offsetY = max(0, (int) floor(($heightPx - $imageHeightPx) / 2));

        $drawing = null;
        $drawingMode = '';
        $drawingFallbackReason = '';
        $reasons = [];
        $attempted = [];
        $resolveCandidates = function (array $candidates) use (
            &$drawing,
            &$drawingMode,
            &$drawingFallbackReason,
            &$reasons,
            &$attempted,
            $imageWidthPx,
            $imageHeightPx
        ): void {
            foreach ($candidates as $candidate) {
                if ($drawing !== null || isset($attempted[$candidate])) {
                    continue;
                }
                $attempted[$candidate] = true;
                $outcome = $this->resolveWorkbookImageSourceOutcome($candidate);
                $sourcePath = (string) ($outcome['path'] ?? '');
                if ($sourcePath === '') {
                    $reason = (string) ($outcome['reason'] ?? 'unavailable');
                    $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                    continue;
                }

                $embed = $this->createWorkbookDrawingOutcome(
                    $sourcePath,
                    $imageWidthPx * 2,
                    $imageHeightPx * 2
                );
                if (($embed['drawing'] ?? null) !== null) {
                    $drawing = $embed['drawing'];
                    $drawingMode = (string) ($embed['mode'] ?? 'file');
                    $drawingFallbackReason = (string) ($embed['fallback_reason'] ?? '');
                    return;
                }
                $reason = (string) ($embed['reason'] ?? 'drawing_unavailable');
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            }
        };
        $resolveCandidates($providedPaths);

        $usedCanonicalFallback = false;
        $canonicalPaths = [];
        if ($drawing === null) {
            $canonicalPaths = $this->resolveCanonicalImagePathsForContext($context);
            $newCanonicalPaths = array_values(array_filter(
                $canonicalPaths,
                static fn(string $candidate): bool => !isset($attempted[$candidate])
            ));
            if ($newCanonicalPaths) {
                $usedCanonicalFallback = true;
                $resolveCandidates($newCanonicalPaths);
            }
        }
        $paths = $this->normalizeImagePaths([$providedPaths, $canonicalPaths]);

        if (!$paths) {
            $this->setSafeCell($sheet,$cell, $this->tr('No photo'));
            $this->logWorkbookImageDiagnostic('excel_image_unavailable', $context, 'no_candidates', 0);
            return;
        }
        if ($drawing === null) {
            $this->setSafeCell($sheet,$cell, $this->tr('No photo'));
            $this->logWorkbookImageDiagnostic(
                'excel_image_unavailable',
                $context,
                'all_candidates_not_embeddable',
                count($paths),
                $reasons
            );
            return;
        }
        if ($usedCanonicalFallback) {
            $this->logWorkbookImageDiagnostic(
                'excel_image_fallback_used',
                $context,
                'canonical_db_fallback_used',
                count($paths),
                $reasons
            );
        } elseif ($reasons) {
            $this->logWorkbookImageDiagnostic(
                'excel_image_fallback_used',
                $context,
                'later_candidate_used',
                count($paths),
                $reasons
            );
        }
        if ($drawingMode === 'memory') {
            $this->logWorkbookImageDiagnostic(
                'excel_image_runtime_fallback',
                $context,
                $drawingFallbackReason !== '' ? $drawingFallbackReason : 'memory_drawing_used',
                count($paths),
                $reasons
            );
        }

        try {
            $drawing->setCoordinates($cell);
            $drawing->setResizeProportional(false);
            $drawing->setWidth($imageWidthPx);
            $drawing->setHeight($imageHeightPx);
            $drawing->setOffsetX($offsetX);
            $drawing->setOffsetY($offsetY);
            $drawing->setWorksheet($sheet);
            $this->setSafeCell($sheet,$cell, '');
        } catch (Throwable $e) {
            $this->setSafeCell($sheet,$cell, $this->tr('No photo'));
            $this->logWorkbookImageDiagnostic('excel_image_unavailable', $context, 'worksheet_attachment_failed', count($paths));
        }
    }

    private function database(): ?PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }
        if ($this->pdoResolutionAttempted) {
            return null;
        }
        $this->pdoResolutionAttempted = true;
        if (!function_exists('getDb')) {
            return null;
        }
        try {
            $pdo = getDb();
            if ($pdo instanceof PDO) {
                $this->pdo = $pdo;
            }
        } catch (Throwable $e) {
            $this->pdo = null;
        }
        return $this->pdo;
    }

    private function primeCanonicalImageContexts(array $items, int $orderId): void
    {
        $targets = [];
        $orderItemIds = [];
        $productIds = [];
        foreach ($items as $item) {
            if (!is_array($item) || $this->normalizeImagePaths($item['image_paths'] ?? [])) {
                continue;
            }
            $orderItemId = max(0, (int) ($item['id'] ?? 0));
            $productId = max(0, (int) ($item['product_id'] ?? 0));
            if ($orderItemId === 0 && $productId === 0) {
                continue;
            }
            $cacheKey = $orderId . '|' . $orderItemId . '|' . $productId;
            if (array_key_exists($cacheKey, $this->workbookContextImageCache)) {
                continue;
            }
            $targets[$cacheKey] = [
                'order_item_id' => $orderItemId,
                'product_id' => $productId,
            ];
            if ($orderItemId > 0) {
                $orderItemIds[$orderItemId] = true;
            }
            if ($productId > 0) {
                $productIds[$productId] = true;
            }
        }
        if (!$targets) {
            return;
        }

        $pdo = $this->database();
        if (!$pdo) {
            foreach (array_keys($targets) as $cacheKey) {
                $this->workbookContextImageCache[$cacheKey] = [];
            }
            return;
        }

        $itemRows = [];
        if ($orderItemIds) {
            try {
                $ids = array_keys($orderItemIds);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare(
                    "SELECT id, order_id, product_id, image_paths
                     FROM order_items
                     WHERE id IN ($placeholders)"
                );
                $stmt->execute($ids);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if ($orderId > 0 && (int) ($row['order_id'] ?? 0) !== $orderId) {
                        continue;
                    }
                    $itemRows[(int) $row['id']] = $row;
                    $linkedProductId = max(0, (int) ($row['product_id'] ?? 0));
                    if ($linkedProductId > 0) {
                        $productIds[$linkedProductId] = true;
                    }
                }
            } catch (Throwable $e) {
                $itemRows = [];
            }
        }

        $receiptMap = $orderItemIds ? clmsReceiptItemImagePaths($pdo, array_keys($orderItemIds)) : [];
        $productMap = $productIds ? clmsProductImagePaths($pdo, array_keys($productIds)) : [];
        foreach ($targets as $cacheKey => $target) {
            $orderItemId = $target['order_item_id'];
            $contextProductId = $target['product_id'];
            $row = $itemRows[$orderItemId] ?? [];
            $linkedProductId = max(0, (int) ($row['product_id'] ?? 0));
            $sameLogicalItem = $contextProductId === 0
                || $linkedProductId === 0
                || $contextProductId === $linkedProductId;
            $itemPaths = $sameLogicalItem
                ? clmsNormalizeImagePathList($row['image_paths'] ?? [])
                : [];
            $receiptPaths = $sameLogicalItem ? ($receiptMap[$orderItemId] ?? []) : [];
            $productId = $contextProductId > 0 ? $contextProductId : $linkedProductId;
            $this->workbookContextImageCache[$cacheKey] = clmsMergeImagePathLists(
                $itemPaths,
                $productMap[$productId] ?? [],
                $receiptPaths
            );
        }
    }

    private function resolveCanonicalImagePathsForContext(array $context): array
    {
        $orderId = max(0, (int) ($context['order_id'] ?? 0));
        $orderItemId = max(0, (int) ($context['order_item_id'] ?? 0));
        $contextProductId = max(0, (int) ($context['product_id'] ?? 0));
        if ($orderItemId === 0 && $contextProductId === 0) {
            return [];
        }

        $cacheKey = $orderId . '|' . $orderItemId . '|' . $contextProductId;
        if (array_key_exists($cacheKey, $this->workbookContextImageCache)) {
            return $this->workbookContextImageCache[$cacheKey];
        }
        $pdo = $this->database();
        if (!$pdo) {
            return $this->workbookContextImageCache[$cacheKey] = [];
        }

        $itemImagePaths = [];
        $receiptImagePaths = [];
        $linkedProductId = 0;
        if ($orderItemId > 0) {
            try {
                $sql = 'SELECT product_id, image_paths FROM order_items WHERE id = ?';
                $params = [$orderItemId];
                if ($orderId > 0) {
                    $sql .= ' AND order_id = ?';
                    $params[] = $orderId;
                }
                $stmt = $pdo->prepare($sql . ' LIMIT 1');
                $stmt->execute($params);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $linkedProductId = max(0, (int) ($row['product_id'] ?? 0));

                // Shared-carton children retain the parent order_item_id while carrying
                // their own product_id. Never borrow the parent image for a child row.
                $sameLogicalItem = $contextProductId === 0
                    || $linkedProductId === 0
                    || $contextProductId === $linkedProductId;
                if ($sameLogicalItem) {
                    $itemImagePaths = clmsNormalizeImagePathList($row['image_paths'] ?? []);
                    $receiptMap = clmsReceiptItemImagePaths($pdo, [$orderItemId]);
                    $receiptImagePaths = $receiptMap[$orderItemId] ?? [];
                }
            } catch (Throwable $e) {
                $linkedProductId = 0;
            }
        }

        $productId = $contextProductId > 0 ? $contextProductId : $linkedProductId;
        $productImagePaths = [];
        if ($productId > 0) {
            $productMap = clmsProductImagePaths($pdo, [$productId]);
            $productImagePaths = $productMap[$productId] ?? [];
        }

        return $this->workbookContextImageCache[$cacheKey] = clmsMergeImagePathLists(
            $itemImagePaths,
            $productImagePaths,
            $receiptImagePaths
        );
    }

    public function diagnoseImageCandidates($imagePaths, array $context = []): array
    {
        $provided = $this->normalizeImagePaths($imagePaths);
        $canonical = $this->resolveCanonicalImagePathsForContext($context);
        $all = $this->normalizeImagePaths([$provided, $canonical]);
        $reasonCounts = [];
        $fallbackCounts = [];
        $sourceUsable = 0;
        $embeddable = 0;
        foreach ($all as $candidate) {
            $outcome = $this->resolveWorkbookImageSourceOutcome($candidate);
            $sourcePath = (string) ($outcome['path'] ?? '');
            if ($sourcePath !== '') {
                $sourceUsable++;
                $embed = $this->createWorkbookDrawingOutcome($sourcePath, 180, 140);
                if (($embed['drawing'] ?? null) !== null) {
                    $embeddable++;
                    $fallbackReason = (string) ($embed['fallback_reason'] ?? '');
                    if (($embed['mode'] ?? '') === 'memory' && $fallbackReason !== '') {
                        $fallbackCounts[$fallbackReason] = ($fallbackCounts[$fallbackReason] ?? 0) + 1;
                    }
                    unset($embed['drawing']);
                    continue;
                }
                $reason = (string) ($embed['reason'] ?? 'drawing_unavailable');
                $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
                continue;
            }
            $reason = (string) ($outcome['reason'] ?? 'unavailable');
            $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
        }

        ksort($reasonCounts);
        ksort($fallbackCounts);
        return [
            'pipeline_version' => self::IMAGE_PIPELINE_VERSION,
            'provided_candidate_count' => count($provided),
            'canonical_candidate_count' => count($canonical),
            'candidate_count' => count($all),
            'source_usable_candidate_count' => $sourceUsable,
            'usable_candidate_count' => $embeddable,
            'embeddable_candidate_count' => $embeddable,
            'status' => $embeddable > 0 ? 'ready' : 'unavailable',
            'reason_counts' => $reasonCounts,
            'runtime_fallback_counts' => $fallbackCounts,
        ];
    }

    private function resolveWorkbookImageSource(string $path): string
    {
        return (string) ($this->resolveWorkbookImageSourceOutcome($path)['path'] ?? '');
    }

    private function resolveWorkbookImageSourceOutcome(string $path): array
    {
        $path = trim(html_entity_decode($path, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($path === '') {
            return ['path' => '', 'reason' => 'empty_path'];
        }
        if (isset($this->workbookImageResolutionCache[$path])) {
            return $this->workbookImageResolutionCache[$path];
        }
        if (str_starts_with(strtolower($path), 'data:')) {
            return $this->workbookImageResolutionCache[$path] = ['path' => '', 'reason' => 'data_uri_not_allowed'];
        }

        $isRemote = preg_match('#^https?://#i', $path) === 1;
        $localCandidate = $path;
        if ($isRemote) {
            $query = (string) parse_url($path, PHP_URL_QUERY);
            if ($query !== '') {
                parse_str($query, $queryValues);
                foreach (['path', 'file_path', 'file', 'image'] as $queryKey) {
                    if (isset($queryValues[$queryKey]) && is_string($queryValues[$queryKey]) && trim($queryValues[$queryKey]) !== '') {
                        $localCandidate = rawurldecode(trim($queryValues[$queryKey]));
                        break;
                    }
                }
            }
            if ($localCandidate === $path) {
                $localCandidate = rawurldecode((string) parse_url($path, PHP_URL_PATH));
            }
        }

        $backendRoot = realpath($this->backendDir) ?: $this->backendDir;
        $backendPrefix = rtrim(str_replace('\\', '/', $backendRoot), '/') . '/';
        $absoluteCandidate = realpath($localCandidate);
        if ($absoluteCandidate !== false && is_file($absoluteCandidate) && is_readable($absoluteCandidate)) {
            $absoluteNormalized = str_replace('\\', '/', $absoluteCandidate);
            if (str_starts_with(strtolower($absoluteNormalized . (is_dir($absoluteCandidate) ? '/' : '')), strtolower($backendPrefix))) {
                return $this->workbookImageResolutionCache[$path] = $this->validateWorkbookImageFile($absoluteCandidate);
            }
        }

        $localRelative = ltrim(str_replace('\\', '/', rawurldecode($localCandidate)), '/');
        $localRelative = preg_replace('#/+#', '/', $localRelative) ?? $localRelative;
        $projectDirectory = strtolower(basename(dirname($this->backendDir)));
        foreach ([$projectDirectory . '/backend/', 'cargochina/backend/', 'backend/', $projectDirectory . '/'] as $prefix) {
            if (str_starts_with(strtolower($localRelative), $prefix)) {
                $localRelative = substr($localRelative, strlen($prefix));
                break;
            }
        }
        if (str_contains($localRelative, '../') || str_contains($localRelative, "\0")) {
            return $this->workbookImageResolutionCache[$path] = ['path' => '', 'reason' => 'unsafe_path'];
        }
        $localPath = realpath($this->backendDir . '/' . $localRelative);
        if ($localPath === false) {
            $localPath = $this->resolveBackendPathCaseInsensitive($localRelative);
        }
        if ($localPath !== false && is_file($localPath) && is_readable($localPath)) {
            $localNormalized = str_replace('\\', '/', $localPath);
            if (str_starts_with(strtolower($localNormalized), strtolower($backendPrefix))) {
                return $this->workbookImageResolutionCache[$path] = $this->validateWorkbookImageFile($localPath);
            }
        }
        if (!$isRemote) {
            return $this->workbookImageResolutionCache[$path] = ['path' => '', 'reason' => 'local_file_unavailable'];
        }
        $url = parse_url($path);
        if (!is_array($url)) {
            return $this->workbookImageResolutionCache[$path] = ['path' => '', 'reason' => 'invalid_remote_url'];
        }
        $host = strtolower((string) ($url['host'] ?? ''));
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return $this->workbookImageResolutionCache[$path] = ['path' => '', 'reason' => 'remote_host_blocked'];
        }
        $ip = gethostbyname($host);
        if ($ip === $host || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return $this->workbookImageResolutionCache[$path] = ['path' => '', 'reason' => 'remote_address_blocked'];
        }
        $cacheDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'clms_excel_remote_images';
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0770, true) && !is_dir($cacheDir)) {
            return $this->workbookImageResolutionCache[$path] = ['path' => '', 'reason' => 'remote_cache_unavailable'];
        }
        $target = $cacheDir . DIRECTORY_SEPARATOR . hash('sha256', $path) . '.img';
        if (is_file($target) && filesize($target) > 0) {
            $cached = $this->validateWorkbookImageFile($target);
            if (($cached['path'] ?? '') !== '') {
                return $this->workbookImageResolutionCache[$path] = $cached;
            }
            @unlink($target);
        }

        $download = $this->downloadRemoteWorkbookImage($path, $host, $ip, (int) ($url['port'] ?? 0));
        if (($download['data'] ?? '') === '') {
            return $this->workbookImageResolutionCache[$path] = [
                'path' => '',
                'reason' => (string) ($download['reason'] ?? 'remote_fetch_failed'),
            ];
        }
        if (@file_put_contents($target, $download['data'], LOCK_EX) === false) {
            return $this->workbookImageResolutionCache[$path] = ['path' => '', 'reason' => 'remote_cache_write_failed'];
        }
        $outcome = $this->validateWorkbookImageFile($target);
        if (($outcome['path'] ?? '') === '') {
            @unlink($target);
        }
        return $this->workbookImageResolutionCache[$path] = $outcome;
    }

    private function resolveBackendPathCaseInsensitive(string $relativePath)
    {
        $segments = array_values(array_filter(explode('/', trim($relativePath, '/')), static fn(string $part): bool => $part !== ''));
        if (!$segments || in_array('..', $segments, true) || in_array('.', $segments, true)) {
            return false;
        }

        $current = realpath($this->backendDir);
        if ($current === false) {
            return false;
        }
        foreach ($segments as $segment) {
            $exact = $current . DIRECTORY_SEPARATOR . $segment;
            if (file_exists($exact)) {
                $current = $exact;
                continue;
            }
            $entries = @scandir($current);
            if (!is_array($entries)) {
                return false;
            }
            $match = null;
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..' && strcasecmp($entry, $segment) === 0) {
                    $match = $entry;
                    break;
                }
            }
            if ($match === null) {
                return false;
            }
            $current .= DIRECTORY_SEPARATOR . $match;
        }

        $resolved = realpath($current);
        if ($resolved === false) {
            return false;
        }
        $backendRoot = realpath($this->backendDir);
        $resolvedNormalized = str_replace('\\', '/', $resolved);
        $backendPrefix = rtrim(str_replace('\\', '/', (string) $backendRoot), '/') . '/';
        return $backendRoot !== false && str_starts_with(strtolower($resolvedNormalized), strtolower($backendPrefix))
            ? $resolved
            : false;
    }

    private function validateWorkbookImageFile(string $path): array
    {
        // API exports must enforce the same private-file scope as original/thumbnail delivery.
        if (session_status() === PHP_SESSION_ACTIVE && getAuthUserId()) {
            $root=str_replace('\\','/',realpath($this->backendDir.'/uploads') ?: $this->backendDir.'/uploads').'/';
            $absolute=str_replace('\\','/',realpath($path) ?: $path);
            if (str_starts_with(strtolower($absolute),strtolower($root))) {
                require_once __DIR__.'/UploadAccessService.php';
                UploadAccessService::authorize($this->pdo ?? getDb(),'uploads/'.substr($absolute,strlen($root)));
            }
        }

        if (!is_file($path)) {
            return ['path' => '', 'reason' => 'local_file_missing'];
        }
        if (!is_readable($path)) {
            return ['path' => '', 'reason' => 'local_file_unreadable'];
        }
        $size = @filesize($path);
        if ($size === false || $size <= 0) {
            return ['path' => '', 'reason' => 'empty_file'];
        }
        if ($size > 25 * 1024 * 1024) {
            return ['path' => '', 'reason' => 'image_too_large'];
        }
        $info = @getimagesize($path);
        if (!is_array($info)) {
            return ['path' => '', 'reason' => 'invalid_image_content'];
        }
        if ((float)($info[0]??0)*(float)($info[1]??0)>25000000) return ['path'=>'','reason'=>'image_dimensions_too_large'];
        $mime = strtolower((string) ($info['mime'] ?? ''));
        $directMimes = ['image/jpeg', 'image/png', 'image/gif'];
        $convertMimes = ['image/webp', 'image/bmp', 'image/x-ms-bmp'];
        if (!in_array($mime, $directMimes, true) && !in_array($mime, $convertMimes, true)) {
            return ['path' => '', 'reason' => 'unsupported_image_type'];
        }
        if (in_array($mime, $convertMimes, true)
            && (!extension_loaded('gd') || !function_exists('imagecreatefromstring'))) {
            return ['path' => '', 'reason' => 'image_conversion_unavailable'];
        }
        return ['path' => $path, 'reason' => 'ok', 'mime' => $mime];
    }

    private function downloadRemoteWorkbookImage(string $url, string $host, string $ip, int $port = 0): array
    {
        $limit = 8 * 1024 * 1024;
        if (extension_loaded('curl') && function_exists('curl_init')) {
            $data = '';
            $tooLarge = false;
            $handle = curl_init($url);
            if ($handle === false) {
                return ['data' => '', 'reason' => 'remote_fetch_failed'];
            }
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            $resolvedPort = $port > 0 ? $port : ($scheme === 'https' ? 443 : 80);
            curl_setopt_array($handle, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROXY => '',
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 6,
                CURLOPT_USERAGENT => 'CLMS Excel Export/1.0',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_RESOLVE => [$host . ':' . $resolvedPort . ':' . $ip],
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$data, &$tooLarge, $limit): int {
                    if (strlen($data) + strlen($chunk) > $limit) {
                        $tooLarge = true;
                        return 0;
                    }
                    $data .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $success = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_close($handle);
            if ($tooLarge) {
                return ['data' => '', 'reason' => 'remote_image_too_large'];
            }
            if ($success === false || $status < 200 || $status >= 300 || $data === '') {
                return ['data' => '', 'reason' => 'remote_fetch_failed'];
            }
        } else {
            return ['data' => '', 'reason' => 'secure_remote_transport_unavailable'];
        }

        $imageInfo = @getimagesizefromstring($data);
        if ($imageInfo === false || (float)$imageInfo[0]*(float)$imageInfo[1]>25000000) {
            return ['data' => '', 'reason' => 'remote_invalid_image'];
        }
        return ['data' => $data, 'reason' => 'ok'];
    }

    private function logWorkbookImageDiagnostic(
        string $event,
        array $context,
        string $reason,
        int $candidateCount,
        array $reasonCounts = []
    ): void {
        $safe = [
            'scope' => substr(preg_replace('/[^a-z0-9_-]+/i', '', (string) ($context['scope'] ?? 'excel')) ?: 'excel', 0, 40),
            'order_id' => max(0, (int) ($context['order_id'] ?? 0)),
            'order_item_id' => max(0, (int) ($context['order_item_id'] ?? 0)),
            'product_id' => max(0, (int) ($context['product_id'] ?? 0)),
            'item_no' => substr(trim((string) ($context['item_no'] ?? '')), 0, 80),
            'reason' => substr(preg_replace('/[^a-z0-9_-]+/i', '', $reason) ?: 'unavailable', 0, 60),
            'candidate_count' => max(0, $candidateCount),
        ];
        if ($reasonCounts) {
            $safe['reason_counts'] = [];
            foreach ($reasonCounts as $key => $count) {
                $safeKey = substr(preg_replace('/[^a-z0-9_-]+/i', '', (string) $key) ?: 'unavailable', 0, 60);
                $safe['reason_counts'][$safeKey] = max(0, (int) $count);
            }
            ksort($safe['reason_counts']);
        }
        $encoded = json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $key = $event . '|' . hash('sha256', is_string($encoded) ? $encoded : serialize($safe));
        if (isset($this->workbookImageDiagnosticKeys[$key])) {
            return;
        }
        $this->workbookImageDiagnosticKeys[$key] = true;
        if (function_exists('logClms')) {
            logClms($event, $safe);
        }
    }

    private function normalizeImagePaths($imagePaths): array
    {
        $paths = [];
        $collect = function ($value) use (&$collect, &$paths): void {
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') return;
                if (($value[0] ?? '') === '[' || ($value[0] ?? '') === '{') {
                    $decoded = json_decode($value, true);
                    if (is_array($decoded)) {
                        $collect($decoded);
                        return;
                    }
                }
                $paths[$value] = true;
                return;
            }
            if (!is_array($value)) return;

            foreach (['file_path', 'path', 'url', 'image_path', 'photo_path'] as $pathKey) {
                if (isset($value[$pathKey]) && is_string($value[$pathKey])) {
                    $collect($value[$pathKey]);
                }
            }
            foreach ($value as $key => $nested) {
                if (in_array((string) $key, ['file_path', 'path', 'url', 'image_path', 'photo_path'], true)) continue;
                if (is_array($nested) || is_string($nested)) {
                    $collect($nested);
                }
            }
        };
        $collect($imagePaths);
        return array_keys($paths);
    }

    private function collectItemImagePaths(array $items): array
    {
        $paths = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            foreach ($this->normalizeImagePaths($item['image_paths'] ?? []) as $path) {
                $paths[$path] = true;
            }
        }
        return array_keys($paths);
    }

    private function itemText(array $item, string $key): string
    {
        return trim((string) ($item[$key] ?? ''));
    }

    private function descriptionText(array $item): string
    {
        $description = trim((string) ($item['description_en'] ?? $item['description_cn'] ?? ''));
        $notes = $this->itemText($item, 'notes');
        if ($notes === '' || str_contains($description, $notes)) {
            return $description;
        }
        return trim($description . ($description !== '' ? ' | ' : '') . $notes);
    }

    private function copyNormalGoodsText(array $item): string
    {
        if (!empty($item['item_type_code'])) {
            return $this->itemTypeText((string) $item['item_type_code']);
        }
        $value = $this->itemText($item, 'copy_normal_goods');
        return match (strtolower($value)) {
            'copy' => $this->tr('Copy Goods'),
            'dangerous' => $this->tr('Dangerous Goods'),
            'normal' => $this->tr('Normal Goods'),
            default => $value,
        };
    }

    private function itemTypeText(string $code): string
    {
        return match (strtolower(trim($code))) {
            'normal' => $this->tr('Normal Goods'),
            'replica' => $this->tr('Replica Goods'),
            'cosmetics' => $this->tr('Cosmetics'),
            'branded' => $this->tr('Branded Goods'),
            'food' => $this->tr('Food'),
            'dangerous' => $this->tr('Dangerous Goods'),
            'other' => $this->tr('Other'),
            default => $this->tr('Unclassified'),
        };
    }

    private function dimensionValue(array $item, string $preferredKey, string $legacyKey)
    {
        foreach ([$preferredKey, $legacyKey] as $key) {
            if (isset($item[$key]) && $item[$key] !== '' && $item[$key] !== null) {
                return is_numeric($item[$key]) ? round((float) $item[$key], 4) : $item[$key];
            }
        }

        return '';
    }

    private function resolveItemSize(array $item): string
    {
        $explicit = $this->itemText($item, 'size');
        if ($explicit !== '') {
            return $explicit;
        }

        $length = $item['item_length'] ?? $item['length'] ?? null;
        $width = $item['item_width'] ?? $item['width'] ?? null;
        $height = $item['item_height'] ?? $item['height'] ?? null;
        $parts = [];
        foreach ([$length, $width, $height] as $value) {
            if ($value !== null && $value !== '' && is_numeric($value) && (float) $value > 0) {
                $parts[] = rtrim(rtrim((string) round((float) $value, 4), '0'), '.');
            }
        }

        return count($parts) === 3 ? implode(' x ', $parts) : '';
    }

    private function resolveQuantity(array $item): float
    {
        $quantity = (float) ($item['quantity'] ?? 0);
        $cartons = (float) ($item['cartons'] ?? 0);
        $qtyPerCarton = (float) ($item['qty_per_carton'] ?? 0);

        if ($quantity <= 0 && $cartons > 0 && $qtyPerCarton > 0) {
            $quantity = $cartons * $qtyPerCarton;
        }

        return round($quantity, 4);
    }

    private function resolveUnitPrice(array $item): ?float
    {
        foreach (['sell_price', 'unit_price'] as $key) {
            if (isset($item[$key]) && $item[$key] !== '' && $item[$key] !== null) {
                return round((float) $item[$key], 4);
            }
        }

        return null;
    }

    private function resolveFactoryPriceDisplay(array $item): ?float
    {
        foreach (['effective_buy_price', 'buy_price', 'product_buy_price'] as $key) {
            if (isset($item[$key]) && $item[$key] !== '' && $item[$key] !== null) {
                return round((float) $item[$key], 4);
            }
        }

        return null;
    }

    private function resolveFactoryPriceForTotals(array $item, ?float $unitPrice): ?float
    {
        $factory = $this->resolveFactoryPriceDisplay($item);
        if ($factory !== null) {
            return $factory;
        }

        return $unitPrice;
    }

    private function extractReceiptFees(array $order): array
    {
        $fees = $order['receipt_fees'] ?? $order['receipt']['fees'] ?? [];
        if (!is_array($fees)) {
            return [];
        }

        return array_values(array_filter($fees, static function ($fee): bool {
            if (!is_array($fee)) {
                return false;
            }
            return (float) ($fee['amount'] ?? 0) > 0;
        }));
    }

    private function calculateStandardSellTotals(array $items, array $order): array
    {
        $totals = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $quantity = $this->resolveQuantity($item);
            $unitPrice = $this->resolveUnitPrice($item);
            if ($quantity <= 0 || $unitPrice === null) {
                continue;
            }
            $currency = $this->resolveCurrency($order, $item);
            $this->addCurrencyTotal($totals, $currency, round($quantity * $unitPrice, 4));
        }

        return $totals;
    }

    private function calculateReceiptFeeTotals(array $fees, string $defaultCurrency): array
    {
        $totals = [];
        foreach ($fees as $fee) {
            if (!is_array($fee)) {
                continue;
            }
            $amount = round((float) ($fee['amount'] ?? 0), 4);
            if ($amount <= 0) {
                continue;
            }
            $currency = $this->normalizeCurrency((string) ($fee['currency'] ?? $defaultCurrency));
            $this->addCurrencyTotal($totals, $currency, $amount);
        }

        return $totals;
    }

    private function resolveCurrency(array $order, array $item): string
    {
        foreach (['currency', 'item_currency'] as $key) {
            $candidate = trim((string) ($item[$key] ?? ''));
            if ($candidate !== '') {
                return $this->normalizeCurrency($candidate);
            }
        }

        $candidate = trim((string) ($order['currency'] ?? ''));
        return $candidate !== '' ? $this->normalizeCurrency($candidate) : 'USD';
    }

    private function normalizeCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        return $currency !== '' ? $currency : 'USD';
    }

    private function addCurrencyTotal(array &$bucket, string $currency, float $amount): void
    {
        $currency = $this->normalizeCurrency($currency);
        if (!isset($bucket[$currency])) {
            $bucket[$currency] = 0.0;
        }
        $bucket[$currency] += $amount;
    }

    private function mergeCurrencyTotals(array $base, array $additional): array
    {
        $merged = $base;
        foreach ($additional as $currency => $amount) {
            if (!isset($merged[$currency])) {
                $merged[$currency] = 0.0;
            }
            $merged[$currency] += (float) $amount;
        }
        return $merged;
    }

    private function formatCurrencyBreakdown(array $totals): string
    {
        $parts = [];
        foreach ($totals as $currency => $amount) {
            $amount = round((float) $amount, 4);
            if (abs($amount) < 0.0001) {
                continue;
            }
            $parts[] = $currency . ' ' . format_display_amount($amount);
        }

        return $parts ? implode(' | ', $parts) : '-';
    }

    private function groupItemsBySupplier(array $items, array $order = []): array
    {
        $groups = [];

        foreach ($items as $item) {
            $supplierName = trim((string) ($item['supplier_name'] ?? $order['supplier_name'] ?? ''));
            $supplierId = trim((string) ($item['supplier_id'] ?? $order['supplier_id'] ?? ''));
            $key = $supplierId !== '' ? 'id:' . $supplierId : 'name:' . md5(strtolower($supplierName));

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'supplier_name' => $supplierName,
                    'supplier_phone' => trim((string) ($item['supplier_phone'] ?? $order['supplier_phone'] ?? '')),
                    'supplier_info' => $this->extractSupplierAccountReference($item, $order),
                    'items' => [],
                ];
            } else {
                if ($groups[$key]['supplier_phone'] === '') {
                    $groups[$key]['supplier_phone'] = trim((string) ($item['supplier_phone'] ?? $order['supplier_phone'] ?? ''));
                }
                if ($groups[$key]['supplier_info'] === '') {
                    $groups[$key]['supplier_info'] = $this->extractSupplierAccountReference($item, $order);
                }
            }

            $groups[$key]['items'][] = $item;
        }

        return array_values($groups);
    }

    private function extractSupplierAccountReference(array $item, array $order): string
    {
        foreach ([$item['supplier_payment_links'] ?? null, $order['supplier_payment_links'] ?? null] as $raw) {
            $value = $this->extractFirstPaymentReference($raw);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function extractFirstPaymentReference($raw): string
    {
        if (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '') {
                return '';
            }
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $this->extractFirstPaymentReference($decoded);
            }
            return $trimmed;
        }

        if (!is_array($raw)) {
            return '';
        }

        $candidateKeys = ['value', 'account_number', 'accountNumber', 'number', 'iban', 'account', 'link', 'url'];
        foreach ($candidateKeys as $key) {
            if (isset($raw[$key])) {
                $value = trim((string) $raw[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        foreach ($raw as $value) {
            $resolved = $this->extractFirstPaymentReference($value);
            if ($resolved !== '') {
                return $resolved;
            }
        }

        return '';
    }

    private function styleRange($sheet, string $range, array $style): void
    {
        $sheet->getStyle($range)->applyFromArray($style);
    }

    public static function formatCustomerDisplay(array $record, array $items = []): string
    {
        $name = trim((string) ($record['customer_name'] ?? $record['name'] ?? ''));
        $codes = [];

        foreach ([
            $record['default_shipping_code'] ?? null,
            $record['customer_shipping_code'] ?? null,
            $record['shipping_code'] ?? null,
        ] as $candidate) {
            $candidate = trim((string) ($candidate ?? ''));
            if ($candidate !== '') {
                $codes[$candidate] = true;
            }
        }

        if (!$codes) {
            foreach ($items as $item) {
                $candidate = trim((string) ($item['shipping_code'] ?? ''));
                if ($candidate !== '') {
                    $codes[$candidate] = true;
                }
            }
        }

        if (!$codes) {
            return $name;
        }

        $suffix = implode(', ', array_keys($codes));
        return $name !== '' ? ($name . ' (' . $suffix . ')') : $suffix;
    }

    private function outputXlsx(Spreadsheet $spreadsheet, string $filename): void
    {
        $this->prepareWorkbook($spreadsheet);
        $writer = new Xlsx($spreadsheet);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $filename) . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-CLMS-Excel-Image-Pipeline: ' . self::IMAGE_PIPELINE_VERSION);
        $writer->save('php://output');
        exit;
    }

    private function prepareWorkbook(Spreadsheet $spreadsheet): void
    {
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $lastColumn = $sheet->getHighestColumn();
            $lastRow = max(1, $sheet->getHighestRow());
            $sheet->setShowGridlines(false);
            $sheet->getPageSetup()
                ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
                ->setPaperSize(PageSetup::PAPERSIZE_A4)
                ->setFitToPage(true)
                ->setFitToWidth(1)
                ->setFitToHeight(0)
                ->setPrintArea("A1:{$lastColumn}{$lastRow}")
                ->setRowsToRepeatAtTopByStartAndEnd(1, min(9, $lastRow));
            $sheet->getPageMargins()
                ->setTop(0.35)
                ->setRight(0.25)
                ->setBottom(0.35)
                ->setLeft(0.25)
                ->setHeader(0.15)
                ->setFooter(0.15);
            $sheet->getPageSetup()->setHorizontalCentered(true);
        }
    }

    /** These workbooks contain calculated values, never executable formulas. */
    private function setSafeCell(Worksheet $sheet,string $coordinate,$value): void
    {
        if(is_string($value))$sheet->setCellValueExplicit($coordinate,$value,DataType::TYPE_STRING);
        else $sheet->setCellValue($coordinate,$value);
    }

    private function createWorkbookDrawingOutcome(string $sourcePath, int $targetWidth, int $targetHeight): array
    {
        $preparedPath = $sourcePath;
        $fallbackReason = '';
        try {
            $preparedPath = $this->workbookImagePath($sourcePath, $targetWidth, $targetHeight);
        } catch (Throwable $e) {
            $fallbackReason = 'thumbnail_preparation_failed';
        }

        if (!is_file($preparedPath) || !is_readable($preparedPath)) {
            return ['drawing' => null, 'reason' => 'prepared_image_unavailable'];
        }

        $preparedInfo = @getimagesize($preparedPath);
        $preparedMime = is_array($preparedInfo) ? strtolower((string) ($preparedInfo['mime'] ?? '')) : '';
        $fileDrawingMimes = ['image/jpeg', 'image/png', 'image/gif'];
        if (in_array($preparedMime, $fileDrawingMimes, true) && function_exists('mime_content_type')) {
            try {
                $drawing = new Drawing();
                $drawing->setPath($preparedPath);
                return [
                    'drawing' => $drawing,
                    'mode' => 'file',
                    'reason' => 'ok',
                    'fallback_reason' => $fallbackReason,
                ];
            } catch (Throwable $e) {
                $fallbackReason = 'file_drawing_rejected';
            }
        } elseif (!function_exists('mime_content_type')) {
            $fallbackReason = 'fileinfo_unavailable';
        } elseif ($preparedMime !== '') {
            $fallbackReason = 'file_drawing_conversion_required';
        }

        if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
            return [
                'drawing' => null,
                'reason' => $fallbackReason !== '' ? $fallbackReason : 'memory_drawing_unavailable',
            ];
        }

        $sourceData = @file_get_contents($preparedPath);
        if (!is_string($sourceData) || $sourceData === '') {
            return ['drawing' => null, 'reason' => 'memory_image_read_failed'];
        }
        $imageResource = @imagecreatefromstring($sourceData);
        unset($sourceData);
        if ($imageResource === false) {
            return ['drawing' => null, 'reason' => 'memory_image_decode_failed'];
        }

        if (function_exists('imagepng')) {
            $renderingFunction = MemoryDrawing::RENDERING_PNG;
            $mimeType = MemoryDrawing::MIMETYPE_PNG;
        } elseif (function_exists('imagejpeg')) {
            $renderingFunction = MemoryDrawing::RENDERING_JPEG;
            $mimeType = MemoryDrawing::MIMETYPE_JPEG;
        } else {
            @imagedestroy($imageResource);
            return ['drawing' => null, 'reason' => 'memory_image_encoder_unavailable'];
        }

        $drawing = null;
        try {
            $drawing = new MemoryDrawing();
            $drawing->setImageResource($imageResource);
            $drawing->setRenderingFunction($renderingFunction);
            $drawing->setMimeType($mimeType);

            return [
                'drawing' => $drawing,
                'mode' => 'memory',
                'reason' => 'ok',
                'fallback_reason' => $fallbackReason !== '' ? $fallbackReason : 'memory_drawing_used',
            ];
        } catch (Throwable $e) {
            if ($drawing instanceof MemoryDrawing) {
                unset($drawing);
            } else {
                @imagedestroy($imageResource);
            }
            return ['drawing' => null, 'reason' => 'memory_drawing_creation_failed'];
        }
    }

    private function workbookImagePath(string $sourcePath, int $targetWidth, int $targetHeight): string
    {
        $targetWidth = max(64, min(640, $targetWidth));
        $targetHeight = max(64, min(480, $targetHeight));
        $requestKey = $sourcePath . '|' . $targetWidth . 'x' . $targetHeight;
        if (isset($this->workbookImageCache[$requestKey])) {
            return $this->workbookImageCache[$requestKey];
        }

        if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
            return $this->workbookImageCache[$requestKey] = $sourcePath;
        }

        $contentHash = @hash_file('sha256', $sourcePath);
        if (!is_string($contentHash) || $contentHash === '') {
            return $this->workbookImageCache[$requestKey] = $sourcePath;
        }

        $cacheDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'clms_excel_thumbnails';
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0770, true) && !is_dir($cacheDir)) {
            return $this->workbookImageCache[$requestKey] = $sourcePath;
        }

        $thumbnailPath = $cacheDir . DIRECTORY_SEPARATOR
            . $contentHash . '_' . $targetWidth . 'x' . $targetHeight . '.jpg';
        if (is_file($thumbnailPath) && filesize($thumbnailPath) > 0) {
            return $this->workbookImageCache[$requestKey] = $thumbnailPath;
        }

        $sourceData = @file_get_contents($sourcePath);
        if (!is_string($sourceData) || $sourceData === '') {
            return $this->workbookImageCache[$requestKey] = $sourcePath;
        }
        $sourceImage = @imagecreatefromstring($sourceData);
        unset($sourceData);
        if ($sourceImage === false) {
            return $this->workbookImageCache[$requestKey] = $sourcePath;
        }

        $sourceWidth = imagesx($sourceImage);
        $sourceHeight = imagesy($sourceImage);
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            imagedestroy($sourceImage);
            return $this->workbookImageCache[$requestKey] = $sourcePath;
        }

        $thumbnail = imagecreatetruecolor($targetWidth, $targetHeight);
        $white = imagecolorallocate($thumbnail, 255, 255, 255);
        imagefill($thumbnail, 0, 0, $white);
        $scale = min($targetWidth / $sourceWidth, $targetHeight / $sourceHeight);
        $copyWidth = max(1, (int) floor($sourceWidth * $scale));
        $copyHeight = max(1, (int) floor($sourceHeight * $scale));
        $copyX = (int) floor(($targetWidth - $copyWidth) / 2);
        $copyY = (int) floor(($targetHeight - $copyHeight) / 2);
        imagecopyresampled(
            $thumbnail,
            $sourceImage,
            $copyX,
            $copyY,
            0,
            0,
            $copyWidth,
            $copyHeight,
            $sourceWidth,
            $sourceHeight
        );
        imagedestroy($sourceImage);

        $temporaryPath = $thumbnailPath . '.' . getmypid() . '.tmp';
        $written = @imagejpeg($thumbnail, $temporaryPath, 78);
        imagedestroy($thumbnail);
        if (!$written || !is_file($temporaryPath)) {
            @unlink($temporaryPath);
            return $this->workbookImageCache[$requestKey] = $sourcePath;
        }

        if (!is_file($thumbnailPath) && !@rename($temporaryPath, $thumbnailPath)) {
            @unlink($temporaryPath);
            return $this->workbookImageCache[$requestKey] = $sourcePath;
        }
        @unlink($temporaryPath);

        return $this->workbookImageCache[$requestKey] = $thumbnailPath;
    }

    private function exportSimpleTable(string $title, array $headers, array $rows, string $filename): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $localizedTitle = $this->tr($title);
        $englishHeaders = array_map(fn($header) => $this->trForLocale((string) $header, 'en'), $headers);
        $chineseHeaders = array_map(fn($header) => $this->trForLocale((string) $header, 'zh-CN'), $headers);
        $sheet->setTitle(substr($localizedTitle, 0, 31));
        $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($englishHeaders)));
        $headerRow = $this->writeCompanyHeader(
            $sheet,
            1,
            $lastColumn,
            $localizedTitle,
            [
                [$this->tr('Generated') . ':', date('Y-m-d H:i:s')],
                [$this->tr('Records') . ':', (string) count($rows)],
                ['', ''],
                ['', ''],
            ],
            $this->tr('Filtered export - complete result set')
        );

        $chineseHeaderRow = $headerRow + 1;
        foreach ($englishHeaders as $index => $header) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $this->setSafeCell($sheet,$column . $headerRow, $header);
            $this->setSafeCell($sheet,$column . $chineseHeaderRow, $chineseHeaders[$index] ?? $header);
            $sheet->getStyle($column . $headerRow . ':' . $column . $chineseHeaderRow)->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 11, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::MASTER_TABLE_BLUE]],
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D8E2F0']],
                ],
            ]);
        }
        $sheet->getRowDimension($headerRow)->setRowHeight(34);
        $sheet->getRowDimension($chineseHeaderRow)->setRowHeight(34);

        $rowNumber = $chineseHeaderRow + 1;
        foreach ($rows as $row) {
            foreach (array_values($row) as $index => $value) {
                $column = Coordinate::stringFromColumnIndex($index + 1);
                $cell = $column . $rowNumber;
                $header = (string) ($headers[$index] ?? '');
                if (strcasecmp(trim($header), 'Photo') === 0) {
                    $this->writePhotoCell($sheet, $cell, $value, [
                        'scope' => 'table_export',
                        'item_no' => 'row-' . $rowNumber,
                    ]);
                    $sheet->getColumnDimension($column)->setWidth(self::PHOTO_COLUMN_WIDTH);
                    $sheet->getRowDimension($rowNumber)->setRowHeight(self::PHOTO_ROW_HEIGHT_PT);
                } elseif (is_string($value)
                    && preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $value)
                    && preg_match('/date|ready|received|created|updated|generated/i', $header)) {
                    $excelDate = $this->excelDateValue($value);
                    $this->setSafeCell($sheet,$cell, $excelDate ?? $value);
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(str_contains($value, ':') ? 'yyyy-mm-dd hh:mm:ss' : 'yyyy-mm-dd');
                } elseif (in_array(strtolower(trim($header)), ['item number', 'i.i.n'], true)) {
                    $sheet->setCellValueExplicit($cell, (string) ($value ?? ''), DataType::TYPE_STRING);
                } elseif (is_string($value)) {
                    $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
                } else {
                    $this->setSafeCell($sheet,$cell, $value);
                }
                $sheet->getStyle($cell)->applyFromArray([
                    'font' => ['name' => 'Arial', 'size' => 10],
                    'alignment' => [
                        'horizontal' => is_numeric($value) ? Alignment::HORIZONTAL_RIGHT : Alignment::HORIZONTAL_LEFT,
                        'vertical' => Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                    'borders' => [
                        'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E6ECF4']],
                    ],
                ]);
            }
            $rowNumber++;
        }

        if ($rowNumber === $chineseHeaderRow + 1) {
            $this->setSafeCell($sheet,'A' . $rowNumber, $this->tr('No rows available.'));
            $sheet->mergeCells("A{$rowNumber}:{$lastColumn}{$rowNumber}");
            $sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")->getFont()->setItalic(true);
            $rowNumber++;
        }

        foreach (range(1, count($englishHeaders)) as $index) {
            $column = Coordinate::stringFromColumnIndex($index);
            if (strcasecmp(trim((string) ($headers[$index - 1] ?? '')), 'Photo') !== 0) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
        }
        $sheet->freezePane('A' . ($chineseHeaderRow + 1));
        $sheet->setAutoFilter("A{$chineseHeaderRow}:{$lastColumn}{$chineseHeaderRow}");

        $this->outputXlsx($spreadsheet, $filename);
    }
}
