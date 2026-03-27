<?php

/**
 * Order Excel Export — matches Template.xlsx layout exactly
 *
 * Column layout (A=narrow/empty, content starts at B):
 *   B=PHOTO  C=ITEM NO  D=SUPPLIER  E=DESCRIPTION  F=TOTAL CTNS
 *   G=QTY/CTN  H=TOTAL QTY  I=UNIT PRICE  J=TOTAL AMOUNT
 *   K=CBM  L=TOTAL CBM  M=GWKG  N=TOTAL GW
 *
 * Single-order export: supplier header (rows 1-4, B:N merged) + column headers + items
 * Container export: column headers once, then per order: ## row + items
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Shared\Drawing as SharedDrawing;

class OrderExcelService
{
    private string $backendDir;
    private const LAST_COL   = 'N';
    private const HEADER_MERGE = 'B%d:N%d'; // sprintf pattern
    private const PHOTO_COLUMN_WIDTH = 21;
    private const PHOTO_ROW_HEIGHT_PT = 90;

    public function __construct()
    {
        $this->backendDir = dirname(__DIR__);
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public function exportOrder(array $order, array $items, ?string $filename = null): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $this->setColumnWidths($sheet);

        $row = $this->writeSupplierHeader($sheet, $order, 1);   // rows 1-4
        $this->writeColumnHeaders($sheet, $row);                 // row 5
        $row++;
        $this->writeItems($sheet, $items, $row);

        $outName = $filename ?? ('order_' . (int) ($order['id'] ?? 0) . '_goods_details.xlsx');
        $this->outputXlsx($spreadsheet, $outName);
    }

    public function exportOrders(array $ordersWithItems, string $filename = 'container_orders.xlsx'): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $this->setColumnWidths($sheet);

        // Same MASTERTOOLS header as single-order export (rows 1–4)
        $firstOrder = ($ordersWithItems[0] ?? [])['order'] ?? [];
        $row = $this->writeSupplierHeader($sheet, $firstOrder, 1);
        $this->writeColumnHeaders($sheet, $row);
        $row++;

        foreach ($ordersWithItems as $data) {
            $order = $data['order'];
            $items = $data['items'];
            $customerDisplay = self::formatCustomerDisplay($order, $items);

            // Separator: ## | customer name | customer phone
            $sheet->setCellValue('A' . $row, '##');
            $sheet->setCellValue('B' . $row, $customerDisplay);
            $sheet->setCellValue('C' . $row, $order['customer_phone'] ?? '');
            $sheet->getStyle('A' . $row . ':C' . $row)->applyFromArray([
                'font'      => ['name' => 'Arial', 'size' => 11, 'bold' => true],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER,
                ],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(22);
            $row++;

            $row = $this->writeItems($sheet, $items, $row);
            $row++; // blank spacer row
        }

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

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function setColumnWidths($sheet): void
    {
        $widths = [
            'A' =>  9,
            'B' => 25.71,   // PHOTO
            'C' => 20.14,   // ITEM NO
            'D' => 20.14,   // SUPPLIER
            'E' => 52.14,   // DESCRIPTION
            'F' => 15.29,   // TOTAL CTNS
            'G' => 15.29,   // QTY/CTN
            'H' => 15.29,   // TOTAL QTY
            'I' => 15.29,   // UNIT PRICE
            'J' => 15.29,   // TOTAL AMOUNT
            'K' => 15.29,   // CBM
            'L' => 15.29,   // TOTAL CBM
            'M' =>  9,      // GWKG
            'N' => 15.29,   // TOTAL GW
        ];
        foreach ($widths as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        $sheet->getColumnDimension('B')->setWidth(self::PHOTO_COLUMN_WIDTH);
    }

    private function writeSupplierHeader($sheet, array $order, int $startRow): int
    {
        // Fixed header from Template.xlsx — matches the exact company header text
        $headerRows = [
            'MASTERTOOLS COMPANY LIMITED',
            '        ADDRESS: CHINA-ZHEJIANG PROVINCE-YIWU CITY-JIANDONG STREET-WUYUE SQUARE -MANSION NO.1 -25th FLOOR-2515',
            '        TEL: 0579-85178151/85178152        FAX: 0579-85177247',
            'GOOD DETAILS',
        ];

        foreach ($headerRows as $i => $text) {
            $r = $startRow + $i;
            $sheet->setCellValue('B' . $r, $text);
            $sheet->mergeCells(sprintf(self::HEADER_MERGE, $r, $r));
            $sheet->getStyle(sprintf(self::HEADER_MERGE, $r, $r))->applyFromArray([
                'font'      => ['name' => 'Calibri', 'size' => 16, 'bold' => true, 'color' => ['rgb' => '1F4E79']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFFFF']],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER,
                    'wrapText'   => false,
                ],
                'borders'   => [
                    'outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BFBFBF']],
                ],
            ]);
            $sheet->getRowDimension($r)->setRowHeight(39.95);
        }

        return $startRow + 4;
    }

    private function writeColumnHeaders($sheet, int $row): void
    {
        $headers = [
            'B' => ['label' => 'PHOTO',        'font' => 'Calibri', 'size' => 12],
            'C' => ['label' => 'ITEM NO',       'font' => 'Arial',   'size' => 11],
            'D' => ['label' => 'SUPPLIER',      'font' => 'Arial',   'size' => 11],
            'E' => ['label' => 'DESCRIPTION',   'font' => 'Arial',   'size' => 11],
            'F' => ['label' => 'TOTAL CTNS',    'font' => 'Arial',   'size' => 11],
            'G' => ['label' => 'QTY/CTN',       'font' => 'Arial',   'size' => 11],
            'H' => ['label' => 'TOTAL QTY',     'font' => 'Arial',   'size' => 11],
            'I' => ['label' => 'UNIT PRICE',    'font' => 'Arial',   'size' => 11],
            'J' => ['label' => 'TOTAL AMOUNT',  'font' => 'Arial',   'size' => 11],
            'K' => ['label' => 'CBM',           'font' => 'Arial',   'size' => 11],
            'L' => ['label' => 'TOTAL CBM',     'font' => 'Arial',   'size' => 11],
            'M' => ['label' => 'GWKG',          'font' => 'Arial',   'size' => 11],
            'N' => ['label' => 'TOTAL GW',      'font' => 'Arial',   'size' => 11],
        ];

        foreach ($headers as $col => $h) {
            $sheet->setCellValue($col . $row, $h['label']);
            $sheet->getStyle($col . $row)->applyFromArray([
                'font'      => ['name' => $h['font'], 'size' => $h['size'], 'bold' => true, 'color' => ['rgb' => '1F4E79']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFFFF']],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER,
                    'wrapText'   => true,
                ],
                'borders'   => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BFBFBF']],
                ],
            ]);
        }
        $sheet->getRowDimension($row)->setRowHeight(54.75);
    }

    private function writeItems($sheet, array $items, int $startRow): int
    {
        $row = $startRow;

        foreach ($items as $it) {
            $imagePaths = $it['image_paths'] ?? [];
            if (is_string($imagePaths)) $imagePaths = json_decode($imagePaths, true) ?: [];

            // Fallback: item_no → shipping_code
            $itemNo     = (string) ($it['item_no'] ?? '');
            if ($itemNo === '') $itemNo = (string) ($it['shipping_code'] ?? '');

            $desc       = (string) ($it['description_en'] ?? $it['description_cn'] ?? '');
            $cartons    = $it['cartons'] ?? '';
            $qtyCtn     = $it['qty_per_carton'] ?? '';
            $unitPrice  = $it['sell_price'] ?? $it['unit_price'] ?? '';
            $scope      = strtolower(trim((string) ($it['product_dimensions_scope'] ?? $it['dimensions_scope'] ?? 'piece')));
            $cartonsNum = (float) ($it['cartons'] ?? 0);
            $qtyPerCtn  = (float) ($it['qty_per_carton'] ?? 0);
            $qtyNum     = ($cartonsNum > 0 && $qtyPerCtn > 0)
                ? $cartonsNum * $qtyPerCtn
                : (float) ($it['quantity'] ?? 0);
            $denom      = ($scope === 'carton' && $cartonsNum > 0)
                ? $cartonsNum
                : ($qtyNum > 0 ? $qtyNum : 0);
            $cbmPer     = ($it['declared_cbm'] && $denom > 0)
                ? round((float) $it['declared_cbm'] / $denom, 6)
                : '';
            $gwKg       = ($it['declared_weight'] && $denom > 0)
                ? round((float) $it['declared_weight'] / $denom, 4)
                : '';

            // Calculated columns use Excel formulas so Lebanon-side edits auto-update totals:
            //   H (TOTAL QTY)    = TOTAL CTNS (F) × QTY/CTN (G)
            //   J (TOTAL AMOUNT) = TOTAL QTY (H)  × UNIT PRICE (I)
            //   L (TOTAL CBM)    = per-unit (K) × multiplier (F for carton scope, H for piece scope)
            //   N (TOTAL GW)     = per-unit (M) × multiplier (F for carton scope, H for piece scope)
            $multCol = ($scope === 'carton') ? 'F' : 'H';
            $data = [
                'C' => $itemNo,
                'D' => $it['supplier_name'] ?? '',
                'E' => $desc,
                'F' => $cartons,
                'G' => $qtyCtn,
                'H' => "=F{$row}*G{$row}",
                'I' => $unitPrice,
                'J' => "=H{$row}*I{$row}",
                'K' => $cbmPer,
                'L' => "=K{$row}*{$multCol}{$row}",
                'M' => $gwKg,
                'N' => "={$multCol}{$row}*M{$row}",
            ];

            foreach ($data as $col => $val) {
                $sheet->setCellValue($col . $row, $val);
                $sheet->getStyle($col . $row)->applyFromArray([
                    'font'      => ['name' => 'Arial', 'size' => 11],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFFFF']],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER,
                        'wrapText'   => true,
                    ],
                    'borders'   => [
                        'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BFBFBF']],
                    ],
                ]);
            }

            // Image in column B
            $hasImage = false;
            $sheet->getRowDimension($row)->setRowHeight(self::PHOTO_ROW_HEIGHT_PT);
            if (!empty($imagePaths) && is_array($imagePaths)) {
                $imgPath = $this->backendDir . '/' . ($imagePaths[0] ?? '');
                if (file_exists($imgPath) && is_readable($imgPath)) {
                    try {
                        $defaultFont = $sheet->getParent()->getDefaultStyle()->getFont();
                        $imageWidthPx = SharedDrawing::cellDimensionToPixels(
                            $sheet->getColumnDimension('B')->getWidth(),
                            $defaultFont
                        );
                        $imageHeightPx = SharedDrawing::pointsToPixels(self::PHOTO_ROW_HEIGHT_PT);

                        $drawing = new Drawing();
                        $drawing->setPath($imgPath);
                        $drawing->setCoordinates('B' . $row);
                        $drawing->setResizeProportional(false);
                        $drawing->setWidth($imageWidthPx);
                        $drawing->setHeight($imageHeightPx);
                        $drawing->setWorksheet($sheet);
                        $hasImage = true;
                    } catch (Throwable $e) {
                        // fall through to text fallback
                    }
                }
            }
            // Always apply border + style to the PHOTO cell (B)
            $sheet->getStyle('B' . $row)->applyFromArray([
                'font'      => ['name' => 'Arial', 'size' => 11],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFFFF']],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER,
                ],
                'borders'   => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BFBFBF']],
                ],
            ]);
            if (!$hasImage && !empty($imagePaths)) {
                $sheet->setCellValue('B' . $row, count($imagePaths) . ' photo(s)');
            }

            $row++;
        }

        return $row;
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
            'font' => ['name' => 'Calibri', 'size' => 14, 'bold' => true, 'color' => ['rgb' => '1F4E79']],
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
                'font' => ['name' => 'Arial', 'size' => 11, 'bold' => true, 'color' => ['rgb' => '1F4E79']],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF3FF']],
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
