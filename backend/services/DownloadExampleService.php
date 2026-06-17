<?php

require_once dirname(__DIR__) . '/services/OrderExcelService.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class DownloadExampleService
{
    private string $projectRoot;
    private OrderExcelService $excel;

    public function __construct()
    {
        $this->projectRoot = dirname(__DIR__, 2);
        $this->excel = new OrderExcelService();
    }

    public function outputBySlug(string $slug): void
    {
        switch ($slug) {
            case 'example-order-export-xlsx':
                $this->outputOrderExample();
                return;
            case 'example-container-export-xlsx':
                $this->outputContainerExample();
                return;
            case 'example-draft-order-export-xlsx':
                $this->outputDraftExample();
                return;
            case 'example-procurement-template-xlsx':
                $this->outputProcurementTemplateExample();
                return;
            case 'example-orders-list-export-xlsx':
                $this->outputOrdersListExample();
                return;
            case 'example-receiving-queue-export-xlsx':
                $this->outputReceivingQueueExample();
                return;
        }

        throw new RuntimeException('Unknown example export');
    }

    private function outputOrderExample(): void
    {
        [$order, $items] = $this->buildExampleSingleOrder();
        $this->excel->exportOrder($order, $items, 'example_order_export.xlsx');
    }

    private function outputDraftExample(): void
    {
        [$order, $items] = $this->buildExampleSingleOrder();
        $order['id'] = 9001;
        $order['order_type'] = 'draft_procurement';
        $this->excel->exportOrder($order, $items, 'example_draft_order_export.xlsx');
    }

    private function outputContainerExample(): void
    {
        $this->excel->exportOrders(
            $this->buildExampleOrdersWithItems(),
            'example_container_orders_export.xlsx',
            [
                'container' => [
                    'id' => 1,
                    'code' => 'EXAMPLE-CTR-001',
                ],
                'expenses' => $this->buildExampleContainerExpenses(),
            ]
        );
    }

    private function outputOrdersListExample(): void
    {
        $this->excel->exportOrdersListSummary(
            $this->buildExampleOrdersListRows(),
            'example_orders_list_export.xlsx'
        );
    }

    private function outputReceivingQueueExample(): void
    {
        $this->excel->exportReceivingQueueSummary(
            $this->buildExampleReceivingRows(),
            'example_receiving_queue_export.xlsx'
        );
    }

    private function outputProcurementTemplateExample(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Procurement Import');

        $sheet->setCellValue('A1', 'Example Procurement Template');
        $sheet->mergeCells('A1:S1');
        $sheet->getStyle('A1:S1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '1F4E79']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF3FF']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);

        $metadata = [
            ['Customer', 'Example Retail Group'],
            ['Destination Country', 'Lebanon (LB)'],
            ['Expected Ready', '2026-07-15'],
            ['Currency', 'RMB'],
        ];
        $row = 3;
        foreach ($metadata as $entry) {
            $sheet->fromArray($entry, null, 'A' . $row);
            $sheet->getStyle('A' . $row)->getFont()->setBold(true);
            $row++;
        }

        $row++;
        $headers = [
            'Item No',
            'English Item Name',
            'Chinese Item Name',
            'SKU / Item Code',
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
            'HS Code',
            'Notes / Description',
            'Custom Design',
        ];
        $sheet->fromArray($headers, null, 'A' . $row);
        $headerRow = $row;
        $row++;

        $sampleRows = [
            ['EXAMPLE-1-1', 'Travel Mug', '旅行保温杯', 'SKU-MUG-001', 432, 'pieces', 24, 18, 12.50, 15.00, 6480, 0.0048, 2.0736, 0.31, 133.92, 'Jinhua Gift Creations', '9617.00', 'Stainless steel giftware; keep logo area blank.', 'No'],
            ['EXAMPLE-1-2', 'Storage Jar Set', '玻璃储物罐套装', 'SKU-JAR-002', 160, 'pieces', 16, 10, 21.80, 25.00, 4000, 0.0090, 1.44, 0.52, 83.20, 'Jinhua Gift Creations', '7013.49', 'Packed as 3-piece kitchen set.', 'No'],
            ['EXAMPLE-2-1', 'Pet Bowl', '宠物碗', 'SKU-PET-010', 420, 'pieces', 30, 14, 7.20, 9.50, 3990, 0.0039, 1.638, 0.22, 92.40, 'Qingdao Pet Utility', '3924.90', 'Blue and grey mixed colors.', 'No'],
            ['EXAMPLE-2-2', 'Carrier Pad', '宠物运输垫', 'SKU-PAD-011', 168, 'pieces', 24, 7, 9.80, 12.00, 2016, 0.0068, 1.1424, 0.36, 60.48, 'Qingdao Pet Utility', '6307.90', 'Use waterproof fabric; verify carton label in Chinese and English.', 'Yes'],
        ];
        foreach ($sampleRows as $sample) {
            $sheet->fromArray($sample, null, 'A' . $row);
            $row++;
        }

        $lastRow = $row - 1;
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$lastRow}")->applyFromArray([
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D7E3F4']],
            ],
            'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
        ]);
        $sheet->getStyle("A" . ($headerRow + 1) . ":D{$lastRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->getStyle("P" . ($headerRow + 1) . ":Q{$lastRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->freezePane('A' . ($headerRow + 1));
        $sheet->setAutoFilter("A{$headerRow}:{$lastColumn}{$lastRow}");

        $widths = [16, 24, 22, 18, 12, 10, 15, 10, 14, 14, 14, 12, 12, 13, 13, 24, 12, 36, 14];
        foreach ($widths as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }

        $writer = new Xlsx($spreadsheet);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="example_procurement_template.xlsx"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        $writer->save('php://output');
        exit;
    }

    private function buildExampleSingleOrder(): array
    {
        $items = $this->buildExampleItems();
        $order = [
            'id' => 9000,
            'customer_name' => 'Example Retail Group',
            'customer_phone' => '+961-70-000100',
            'supplier_name' => 'Multiple suppliers',
            'expected_ready_date' => '2026-04-15',
            'status' => 'Approved',
            'currency' => 'USD',
            'order_type' => 'standard',
        ];

        return [$order, $items];
    }

    private function buildExampleOrdersWithItems(): array
    {
        $items = $this->buildExampleItems();

        return [
            [
                'order' => [
                    'id' => 9101,
                    'customer_id' => 101,
                    'customer_name' => 'Example Retail Group',
                    'default_shipping_code' => 'EXA-RETAIL',
                    'customer_phone' => '+961-70-000100',
                    'supplier_name' => 'Jinhua Gift Creations',
                    'supplier_phone' => '+86-579-85178151',
                    'supplier_payment_links' => json_encode([
                        ['label' => 'Bank', 'account_number' => 'CN-ACC-001-8891'],
                    ], JSON_UNESCAPED_SLASHES),
                    'expected_ready_date' => '2026-04-15',
                    'status' => 'ReadyForConsolidation',
                    'currency' => 'USD',
                ],
                'items' => array_slice($items, 0, 4),
            ],
            [
                'order' => [
                    'id' => 9102,
                    'customer_id' => 102,
                    'customer_name' => 'Family Mart Lebanon',
                    'default_shipping_code' => 'FMLB',
                    'customer_phone' => '+961-71-440220',
                    'supplier_name' => 'Qingdao Pet Utility',
                    'supplier_phone' => '+86-532-77001234',
                    'supplier_payment_links' => json_encode([
                        ['label' => 'Alipay', 'account_number' => 'ALI-QD-557700'],
                    ], JSON_UNESCAPED_SLASHES),
                    'expected_ready_date' => '2026-04-16',
                    'status' => 'ReadyForConsolidation',
                    'currency' => 'USD',
                ],
                'items' => array_slice($items, 4, 3),
            ],
            [
                'order' => [
                    'id' => 9103,
                    'customer_id' => 103,
                    'customer_name' => 'Active Sport House',
                    'default_shipping_code' => 'ASH',
                    'customer_phone' => '+961-76-123456',
                    'supplier_name' => 'Wenzhou Stationery House',
                    'supplier_phone' => '+86-577-65008810',
                    'supplier_payment_links' => json_encode([
                        ['label' => 'WeChat', 'account_number' => 'WC-WSH-90088'],
                    ], JSON_UNESCAPED_SLASHES),
                    'expected_ready_date' => '2026-04-17',
                    'status' => 'AssignedToContainer',
                    'currency' => 'USD',
                ],
                'items' => array_slice($items, 7, 3),
            ],
        ];
    }

    private function buildExampleOrdersListRows(): array
    {
        return array_map(
            function (array $entry): array {
                $order = $entry['order'];
                $items = $entry['items'];

                return [
                    'id' => $order['id'],
                    'order_type' => 'standard',
                    'customer_name' => $order['customer_name'],
                    'default_shipping_code' => $order['default_shipping_code'] ?? '',
                    'supplier_name' => $order['supplier_name'],
                    'expected_ready_date' => $order['expected_ready_date'],
                    'status' => $order['status'],
                    'items' => $items,
                ];
            },
            $this->buildExampleOrdersWithItems()
        );
    }

    private function buildExampleReceivingRows(): array
    {
        return array_map(
            function (array $entry): array {
                $order = $entry['order'];
                $items = $entry['items'];
                $declaredCbm = 0.0;
                $declaredWeight = 0.0;
                foreach ($items as $item) {
                    $declaredCbm += (float) ($item['declared_cbm'] ?? 0);
                    $declaredWeight += (float) ($item['declared_weight'] ?? 0);
                }

                return [
                    'id' => $order['id'],
                    'customer_name' => $order['customer_name'],
                    'default_shipping_code' => $order['default_shipping_code'] ?? '',
                    'supplier_name' => $order['supplier_name'],
                    'supplier_phone' => $order['supplier_phone'] ?? '',
                    'expected_ready_date' => $order['expected_ready_date'],
                    'status' => 'Approved',
                    'declared_cbm' => $declaredCbm,
                    'declared_weight' => $declaredWeight,
                    'items' => $items,
                ];
            },
            $this->buildExampleOrdersWithItems()
        );
    }

    private function buildExampleItems(): array
    {
        $imagePool = $this->getExampleImagePool();
        $shippingCode = 'EXAMPLE';
        $rows = [
            ['supplier' => 'Jinhua Gift Creations', 'item_no' => 'EXAMPLE-1-1', 'description' => 'Travel Mug - Giftware', 'cartons' => 18, 'qty_per_carton' => 24, 'price' => 2.40, 'cbm' => 0.0048, 'weight' => 0.31],
            ['supplier' => 'Jinhua Gift Creations', 'item_no' => 'EXAMPLE-1-2', 'description' => 'Insulated Flask - Giftware', 'cartons' => 12, 'qty_per_carton' => 20, 'price' => 3.15, 'cbm' => 0.0061, 'weight' => 0.38],
            ['supplier' => 'Jinhua Gift Creations', 'item_no' => 'EXAMPLE-1-3', 'description' => 'Storage Jar Set - Home', 'cartons' => 10, 'qty_per_carton' => 16, 'price' => 4.20, 'cbm' => 0.0090, 'weight' => 0.52],
            ['supplier' => 'Jinhua Gift Creations', 'item_no' => 'EXAMPLE-1-4', 'description' => 'Picnic Plate Pack - Home', 'cartons' => 9, 'qty_per_carton' => 18, 'price' => 2.95, 'cbm' => 0.0078, 'weight' => 0.44],
            ['supplier' => 'Qingdao Pet Utility', 'item_no' => 'EXAMPLE-2-1', 'description' => 'Pet Bowl - Pet & Utility', 'cartons' => 14, 'qty_per_carton' => 30, 'price' => 1.55, 'cbm' => 0.0039, 'weight' => 0.22],
            ['supplier' => 'Qingdao Pet Utility', 'item_no' => 'EXAMPLE-2-2', 'description' => 'Carrier Pad - Pet & Utility', 'cartons' => 7, 'qty_per_carton' => 24, 'price' => 2.10, 'cbm' => 0.0068, 'weight' => 0.36],
            ['supplier' => 'Qingdao Pet Utility', 'item_no' => 'EXAMPLE-2-3', 'description' => 'Litter Scoop - Pet & Utility', 'cartons' => 8, 'qty_per_carton' => 18, 'price' => 1.85, 'cbm' => 0.0054, 'weight' => 0.29],
            ['supplier' => 'Wenzhou Stationery House', 'item_no' => 'EXAMPLE-3-1', 'description' => 'Marker Set - Office', 'cartons' => 11, 'qty_per_carton' => 20, 'price' => 2.65, 'cbm' => 0.0045, 'weight' => 0.25],
            ['supplier' => 'Wenzhou Stationery House', 'item_no' => 'EXAMPLE-3-2', 'description' => 'Letter Tray - Office', 'cartons' => 6, 'qty_per_carton' => 12, 'price' => 3.80, 'cbm' => 0.0115, 'weight' => 0.71],
            ['supplier' => 'Wenzhou Stationery House', 'item_no' => 'EXAMPLE-3-3', 'description' => 'Desk File - Office', 'cartons' => 5, 'qty_per_carton' => 14, 'price' => 3.25, 'cbm' => 0.0102, 'weight' => 0.66],
        ];

        return array_map(function (array $row, int $index) use ($imagePool, $shippingCode): array {
            $cartons = (float) $row['cartons'];
            $qtyPerCarton = (float) $row['qty_per_carton'];
            $quantity = $cartons * $qtyPerCarton;
            $unitPrice = (float) $row['price'];
            $cbmPerUnit = (float) $row['cbm'];
            $weightPerUnit = (float) $row['weight'];
            $imagePaths = [];
            if ($index < 8 && !empty($imagePool)) {
                $imagePaths[] = $imagePool[$index % count($imagePool)];
            }

            return [
                'item_no' => $row['item_no'],
                'shipping_code' => $shippingCode,
                'what_brand' => match ($row['supplier']) {
                    'Jinhua Gift Creations' => 'GiftPro',
                    'Qingdao Pet Utility' => 'PetLine',
                    'Wenzhou Stationery House' => 'OfficeMate',
                    default => '',
                },
                'copy_normal_goods' => $index % 3 === 0 ? 'Copy' : 'Normal',
                'code' => 'EX-CODE-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                'express_number' => 'EXP' . date('Y') . str_pad((string) ($index + 11), 5, '0', STR_PAD_LEFT),
                'size' => match ($index % 4) {
                    0 => '60 x 40 x 38',
                    1 => '52 x 36 x 30',
                    2 => '48 x 35 x 28',
                    default => '45 x 30 x 25',
                },
                'supplier_name' => $row['supplier'],
                'supplier_phone' => match ($row['supplier']) {
                    'Jinhua Gift Creations' => '+86-579-85178151',
                    'Qingdao Pet Utility' => '+86-532-77001234',
                    'Wenzhou Stationery House' => '+86-577-65008810',
                    default => '+86-000-000000',
                },
                'supplier_payment_links' => match ($row['supplier']) {
                    'Jinhua Gift Creations' => json_encode([['label' => 'Bank', 'account_number' => 'CN-ACC-001-8891']], JSON_UNESCAPED_SLASHES),
                    'Qingdao Pet Utility' => json_encode([['label' => 'Alipay', 'account_number' => 'ALI-QD-557700']], JSON_UNESCAPED_SLASHES),
                    'Wenzhou Stationery House' => json_encode([['label' => 'WeChat', 'account_number' => 'WC-WSH-90088']], JSON_UNESCAPED_SLASHES),
                    default => '',
                },
                'description_en' => $row['description'],
                'description_cn' => $row['description'],
                'cartons' => $cartons,
                'qty_per_carton' => $qtyPerCarton,
                'quantity' => $quantity,
                'sell_price' => $unitPrice,
                'unit_price' => $unitPrice,
                'effective_buy_price' => round($unitPrice * 0.72, 4),
                'product_buy_price' => round($unitPrice * 0.72, 4),
                'declared_cbm' => round($cbmPerUnit * $quantity, 6),
                'declared_weight' => round($weightPerUnit * $quantity, 4),
                'image_paths' => $imagePaths,
                'dimensions_scope' => 'piece',
                'product_dimensions_scope' => 'piece',
            ];
        }, $rows, array_keys($rows));
    }

    private function buildExampleContainerExpenses(): array
    {
        return [
            [
                'id' => 7001,
                'customer_id' => 101,
                'currency' => 'USD',
                'amount' => 420,
                'category_name' => 'Freight to Yiwu',
                'supplier_name' => '',
                'notes' => 'Freight to Yiwu',
            ],
            [
                'id' => 7002,
                'customer_id' => 101,
                'currency' => 'USD',
                'amount' => 50,
                'category_name' => 'Forklift unloading',
                'supplier_name' => '',
                'notes' => 'Forklift unloading 24 pallets',
            ],
            [
                'id' => 7003,
                'customer_id' => 102,
                'currency' => 'USD',
                'amount' => 80,
                'category_name' => 'Inspection',
                'supplier_name' => '',
                'notes' => 'Pet utilities inspection fee',
            ],
            [
                'id' => 7004,
                'customer_id' => 103,
                'currency' => 'USD',
                'amount' => 65,
                'category_name' => 'Loading',
                'supplier_name' => '',
                'notes' => 'Stationery loading support',
            ],
            [
                'id' => 7005,
                'currency' => 'USD',
                'amount' => 150,
                'category_name' => 'Container charge',
                'supplier_name' => '',
                'notes' => 'Container-wide handling charge',
            ],
        ];
    }

    private function getExampleImagePool(): array
    {
        $uploadDir = $this->projectRoot . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'uploads';
        if (!is_dir($uploadDir)) {
            return [];
        }

        $files = [];
        foreach (['*.jpg', '*.jpeg', '*.png'] as $pattern) {
            $matches = glob($uploadDir . DIRECTORY_SEPARATOR . $pattern) ?: [];
            foreach ($matches as $match) {
                if (is_file($match) && is_readable($match)) {
                    $files[] = 'uploads/' . basename($match);
                }
            }
        }
        $files = array_values(array_unique($files));
        sort($files);

        return array_slice($files, 0, 8);
    }
}
