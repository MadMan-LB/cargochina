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

    private const STANDARD_LAST_COL = 'N';
    private const CONTAINER_LAST_COL = 'Q';
    private const PHOTO_COLUMN = 'B';
    private const PHOTO_COLUMN_WIDTH = 21;
    private const PHOTO_ROW_HEIGHT_PT = 90;
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

    public function exportOrder(array $order, array $items, ?string $filename = null): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $this->setStandardColumnWidths($sheet);

        $row = $this->writeCompanyHeader($sheet, 1, self::STANDARD_LAST_COL);
        $this->writeStandardColumnHeaders($sheet, $row);
        $row++;
        $this->writeStandardItems($sheet, $items, $row);

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
                $supplierDisplay = count($names) === 1 ? $names[0] : 'Multiple (' . implode(', ', $names) . ')';
            }

            return [
                (int) ($row['id'] ?? 0),
                (string) ($row['order_type'] ?? 'standard'),
                self::formatCustomerDisplay($row, $items),
                $supplierDisplay,
                (string) ($row['expected_ready_date'] ?? ''),
                (string) ($row['status'] ?? ''),
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
                $itemsSummary[] = trim(sprintf(
                    '%s %sctn HS:%s',
                    $shippingCode !== '' ? $shippingCode : '-',
                    (string) ((int) ($item['cartons'] ?? 0)),
                    trim((string) ($item['hs_code'] ?? '')) !== '' ? (string) $item['hs_code'] : '-'
                ));
            }

            return [
                (int) ($row['id'] ?? 0),
                self::formatCustomerDisplay($row, $items),
                (string) ($row['supplier_name'] ?? ''),
                (string) ($row['supplier_phone'] ?? ''),
                (string) ($row['expected_ready_date'] ?? ''),
                (string) ($row['status'] ?? ''),
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
            'B' => self::PHOTO_COLUMN_WIDTH,
            'C' => 20.14,
            'D' => 20.14,
            'E' => 52.14,
            'F' => 15.29,
            'G' => 15.29,
            'H' => 15.29,
            'I' => 15.29,
            'J' => 15.29,
            'K' => 15.29,
            'L' => 15.29,
            'M' => 9,
            'N' => 15.29,
        ];

        foreach ($widths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
    }

    private function setContainerColumnWidths($sheet): void
    {
        $widths = [
            'A' => 5,
            'B' => self::PHOTO_COLUMN_WIDTH,
            'C' => 18,
            'D' => 24,
            'E' => 18,
            'F' => 22,
            'G' => 48,
            'H' => 12,
            'I' => 12,
            'J' => 12,
            'K' => 14,
            'L' => 14,
            'M' => 16,
            'N' => 12,
            'O' => 12,
            'P' => 9,
            'Q' => 12,
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
            'B' => 'PHOTO',
            'C' => 'ITEM NO',
            'D' => 'SUPPLIER',
            'E' => 'DESCRIPTION',
            'F' => 'TOTAL CTNS',
            'G' => 'QTY/CTN',
            'H' => 'TOTAL QTY',
            'I' => 'UNIT PRICE',
            'J' => 'TOTAL AMOUNT',
            'K' => 'CBM',
            'L' => 'TOTAL CBM',
            'M' => 'GWKG',
            'N' => 'TOTAL GW',
        ];

        foreach ($headers as $col => $label) {
            $sheet->setCellValue($col . $row, $label);
        }

        $this->styleRange($sheet, 'B' . $row . ':N' . $row, [
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

    private function writeStandardItems($sheet, array $items, int $startRow): int
    {
        $row = $startRow;

        foreach ($items as $item) {
            $cartons = (float) ($item['cartons'] ?? 0);
            $qtyPerCarton = (float) ($item['qty_per_carton'] ?? 0);
            $quantity = $this->resolveQuantity($item);
            $unitPrice = $this->resolveUnitPrice($item);
            $scope = strtolower(trim((string) ($item['product_dimensions_scope'] ?? $item['dimensions_scope'] ?? 'piece')));
            $multiplier = $scope === 'carton' && $cartons > 0 ? $cartons : $quantity;
            $cbmPer = $multiplier > 0 ? round((float) ($item['declared_cbm'] ?? 0) / $multiplier, 6) : '';
            $weightPer = $multiplier > 0 ? round((float) ($item['declared_weight'] ?? 0) / $multiplier, 4) : '';

            $sheet->setCellValue('C' . $row, (string) ($item['item_no'] ?? $item['shipping_code'] ?? ''));
            $sheet->setCellValue('D' . $row, (string) ($item['supplier_name'] ?? ''));
            $sheet->setCellValue('E' . $row, (string) ($item['description_en'] ?? $item['description_cn'] ?? ''));
            $sheet->setCellValue('F' . $row, $cartons ?: '');
            $sheet->setCellValue('G' . $row, $qtyPerCarton ?: '');
            $sheet->setCellValue('H' . $row, $quantity ?: '');
            $sheet->setCellValue('I' . $row, $unitPrice !== null ? $unitPrice : '');
            $sheet->setCellValue('J' . $row, ($unitPrice !== null && $quantity > 0) ? round($unitPrice * $quantity, 4) : '');
            $sheet->setCellValue('K' . $row, $cbmPer);
            $sheet->setCellValue('L' . $row, round((float) ($item['declared_cbm'] ?? 0), 6));
            $sheet->setCellValue('M' . $row, $weightPer);
            $sheet->setCellValue('N' . $row, round((float) ($item['declared_weight'] ?? 0), 4));

            $this->styleRange($sheet, 'B' . $row . ':N' . $row, [
                'font' => ['name' => 'Arial', 'size' => 11],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFFFF']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
            ]);

            $sheet->getRowDimension($row)->setRowHeight(self::PHOTO_ROW_HEIGHT_PT);
            $this->writePhotoCell($sheet, 'B' . $row, $item['image_paths'] ?? []);
            $row++;
        }

        return $row;
    }

    private function writeContainerColumnHeaders($sheet, int $row): void
    {
        $headers = [
            'B' => 'PHOTO',
            'C' => 'ITEM NO',
            'D' => 'SUPPLIER',
            'E' => 'SUPPLIER PHONE',
            'F' => 'ACCOUNT NB',
            'G' => 'DESCRIPTION',
            'H' => 'TOTAL CTNS',
            'I' => 'QTY/CTN',
            'J' => 'TOTAL QTY',
            'K' => 'UNIT PRICE',
            'L' => 'FACTORY PRICE',
            'M' => 'TOTAL AMOUNT',
            'N' => 'CBM',
            'O' => 'TOTAL CBM',
            'P' => 'GWKG',
            'Q' => 'TOTAL GW',
        ];

        foreach ($headers as $col => $label) {
            $sheet->setCellValue($col . $row, $label);
        }

        $this->styleRange($sheet, 'B' . $row . ':Q' . $row, [
            'font' => ['name' => 'Arial', 'size' => 11, 'bold' => true, 'color' => ['rgb' => self::HEADER_BLUE]],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFFFF']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
        ]);
        $this->styleRange($sheet, 'D' . $row . ':F' . $row, [
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::LIGHT_YELLOW]],
        ]);
        $this->styleRange($sheet, 'L' . $row, [
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
        $orderLabel = $orderIds ? ('Orders: #' . implode(', #', $orderIds)) : 'Orders: -';

        $sheet->setCellValue('A' . $row, '##');
        $sheet->setCellValue('B' . $row, $section['customer_display'] ?: 'Customer');
        $sheet->setCellValue('H' . $row, trim((string) ($section['customer_phone'] ?? '')) !== ''
            ? ('Phone: ' . $section['customer_phone'])
            : 'Phone: -');
        $sheet->setCellValue('K' . $row, $orderLabel);
        $sheet->mergeCells('B' . $row . ':G' . $row);
        $sheet->mergeCells('H' . $row . ':J' . $row);
        $sheet->mergeCells('K' . $row . ':Q' . $row);

        $this->styleRange($sheet, 'A' . $row . ':Q' . $row, [
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
        foreach ($items as $item) {
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
            $supplierPhone = trim((string) ($item['supplier_phone'] ?? $order['supplier_phone'] ?? ''));

            $sheet->setCellValue('C' . $row, (string) ($item['item_no'] ?? $item['shipping_code'] ?? ''));
            $sheet->setCellValue('D' . $row, (string) ($item['supplier_name'] ?? $order['supplier_name'] ?? ''));
            $sheet->setCellValue('E' . $row, $supplierPhone);
            $sheet->setCellValue('F' . $row, $accountNumber);
            $sheet->setCellValue('G' . $row, (string) ($item['description_en'] ?? $item['description_cn'] ?? ''));
            $sheet->setCellValue('H' . $row, $cartons ?: '');
            $sheet->setCellValue('I' . $row, $qtyPerCarton ?: '');
            $sheet->setCellValue('J' . $row, $quantity ?: '');
            $sheet->setCellValue('K' . $row, $unitPrice !== null ? $unitPrice : '');
            $sheet->setCellValue('L' . $row, $factoryPrice !== null ? $factoryPrice : '');
            $sheet->setCellValue('M' . $row, $sellTotal ?: '');
            $sheet->setCellValue('N' . $row, $cbmPer);
            $sheet->setCellValue('O' . $row, $totalCbm ?: '');
            $sheet->setCellValue('P' . $row, $weightPer);
            $sheet->setCellValue('Q' . $row, $totalWeight ?: '');

            $this->styleRange($sheet, 'B' . $row . ':Q' . $row, [
                'font' => ['name' => 'Arial', 'size' => 11],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFFFF']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
            ]);
            $this->styleRange($sheet, 'D' . $row . ':F' . $row, [
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::LIGHT_YELLOW]],
            ]);
            $this->styleRange($sheet, 'L' . $row, [
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::LIGHT_YELLOW]],
            ]);
            $sheet->getStyle('G' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getRowDimension($row)->setRowHeight(self::PHOTO_ROW_HEIGHT_PT);
            $this->writePhotoCell($sheet, 'B' . $row, $item['image_paths'] ?? []);

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

        return $row;
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
            $labelParts = ['Expense'];
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
                $description = 'Container / customer expense';
            }

            $sheet->setCellValue('B' . $row, implode(' - ', $labelParts));
            $sheet->setCellValue('G' . $row, $description);
            $sheet->setCellValue('M' . $row, $this->formatCurrencyBreakdown([$currency => $amount]));
            $sheet->mergeCells('B' . $row . ':F' . $row);
            $sheet->mergeCells('G' . $row . ':L' . $row);
            $sheet->mergeCells('M' . $row . ':Q' . $row);

            $this->styleRange($sheet, 'B' . $row . ':Q' . $row, [
                'font' => ['name' => 'Arial', 'size' => 10],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::LIGHT_YELLOW]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
            ]);
            $sheet->getStyle('M' . $row . ':Q' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
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
            $sheet->setCellValue('B' . $row, $section['customer_display'] . ' - ' . $label);
            $sheet->setCellValue('M' . $row, $this->formatCurrencyBreakdown($amounts));
            $sheet->mergeCells('B' . $row . ':L' . $row);
            $sheet->mergeCells('M' . $row . ':Q' . $row);
            $this->styleRange($sheet, 'B' . $row . ':Q' . $row, [
                'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $fill]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
            ]);
            $sheet->getStyle('M' . $row . ':Q' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getRowDimension($row)->setRowHeight(22);
            $row++;
        }

        return $row;
    }

    private function writeOverallExpenseBlock($sheet, array $expenses, int $row, array &$overallTotals): int
    {
        $sheet->setCellValue('B' . $row, 'Container-wide expenses');
        $sheet->mergeCells('B' . $row . ':Q' . $row);
        $this->styleRange($sheet, 'B' . $row . ':Q' . $row, [
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

            $sheet->setCellValue('B' . $row, 'Container expense');
            $sheet->setCellValue('G' . $row, $description);
            $sheet->setCellValue('M' . $row, $this->formatCurrencyBreakdown([$currency => $amount]));
            $sheet->mergeCells('B' . $row . ':F' . $row);
            $sheet->mergeCells('G' . $row . ':L' . $row);
            $sheet->mergeCells('M' . $row . ':Q' . $row);
            $this->styleRange($sheet, 'B' . $row . ':Q' . $row, [
                'font' => ['name' => 'Arial', 'size' => 10],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::SOFT_GREEN]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
            ]);
            $sheet->getStyle('M' . $row . ':Q' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
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
            $sheet->setCellValue('B' . $row, $label);
            $sheet->setCellValue('M' . $row, $value);
            $sheet->mergeCells('B' . $row . ':L' . $row);
            $sheet->mergeCells('M' . $row . ':Q' . $row);
            $this->styleRange($sheet, 'B' . $row . ':Q' . $row, [
                'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::LIGHT_RED]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
            ]);
            $sheet->getStyle('M' . $row . ':Q' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
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
            $sheet->setCellValue($cell, count($paths) . ' photo(s)');
            return;
        }

        try {
            $defaultFont = $sheet->getParent()->getDefaultStyle()->getFont();
            $columnWidthPx = SharedDrawing::cellDimensionToPixels(
                $sheet->getColumnDimension(self::PHOTO_COLUMN)->getWidth(),
                $defaultFont
            );
            $heightPx = SharedDrawing::pointsToPixels(self::PHOTO_ROW_HEIGHT_PT);

            $drawing = new Drawing();
            $drawing->setPath($path);
            $drawing->setCoordinates($cell);
            $drawing->setResizeProportional(false);
            $drawing->setWidth($columnWidthPx);
            $drawing->setHeight($heightPx);
            $drawing->setWorksheet($sheet);
        } catch (Throwable $e) {
            $sheet->setCellValue($cell, count($paths) . ' photo(s)');
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
            $parts[] = $currency . ' ' . number_format($amount, 2);
        }

        return $parts ? implode(' | ', $parts) : '-';
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
        $sheet->setTitle(substr($title, 0, 31));
        $sheet->setCellValue('A1', $title);
        $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($headers)));
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

        foreach ($headers as $index => $header) {
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
            $sheet->setCellValue('A3', 'No rows available.');
            $sheet->mergeCells("A3:{$lastColumn}3");
            $sheet->getStyle("A3:{$lastColumn}3")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle("A3:{$lastColumn}3")->getFont()->setItalic(true);
            $rowNumber++;
        }

        foreach (range(1, count($headers)) as $index) {
            $column = Coordinate::stringFromColumnIndex($index);
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->freezePane('A3');
        $sheet->setAutoFilter("A2:{$lastColumn}2");

        $this->outputXlsx($spreadsheet, $filename);
    }
}
