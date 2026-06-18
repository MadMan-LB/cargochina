<?php

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/api/helpers.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Drawing as SharedDrawing;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class OrderExcelService
{
    private string $backendDir;

    private const STANDARD_LAST_COL = 'S';
    private const CONTAINER_LAST_COL = 'V';
    private const PHOTO_COLUMN = 'E';
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

    public function __construct()
    {
        $this->backendDir = dirname(__DIR__);
    }

    private function tr(string $text, array $params = []): string
    {
        return function_exists('clmsT') ? clmsT($text, $params) : $text;
    }

    private function statusText(string $status): string
    {
        return function_exists('clmsStatusLabel') ? clmsStatusLabel($status) : $status;
    }

    public function exportOrder(array $order, array $items, ?string $filename = null): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $this->setStandardColumnWidths($sheet);

        $row = $this->writeCompanyHeader($sheet, 1, self::STANDARD_LAST_COL);
        $this->writeStandardColumnHeaders($sheet, $row);
        $row++;
        $this->writeStandardItems($sheet, $items, $row, $order);

        $outName = $filename ?? ('order_' . (int) ($order['id'] ?? 0) . '_goods_details.xlsx');
        $this->outputXlsx($spreadsheet, $outName);
    }

    public function exportOrders(array $ordersWithItems, string $filename = 'container_orders.xlsx', array $context = []): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $this->setContainerColumnWidths($sheet);

        $row = $this->writeCompanyHeader($sheet, 1, self::CONTAINER_LAST_COL);
        $this->writeContainerColumnHeaders($sheet, $row);
        $row++;

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
        $sheet->freezePane('A6');

        $this->outputXlsx($spreadsheet, $filename);
    }

    public function exportOrdersListSummary(array $rows, string $filename = 'orders_list.xlsx'): void
    {
        $headers = [
            'Order ID',
            'Order Type',
            'Customer',
            'Supplier',
            'Expected Ready',
            'Status',
            'Deposit Status',
            'Paid Amount',
            'Remaining Balance',
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
                (string) ($row['order_type'] ?? 'standard'),
                self::formatCustomerDisplay($row, $items),
                $supplierDisplay,
                (string) ($row['expected_ready_date'] ?? ''),
                $this->statusText((string) ($row['status'] ?? '')),
                $this->tr((string) ($row['deposit_status'] ?? 'No Deposit')),
                round((float) ($row['deposit_paid_amount'] ?? 0), 2),
                round((float) ($row['remaining_balance'] ?? 0), 2),
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
                    'Brand' => $item['what_brand'] ?? '',
                    'Code' => $item['code'] ?? '',
                    'Express' => $item['express_number'] ?? '',
                    'Size' => $item['size'] ?? '',
                ] as $label => $value) {
                    $value = trim((string) $value);
                    if ($value !== '') {
                        $summaryParts[] = $label . ':' . $value;
                    }
                }
                $itemsSummary[] = trim(implode(' ', $summaryParts));
            }

            return [
                (int) ($row['id'] ?? 0),
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

    private function setStandardColumnWidths($sheet): void
    {
        $widths = [
            'A' => 9,
            'B' => 14,
            'C' => 18,
            'D' => 14,
            'E' => self::PHOTO_COLUMN_WIDTH,
            'F' => 20.14,
            'G' => 20.14,
            'H' => 52.14,
            'I' => 15.29,
            'J' => 15.29,
            'K' => 15.29,
            'L' => 15.29,
            'M' => 15.29,
            'N' => 9,
            'O' => 15.29,
            'P' => 15.29,
            'Q' => 15.29,
            'R' => 18,
            'S' => 18,
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

    private function writeCompanyHeader($sheet, int $startRow, string $lastColumn): int
    {
        $headerRows = [
            'MASTERTOOLS COMPANY LIMITED',
            '        ADDRESS: CHINA-ZHEJIANG PROVINCE-YIWU CITY-JIANDONG STREET-WUYUE SQUARE -MANSION NO.1 -25th FLOOR-2515',
            '        TEL: 0579-85178151/85178152        FAX: 0579-85177247',
            'GOOD DETAILS',
        ];

        foreach ($headerRows as $index => $text) {
            $row = $startRow + $index;
            $sheet->setCellValue('B' . $row, $text);
            $sheet->mergeCells(sprintf('B%d:%s%d', $row, $lastColumn, $row));
            $sheet->getStyle(sprintf('B%d:%s%d', $row, $lastColumn, $row))->applyFromArray([
                'font' => [
                    'name' => 'Calibri',
                    'size' => 16,
                    'bold' => true,
                    'color' => ['rgb' => self::HEADER_BLUE],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FFFFFF'],
                ],
                'borders' => [
                    'outline' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => self::BORDER_COLOR],
                    ],
                ],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(39.95);
        }

        return $startRow + 4;
    }

    private function writeStandardColumnHeaders($sheet, int $row): void
    {
        $headers = [
            'B' => 'WHAT BRAND',
            'C' => 'copy /NORMAL Goods',
            'D' => 'CODE',
            'E' => 'PHOTO',
            'F' => 'ITEM NO',
            'G' => 'SUPPLIER',
            'H' => 'DESCRIPTION',
            'I' => 'TOTAL CTNS',
            'J' => 'QTY/CTN',
            'K' => 'TOTAL QTY',
            'L' => 'UNIT PRICE',
            'M' => 'TOTAL AMOUNT',
            'N' => 'CBM',
            'O' => 'TOTAL CBM',
            'P' => 'GWKG',
            'Q' => 'TOTAL GW',
            'R' => 'express NO',
            'S' => 'size',
        ];

        foreach ($headers as $col => $label) {
            $sheet->setCellValue($col . $row, $this->tr($label));
        }

        $this->styleRange($sheet, 'B' . $row . ':' . self::STANDARD_LAST_COL . $row, [
            'font' => ['name' => 'Arial', 'size' => 11, 'bold' => true, 'color' => ['rgb' => self::HEADER_BLUE]],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFFFF']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(54.75);
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

                $sheet->setCellValue('B' . $row, $this->itemText($item, 'what_brand'));
                $sheet->setCellValue('C' . $row, $this->copyNormalGoodsText($item));
                $sheet->setCellValue('D' . $row, $this->itemText($item, 'code'));
                $sheet->setCellValue('F' . $row, (string) ($item['item_no'] ?? $item['shipping_code'] ?? ''));
                $sheet->setCellValue('G' . $row, (string) ($item['supplier_name'] ?? $group['supplier_name'] ?? ''));
                $sheet->setCellValue('H' . $row, $this->descriptionText($item));
                $sheet->setCellValue('I' . $row, $cartons ?: '');
                $sheet->setCellValue('J' . $row, $qtyPerCarton ?: '');
                $sheet->setCellValue('K' . $row, $quantity ?: '');
                $sheet->setCellValue('L' . $row, $unitPrice !== null ? $unitPrice : '');
                $sheet->setCellValue('M' . $row, ($unitPrice !== null && $quantity > 0) ? round($unitPrice * $quantity, 4) : '');
                $sheet->setCellValue('N' . $row, $cbmPer);
                $sheet->setCellValue('O' . $row, round((float) ($item['declared_cbm'] ?? 0), 6));
                $sheet->setCellValue('P' . $row, $weightPer);
                $sheet->setCellValue('Q' . $row, round((float) ($item['declared_weight'] ?? 0), 4));
                $sheet->setCellValue('R' . $row, $this->itemText($item, 'express_number'));
                $sheet->setCellValue('S' . $row, $this->resolveItemSize($item));

                $this->styleRange($sheet, 'B' . $row . ':' . self::STANDARD_LAST_COL . $row, [
                    'font' => ['name' => 'Arial', 'size' => 11],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFFFF']],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
                ]);
                $sheet->getStyle('L' . $row . ':M' . $row)->getNumberFormat()->setFormatCode('#,##0.####');
                $sheet->getStyle('N' . $row . ':O' . $row)->getNumberFormat()->setFormatCode('#,##0.######');
                $sheet->getStyle('P' . $row . ':Q' . $row)->getNumberFormat()->setFormatCode('#,##0.####');

                $sheet->getRowDimension($row)->setRowHeight(self::PHOTO_ROW_HEIGHT_PT);
                $this->writePhotoCell($sheet, self::PHOTO_COLUMN . $row, $item['image_paths'] ?? []);
                $row++;
            }
        }

        return $row;
    }

    private function writeContainerColumnHeaders($sheet, int $row): void
    {
        $headers = [
            'B' => 'WHAT BRAND',
            'C' => 'copy /NORMAL Goods',
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

        foreach ($headers as $col => $label) {
            $sheet->setCellValue($col . $row, $this->tr($label));
        }

        $this->styleRange($sheet, 'B' . $row . ':' . self::CONTAINER_LAST_COL . $row, [
            'font' => ['name' => 'Arial', 'size' => 11, 'bold' => true, 'color' => ['rgb' => self::HEADER_BLUE]],
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
        $sheet->getRowDimension($row)->setRowHeight(54.75);
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
                $this->writePhotoCell($sheet, self::PHOTO_COLUMN . $row, $item['image_paths'] ?? []);

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
        $sheet->setCellValue('B' . $row, $this->tr('supplier name and info'));
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
            return;
        }

        $path = $this->backendDir . '/' . $paths[0];
        if (!is_file($path) || !is_readable($path)) {
            $sheet->setCellValue($cell, $this->tr('{count} photo(s)', ['count' => count($paths)]));
            return;
        }

        try {
            $defaultFont = $sheet->getParent()->getDefaultStyle()->getFont();
            $columnWidthPx = SharedDrawing::cellDimensionToPixels(
                $sheet->getColumnDimension(self::PHOTO_COLUMN)->getWidth(),
                $defaultFont
            );
            $heightPx = SharedDrawing::pointsToPixels(self::PHOTO_ROW_HEIGHT_PT);
            $imageWidthPx = max(32, (int) floor($columnWidthPx * self::PHOTO_IMAGE_SCALE));
            $imageHeightPx = max(32, (int) floor($heightPx * self::PHOTO_IMAGE_SCALE));
            $offsetX = max(0, (int) floor(($columnWidthPx - $imageWidthPx) / 2));
            $offsetY = max(0, (int) floor(($heightPx - $imageHeightPx) / 2));

            $drawing = new Drawing();
            $drawing->setPath($path);
            $drawing->setCoordinates($cell);
            $drawing->setResizeProportional(false);
            $drawing->setWidth($imageWidthPx);
            $drawing->setHeight($imageHeightPx);
            $drawing->setOffsetX($offsetX);
            $drawing->setOffsetY($offsetY);
            $drawing->setWorksheet($sheet);
        } catch (Throwable $e) {
            $sheet->setCellValue($cell, $this->tr('{count} photo(s)', ['count' => count($paths)]));
        }
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
        $value = $this->itemText($item, 'copy_normal_goods');
        return match (strtolower($value)) {
            'copy' => $this->tr('Copy Goods'),
            'normal' => $this->tr('Normal Goods'),
            default => $value,
        };
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
        $writer = new Xlsx($spreadsheet);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $filename) . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        $writer->save('php://output');
        exit;
    }

    private function exportSimpleTable(string $title, array $headers, array $rows, string $filename): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $localizedTitle = $this->tr($title);
        $localizedHeaders = array_map(fn($header) => $this->tr((string) $header), $headers);
        $sheet->setTitle(substr($localizedTitle, 0, 31));
        $sheet->setCellValue('A1', $localizedTitle);
        $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($localizedHeaders)));
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['name' => 'Calibri', 'size' => 14, 'bold' => true, 'color' => ['rgb' => self::HEADER_BLUE]],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8FBFF']],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(26);

        foreach ($localizedHeaders as $index => $header) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($column . '2', $header);
            $sheet->getStyle($column . '2')->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 11, 'bold' => true, 'color' => ['rgb' => self::HEADER_BLUE]],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::LIGHT_BLUE]],
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D8E2F0']],
                ],
            ]);
        }
        $sheet->getRowDimension(2)->setRowHeight(22);

        $rowNumber = 3;
        foreach ($rows as $row) {
            foreach (array_values($row) as $index => $value) {
                $column = Coordinate::stringFromColumnIndex($index + 1);
                $sheet->setCellValue($column . $rowNumber, $value);
                $sheet->getStyle($column . $rowNumber)->applyFromArray([
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

        if ($rowNumber === 3) {
            $sheet->setCellValue('A3', $this->tr('No rows available.'));
            $sheet->mergeCells("A3:{$lastColumn}3");
            $sheet->getStyle("A3:{$lastColumn}3")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle("A3:{$lastColumn}3")->getFont()->setItalic(true);
            $rowNumber++;
        }

        foreach (range(1, count($localizedHeaders)) as $index) {
            $column = Coordinate::stringFromColumnIndex($index);
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->freezePane('A3');
        $sheet->setAutoFilter("A2:{$lastColumn}2");

        $this->outputXlsx($spreadsheet, $filename);
    }
}
