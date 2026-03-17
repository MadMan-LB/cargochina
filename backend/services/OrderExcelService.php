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
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

class OrderExcelService
{
    private string $backendDir;
    private const LAST_COL   = 'N';
    private const HEADER_MERGE = 'B%d:N%d'; // sprintf pattern

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

            // Separator: ## | customer name | customer phone
            $sheet->setCellValue('A' . $row, '##');
            $sheet->setCellValue('B' . $row, $order['customer_name'] ?? '');
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
            if (!empty($imagePaths) && is_array($imagePaths)) {
                $imgPath = $this->backendDir . '/' . ($imagePaths[0] ?? '');
                if (file_exists($imgPath) && is_readable($imgPath)) {
                    try {
                        $drawing = new Drawing();
                        $drawing->setPath($imgPath);
                        $drawing->setCoordinates('B' . $row);
                        $drawing->setOffsetX(4);
                        $drawing->setOffsetY(4);
                        $drawing->setHeight(62);
                        $drawing->setWorksheet($sheet);
                        $sheet->getRowDimension($row)->setRowHeight(54);
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

    private function outputXlsx(Spreadsheet $spreadsheet, string $filename): void
    {
        $writer = new Xlsx($spreadsheet);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $filename) . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        $writer->save('php://output');
        exit;
    }
}
