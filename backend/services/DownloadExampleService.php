<?php

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class DownloadExampleService
{
    public function outputBySlug(string $slug): void
    {
        switch ($slug) {
            case 'procurement-import-template-xlsx':
            case 'example-procurement-template-xlsx':
            case 'receiving-procurement-import-template-xlsx':
                $this->outputProcurementImportTemplate();
                return;
        }

        throw new RuntimeException('Unknown template');
    }

    private function outputProcurementImportTemplate(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Procurement Import');

        $headers = [
            'Photo',
            'Item No',
            'English Item Name',
            'Chinese Item Name',
            'SKU / Item Code',
            'Brand',
            'Materials',
            'Height',
            'Width',
            'Length',
            'Express Number',
            'Quantity',
            'Unit',
            'Pieces/Carton',
            'Cartons',
            'Factory Price',
            'Customer Price',
            'Total Amount',
            'CBM/Unit',
            'Total CBM',
            'Weight/Unit',
            'Total Weight',
            'Supplier',
            'Supplier Name',
            'HS Code',
            'Notes / Description',
            'Custom Design',
        ];
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));

        $sheet->setCellValue('A1', 'Procurement Import Template');
        $sheet->mergeCells('A1:' . $lastColumn . '1');
        $sheet->getStyle('A1:' . $lastColumn . '1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '1F4E79']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF3FF']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);

        $metadata = [
            ['Customer:', ''],
            ['Destination Country:', ''],
            ['Expected Ready:', ''],
            ['Currency:', ''],
        ];
        $row = 3;
        foreach ($metadata as $entry) {
            $sheet->fromArray($entry, null, 'A' . $row);
            $sheet->getStyle('A' . $row)->getFont()->setBold(true);
            $row++;
        }

        $row++;
        $sheet->fromArray($headers, null, 'A' . $row);
        $headerRow = $row;

        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getStyle("A{$headerRow}:{$lastColumn}" . ($headerRow + 50))->applyFromArray([
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D7E3F4']],
            ],
            'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
        ]);
        $sheet->getStyle("B" . ($headerRow + 1) . ":G" . ($headerRow + 50))->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->getStyle("K" . ($headerRow + 1) . ":K" . ($headerRow + 50))->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->getStyle("W" . ($headerRow + 1) . ":Y" . ($headerRow + 50))->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->freezePane('A' . ($headerRow + 1));
        $sheet->setAutoFilter("A{$headerRow}:{$lastColumn}{$headerRow}");

        for ($bodyRow = $headerRow + 1; $bodyRow <= $headerRow + 50; $bodyRow++) {
            $sheet->getRowDimension($bodyRow)->setRowHeight(72);
        }

        $widths = [18, 16, 24, 22, 18, 16, 24, 10, 10, 10, 18, 12, 10, 15, 10, 14, 14, 14, 12, 12, 13, 13, 24, 24, 12, 36, 14];
        foreach ($widths as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }

        $writer = new Xlsx($spreadsheet);
        if (headers_sent($file, $line)) {
            throw new RuntimeException('Cannot output Excel template because headers were already sent at ' . $file . ':' . $line);
        }
        while (ob_get_level() > 0) {
            if (!@ob_end_clean()) {
                break;
            }
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="procurement_import_template.xlsx"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        $writer->save('php://output');
        exit;
    }
}
