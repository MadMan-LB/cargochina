<?php

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/api/helpers.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Drawing as SharedDrawing;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class OrderExcelService
{
    private string $backendDir;
    private array $workbookImageCache = [];

    private const STANDARD_LAST_COL = 'AB';
    private const CONTAINER_LAST_COL = 'V';
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

    public function __construct()
    {
        $this->backendDir = dirname(__DIR__);
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
                [$this->tr('Container') . ':', (string) ($context['container_code'] ?? $context['code'] ?? '')],
                [$this->tr('Orders') . ':', (string) count($ordersWithItems)],
                [$this->tr('Generated') . ':', date('Y-m-d H:i:s')],
                [$this->tr('Currency') . ':', (string) ($context['currency'] ?? '')],
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

        $this->outputXlsx($spreadsheet, $filename);
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
            if ($supplierNames) {
                $names = array_keys($supplierNames);
                $supplierDisplay = count($names) === 1 ? $names[0] : $this->tr('Multiple ({names})', ['names' => implode(', ', $names)]);
            }

            return [
                (int) ($row['id'] ?? 0),
                $items[0]['image_paths'] ?? [],
                (string) ($row['order_type'] ?? 'standard'),
                self::formatCustomerDisplay($row, $items),
                $supplierDisplay,
                (string) ($row['expected_ready_date'] ?? ''),
                $this->statusText((string) ($row['status'] ?? '')),
                $this->tr((string) ($row['deposit_status'] ?? 'No Deposit')),
                round((float) ($row['deposit_paid_amount'] ?? 0), 2),
                round((float) ($row['remaining_balance'] ?? 0), 2),
                $this->formatOperationalCostSummary($row),
                round($cbm, 4),
                round($weight, 2),
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
                $items[0]['image_paths'] ?? [],
                self::formatCustomerDisplay($row, $items),
                (string) ($row['supplier_name'] ?? ''),
                (string) ($row['supplier_phone'] ?? ''),
                (string) ($row['expected_ready_date'] ?? ''),
                $this->statusText((string) ($row['status'] ?? '')),
                implode('; ', array_keys($shippingCodes)),
                $totalCartons,
                round((float) ($row['declared_cbm'] ?? 0), 6),
                round((float) ($row['declared_weight'] ?? 0), 2),
                implode('; ', array_filter($itemsSummary)),
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
            'Item No',
            'Item Type',
            'Quantity',
            'Actual Quantity',
            'Actual Cartons',
            'Declared CBM',
            'Actual CBM',
            'Actual Weight',
            'Actual Height',
            'Actual Width',
            'Actual Length',
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
                $row['quantity'] ?? null,
                $row['item_actual_quantity'] ?? null,
                $row['item_actual_cartons'] ?? $row['order_actual_cartons'] ?? null,
                $row['declared_cbm'] ?? null,
                $row['item_actual_cbm'] ?? $row['order_actual_cbm'] ?? null,
                $row['item_actual_weight'] ?? $row['order_actual_weight'] ?? null,
                $row['item_actual_height'] ?? $row['height'] ?? $row['item_height'] ?? null,
                $row['item_actual_width'] ?? $row['width'] ?? $row['item_width'] ?? null,
                $row['item_actual_length'] ?? $row['length'] ?? $row['item_length'] ?? null,
            ];
        }, $rows);

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
        ];

        foreach ($widths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
    }

    private function writeCompanyHeader($sheet, int $startRow, string $lastColumn, string $title = 'Goods Details', array $metadata = [], string $note = ''): int
    {
        $titleRow = $startRow;
        $sheet->setCellValue('A' . $titleRow, $title);
        $sheet->mergeCells("A{$titleRow}:{$lastColumn}{$titleRow}");
        $sheet->getStyle("A{$titleRow}:{$lastColumn}{$titleRow}")->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 16, 'bold' => true, 'color' => ['rgb' => self::HEADER_BLUE]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::LIGHT_BLUE]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($titleRow)->setRowHeight(28);

        $brandRow = $startRow + 1;
        $sheet->setCellValue('A' . $brandRow, $this->tr('Salameh Global / CargoChina'));
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
            $sheet->setCellValue('A' . $row, $label);
            if (is_string($value)
                && preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $value)
                && preg_match('/date|ready|received|created|updated|generated/i', $label)) {
                $excelDate = $this->excelDateValue($value);
                $sheet->setCellValue('B' . $row, $excelDate ?? $value);
                $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode(str_contains($value, ':') ? 'yyyy-mm-dd hh:mm:ss' : 'yyyy-mm-dd');
            } else {
                $sheet->setCellValue('B' . $row, $value);
            }
            $sheet->getStyle('A' . $row)->getFont()->setName('Arial')->setBold(true);
            $sheet->getStyle('A' . $row . ':' . $lastColumn . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        }

        $noteRow = $startRow + 6;
        $sheet->setCellValue('A' . $noteRow, $note);
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
            'I' => 'ITEM NO',
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
        ];

        $chineseRow = $row + 1;
        foreach ($headers as $col => $label) {
            $sheet->setCellValue($col . $row, $this->trForLocale($label, 'en'));
            $sheet->setCellValue($col . $chineseRow, $this->trForLocale($label, 'zh-CN'));
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
                $scope = strtolower(trim((string) ($item['product_dimensions_scope'] ?? $item['dimensions_scope'] ?? 'piece')));
                $multiplier = $scope === 'carton' && $cartons > 0 ? $cartons : $quantity;
                $cbmPer = $multiplier > 0 ? round((float) ($item['declared_cbm'] ?? 0) / $multiplier, 6) : '';
                $weightPer = $multiplier > 0 ? round((float) ($item['declared_weight'] ?? 0) / $multiplier, 4) : '';

                $supplierName = (string) ($item['supplier_name'] ?? $group['supplier_name'] ?? '');
                $supplierDisplay = trim((string) ($item['supplier_code'] ?? $item['supplier_store_id'] ?? ''));
                $sheet->setCellValue('A' . $row, $supplierDisplay !== '' ? $supplierDisplay : $supplierName);
                $sheet->setCellValue('B' . $row, $supplierName);
                $sheet->setCellValue('C' . $row, $this->itemText($item, 'brand') ?: $this->itemText($item, 'what_brand'));
                $sheet->setCellValue('D' . $row, $this->itemText($item, 'materials'));
                $sheet->setCellValue('E' . $row, $this->itemText($item, 'what_brand'));
                $sheet->setCellValue('F' . $row, $this->copyNormalGoodsText($item));
                $sheet->setCellValue('G' . $row, $this->itemText($item, 'code'));
                $sheet->setCellValue('I' . $row, (string) ($item['item_no'] ?? $item['shipping_code'] ?? ''));
                $sheet->setCellValue('J' . $row, trim((string) ($item['description_en'] ?? '')));
                $sheet->setCellValue('K' . $row, trim((string) ($item['description_cn'] ?? '')));
                $sheet->setCellValue('L' . $row, $this->dimensionValue($item, 'height', 'item_height'));
                $sheet->setCellValue('M' . $row, $this->dimensionValue($item, 'width', 'item_width'));
                $sheet->setCellValue('N' . $row, $this->dimensionValue($item, 'length', 'item_length'));
                $sheet->setCellValue('O' . $row, $cartons ?: '');
                $sheet->setCellValue('P' . $row, $qtyPerCarton ?: '');
                $sheet->setCellValue('Q' . $row, $quantity ?: '');
                $sheet->setCellValue('R' . $row, $this->itemText($item, 'unit'));
                $sheet->setCellValue('S' . $row, $unitPrice !== null ? $unitPrice : '');
                $sheet->setCellValue('T' . $row, ($unitPrice !== null && $quantity > 0) ? round($unitPrice * $quantity, 4) : '');
                $sheet->setCellValue('U' . $row, $cbmPer);
                $sheet->setCellValue('V' . $row, round((float) ($item['declared_cbm'] ?? 0), 6));
                $sheet->setCellValue('W' . $row, $weightPer);
                $sheet->setCellValue('X' . $row, round((float) ($item['declared_weight'] ?? 0), 4));
                $sheet->setCellValue('Y' . $row, $this->itemText($item, 'express_number'));
                $sheet->setCellValue('Z' . $row, $this->resolveItemSize($item));
                $sheet->setCellValue('AA' . $row, $this->itemText($item, 'hs_code'));
                $sheet->setCellValue('AB' . $row, $this->itemText($item, 'notes'));

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
                $this->writePhotoCell($sheet, self::PHOTO_COLUMN . $row, $item['image_paths'] ?? []);
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
        $sheet->setCellValue('A' . $row, $this->tr('Customer-facing receiving fees'));
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

            $sheet->setCellValue('A' . $row, $label !== '' ? $label : $this->tr('Warehouse fee'));
            $sheet->setCellValue('T' . $row, $amount);
            $sheet->setCellValue('U' . $row, $currency);
            $sheet->setCellValue('V' . $row, $notes);
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
            $sheet->setCellValue('A' . $row, $this->tr($label));
            $sheet->setCellValue('T' . $row, $this->formatCurrencyBreakdown($amounts));
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
        $sheet->setCellValue('A' . $row, $this->tr('Shipment Charges'));
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
            $sheet->setCellValue('A' . $row, $type);
            $sheet->setCellValue('T' . $row, (float) ($cost['base_amount'] ?? 0));
            $sheet->setCellValue('U' . $row, (string) ($cost['base_currency'] ?? ''));
            $sheet->setCellValue('V' . $row, $detail);
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

        $sheet->setCellValue('A' . $row, $this->tr('Shipment Charges Total'));
        $sheet->setCellValue('T' . $row, (float) ($costs['base_total'] ?? 0));
        $sheet->setCellValue('U' . $row, (string) ($costs['base_currency'] ?? ''));
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
            'F' => 'ITEM NO',
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
        ];

        $chineseRow = $row + 1;
        foreach ($headers as $col => $label) {
            $sheet->setCellValue($col . $row, $this->trForLocale($label, 'en'));
            $sheet->setCellValue($col . $chineseRow, $this->trForLocale($label, 'zh-CN'));
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

        $sheet->setCellValue('A' . $row, '##');
        $sheet->setCellValue('B' . $row, $section['customer_display'] ?: $this->tr('Customer'));
        $sheet->setCellValue('K' . $row, trim((string) ($section['customer_phone'] ?? '')) !== ''
            ? $this->tr('Phone: {phone}', ['phone' => $section['customer_phone']])
            : $this->tr('Phone: -'));
        $sheet->setCellValue('N' . $row, $orderLabel);
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
                $scope = strtolower(trim((string) ($item['product_dimensions_scope'] ?? $item['dimensions_scope'] ?? 'piece')));
                $multiplier = $scope === 'carton' && $cartons > 0 ? $cartons : $quantity;
                $totalCbm = round((float) ($item['declared_cbm'] ?? 0), 6);
                $totalWeight = round((float) ($item['declared_weight'] ?? 0), 4);
                $cbmPer = $multiplier > 0 ? round($totalCbm / $multiplier, 6) : '';
                $weightPer = $multiplier > 0 ? round($totalWeight / $multiplier, 4) : '';
                $accountNumber = $this->extractSupplierAccountReference($item, $order);
                $supplierPhone = trim((string) ($item['supplier_phone'] ?? $group['supplier_phone'] ?? $order['supplier_phone'] ?? ''));

                $sheet->setCellValue('B' . $row, $this->itemText($item, 'what_brand'));
                $sheet->setCellValue('C' . $row, $this->copyNormalGoodsText($item));
                $sheet->setCellValue('D' . $row, $this->itemText($item, 'code'));
                $sheet->setCellValue('F' . $row, (string) ($item['item_no'] ?? $item['shipping_code'] ?? ''));
                $sheet->setCellValue('G' . $row, (string) ($item['supplier_name'] ?? $group['supplier_name'] ?? $order['supplier_name'] ?? ''));
                $sheet->setCellValue('H' . $row, $supplierPhone);
                $sheet->setCellValue('I' . $row, $accountNumber !== '' ? $accountNumber : $group['supplier_info']);
                $sheet->setCellValue('J' . $row, $this->descriptionText($item));
                $sheet->setCellValue('K' . $row, $cartons ?: '');
                $sheet->setCellValue('L' . $row, $qtyPerCarton ?: '');
                $sheet->setCellValue('M' . $row, $quantity ?: '');
                $sheet->setCellValue('N' . $row, $unitPrice !== null ? $unitPrice : '');
                $sheet->setCellValue('O' . $row, $factoryPrice !== null ? $factoryPrice : '');
                $sheet->setCellValue('P' . $row, $sellTotal ?: '');
                $sheet->setCellValue('Q' . $row, $cbmPer);
                $sheet->setCellValue('R' . $row, $totalCbm ?: '');
                $sheet->setCellValue('S' . $row, $weightPer);
                $sheet->setCellValue('T' . $row, $totalWeight ?: '');
                $sheet->setCellValue('U' . $row, $this->itemText($item, 'express_number'));
                $sheet->setCellValue('V' . $row, $this->resolveItemSize($item));

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
                $this->writePhotoCell($sheet, self::CONTAINER_PHOTO_COLUMN . $row, $item['image_paths'] ?? []);

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
        $sheet->setCellValue('A' . $row, '@@');
        $sheet->setCellValue('B' . $row, $this->tr('supplier name and info') . ':');
        $sheet->setCellValue('C' . $row, $supplierName !== '' ? $supplierName : '-');
        $sheet->setCellValue('D' . $row, $supplierPhone !== '' ? $supplierPhone : '-');
        $sheet->setCellValue('E' . $row, $supplierInfo !== '' ? $supplierInfo : '-');

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

            $sheet->setCellValue('B' . $row, implode(' - ', $labelParts));
            $sheet->setCellValue('J' . $row, $description);
            $sheet->setCellValue('P' . $row, $this->formatCurrencyBreakdown([$currency => $amount]));
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
            $sheet->setCellValue('B' . $row, $section['customer_display'] . ' - ' . $this->tr($label));
            $sheet->setCellValue('P' . $row, $this->formatCurrencyBreakdown($amounts));
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
        $sheet->setCellValue('B' . $row, $this->tr('Container-wide expenses'));
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

            $sheet->setCellValue('B' . $row, $this->tr('Container expense'));
            $sheet->setCellValue('J' . $row, $description);
            $sheet->setCellValue('P' . $row, $this->formatCurrencyBreakdown([$currency => $amount]));
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
            ['Overall CBM', ['METRIC' => round($overallTotals['cbm'], 6)]],
            ['Overall weight', ['METRIC' => round($overallTotals['weight'], 4)]],
        ];

        foreach ($rows as $entry) {
            [$label, $amounts] = $entry;
            $value = array_key_exists('METRIC', $amounts)
                ? (string) $amounts['METRIC']
                : $this->formatCurrencyBreakdown($amounts);
            $sheet->setCellValue('B' . $row, $this->tr($label));
            $sheet->setCellValue('P' . $row, $value);
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

    private function writePhotoCell($sheet, string $cell, $imagePaths): void
    {
        $paths = $this->normalizeImagePaths($imagePaths);
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

        if (!$paths) {
            $sheet->setCellValue($cell, $this->tr('No photo'));
            return;
        }

        $sourcePath = '';
        foreach ($paths as $candidate) {
            $resolved = $this->resolveWorkbookImageSource($candidate);
            if (is_file($resolved) && is_readable($resolved)) {
                $sourcePath = $resolved;
                break;
            }
        }
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            $sheet->setCellValue($cell, $this->tr('No photo'));
            return;
        }

        try {
            $defaultFont = $sheet->getParent()->getDefaultStyle()->getFont();
            $photoColumn = preg_replace('/\d+$/', '', $cell) ?: self::PHOTO_COLUMN;
            $columnWidthPx = SharedDrawing::cellDimensionToPixels(
                $sheet->getColumnDimension($photoColumn)->getWidth(),
                $defaultFont
            );
            $heightPx = SharedDrawing::pointsToPixels(self::PHOTO_ROW_HEIGHT_PT);
            $imageWidthPx = max(32, (int) floor($columnWidthPx * self::PHOTO_IMAGE_SCALE));
            $imageHeightPx = max(32, (int) floor($heightPx * self::PHOTO_IMAGE_SCALE));
            $offsetX = max(0, (int) floor(($columnWidthPx - $imageWidthPx) / 2));
            $offsetY = max(0, (int) floor(($heightPx - $imageHeightPx) / 2));
            $path = $this->workbookImagePath($sourcePath, $imageWidthPx * 2, $imageHeightPx * 2);

            $drawing = new Drawing();
            $drawing->setPath($path);
            $drawing->setCoordinates($cell);
            $drawing->setResizeProportional(false);
            $drawing->setWidth($imageWidthPx);
            $drawing->setHeight($imageHeightPx);
            $drawing->setOffsetX($offsetX);
            $drawing->setOffsetY($offsetY);
            $drawing->setWorksheet($sheet);
            $sheet->setCellValue($cell, '');
        } catch (Throwable $e) {
            $sheet->setCellValue($cell, $this->tr('No photo'));
        }
    }

    private function resolveWorkbookImageSource(string $path): string
    {
        $urlPath = preg_match('#^https?://#i', $path) ? (string) parse_url($path, PHP_URL_PATH) : $path;
        $localRelative = ltrim(str_replace('\\', '/', $urlPath), '/');
        foreach (['cargochina/backend/', 'backend/'] as $prefix) {
            if (str_starts_with(strtolower($localRelative), $prefix)) {
                $localRelative = substr($localRelative, strlen($prefix));
                break;
            }
        }
        if (str_starts_with($localRelative, 'uploads/')) {
            if (str_contains($localRelative, '../')) return '';
            $localPath = $this->backendDir . '/' . $localRelative;
            if (is_file($localPath) && is_readable($localPath)) {
                return $localPath;
            }
        }
        if (!preg_match('#^https?://#i', $path)) {
            $relative = ltrim(str_replace('\\', '/', $path), '/');
            if (str_contains($relative, '../')) return '';
            return $this->backendDir . '/' . $relative;
        }
        $url = parse_url($path);
        $host = strtolower((string) ($url['host'] ?? ''));
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) return '';
        $ip = gethostbyname($host);
        if ($ip === $host || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return '';
        $cacheDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'clms_excel_remote_images';
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0770, true) && !is_dir($cacheDir)) return '';
        $target = $cacheDir . DIRECTORY_SEPARATOR . hash('sha256', $path) . '.img';
        if (is_file($target) && filesize($target) > 0) return $target;
        $context = stream_context_create(['http' => ['timeout' => 5, 'follow_location' => 0, 'user_agent' => 'CLMS Excel Export/1.0']]);
        $source = @fopen($path, 'rb', false, $context);
        if (!$source) return '';
        $data = stream_get_contents($source, 8 * 1024 * 1024 + 1);
        fclose($source);
        if (!is_string($data) || $data === '' || strlen($data) > 8 * 1024 * 1024 || @getimagesizefromstring($data) === false) return '';
        if (@file_put_contents($target, $data, LOCK_EX) === false) return '';
        return $target;
    }

    private function normalizeImagePaths($imagePaths): array
    {
        if (is_string($imagePaths)) {
            $decoded = json_decode($imagePaths, true);
            $imagePaths = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($imagePaths)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($path): string {
            return trim((string) $path);
        }, $imagePaths), 'strlen'));
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
            $sheet->setCellValue($column . $headerRow, $header);
            $sheet->setCellValue($column . $chineseHeaderRow, $chineseHeaders[$index] ?? $header);
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
                    $this->writePhotoCell($sheet, $cell, $value);
                    $sheet->getColumnDimension($column)->setWidth(self::PHOTO_COLUMN_WIDTH);
                    $sheet->getRowDimension($rowNumber)->setRowHeight(self::PHOTO_ROW_HEIGHT_PT);
                } elseif (is_string($value)
                    && preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $value)
                    && preg_match('/date|ready|received|created|updated|generated/i', $header)) {
                    $excelDate = $this->excelDateValue($value);
                    $sheet->setCellValue($cell, $excelDate ?? $value);
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(str_contains($value, ':') ? 'yyyy-mm-dd hh:mm:ss' : 'yyyy-mm-dd');
                } else {
                    $sheet->setCellValue($cell, $value);
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
            $sheet->setCellValue('A' . $rowNumber, $this->tr('No rows available.'));
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
