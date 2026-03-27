<?php

function clmsDownloadsProjectRoot(): string
{
    return dirname(__DIR__);
}

function clmsDownloadRoleAllowed(array $roles, array $entryRoles): bool
{
    if (!$entryRoles) {
        return true;
    }

    return !empty(array_intersect($roles, $entryRoles));
}

function clmsDownloadHumanSize(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $size = max(0, $bytes);
    $unitIndex = 0;

    while ($size >= 1024 && $unitIndex < count($units) - 1) {
        $size /= 1024;
        $unitIndex++;
    }

    $precision = $unitIndex === 0 ? 0 : ($size >= 10 ? 1 : 2);
    return number_format($size, $precision) . ' ' . $units[$unitIndex];
}

function clmsDownloadRegistry(): array
{
    $root = clmsDownloadsProjectRoot();

    return [
        [
            'key' => 'orders-and-containers',
            'title' => 'Orders & Container Templates',
            'description' => 'Reference layouts, ready-to-download example workbooks, and Excel export entry points for orders and containers.',
            'entries' => [
                [
                    'slug' => 'order-goods-template-xlsx',
                    'mode' => 'file',
                    'title' => 'Order Goods Details Template',
                    'description' => 'Verified Excel reference layout used by the order and container goods-details exports.',
                    'file_type' => 'XLSX',
                    'path' => $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Template.xlsx',
                    'download_name' => 'order_goods_details_template.xlsx',
                    'roles' => ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'ContainersStaff', 'SuperAdmin'],
                ],
                [
                    'slug' => 'example-order-export-xlsx',
                    'mode' => 'generated',
                    'title' => 'Example Order Export',
                    'description' => 'Download a sample order workbook with 10 dummy items, 8 real product photos from uploads, and 3 supplier groups.',
                    'file_type' => 'XLSX',
                    'download_name' => 'example_order_export.xlsx',
                    'roles' => ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'ContainersStaff', 'SuperAdmin'],
                ],
                [
                    'slug' => 'example-container-export-xlsx',
                    'mode' => 'generated',
                    'title' => 'Example Container Export',
                    'description' => 'Download a sample multi-order container workbook showing the same 10 dummy items grouped across 3 suppliers.',
                    'file_type' => 'XLSX',
                    'download_name' => 'example_container_orders_export.xlsx',
                    'roles' => ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'ContainersStaff', 'SuperAdmin'],
                ],
                [
                    'slug' => 'orders-record-export',
                    'mode' => 'module',
                    'title' => 'Single Order Export',
                    'description' => 'Generated from a specific order row using the existing /orders/{id}/export flow in Excel format.',
                    'file_type' => 'XLSX',
                    'module_path' => 'orders.php',
                    'action_label' => 'Open Orders',
                    'roles' => ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'ContainersStaff', 'SuperAdmin'],
                ],
                [
                    'slug' => 'containers-record-export',
                    'mode' => 'module',
                    'title' => 'Container Orders Export',
                    'description' => 'Generated from a specific container using the existing /containers/{id}/export flow in Excel format.',
                    'file_type' => 'XLSX',
                    'module_path' => 'containers.php',
                    'action_label' => 'Open Containers',
                    'roles' => ['ChinaAdmin', 'LebanonAdmin', 'ContainersStaff', 'SuperAdmin'],
                ],
            ],
        ],
        [
            'key' => 'draft-orders',
            'title' => 'Draft Order Exports',
            'description' => 'Draft-order Excel exports, including a ready-made example workbook and the live draft export flow.',
            'entries' => [
                [
                    'slug' => 'example-draft-order-export-xlsx',
                    'mode' => 'generated',
                    'title' => 'Example Draft Order Export',
                    'description' => 'Download a sample Draft an Order workbook with 10 dummy items, 8 photos, and 3 suppliers.',
                    'file_type' => 'XLSX',
                    'download_name' => 'example_draft_order_export.xlsx',
                    'roles' => ['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin'],
                ],
                [
                    'slug' => 'draft-order-export',
                    'mode' => 'module',
                    'title' => 'Draft Order Export',
                    'description' => 'Generated from a specific draft order using the existing /draft-orders/{id}/export flow in Excel format.',
                    'file_type' => 'XLSX',
                    'module_path' => 'procurement_drafts.php',
                    'action_label' => 'Open Draft an Order',
                    'roles' => ['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin'],
                ],
            ],
        ],
        [
            'key' => 'warehouse-and-receiving',
            'title' => 'Warehouse / Receiving Exports',
            'description' => 'Warehouse and receiving Excel exports, with ready-made example files plus the live module entry points.',
            'entries' => [
                [
                    'slug' => 'example-receiving-queue-export-xlsx',
                    'mode' => 'generated',
                    'title' => 'Example Receiving Queue Export',
                    'description' => 'Download a sample receiving queue workbook built from 10 dummy item lines and 3 suppliers.',
                    'file_type' => 'XLSX',
                    'download_name' => 'example_receiving_queue_export.xlsx',
                    'roles' => ['WarehouseStaff', 'SuperAdmin'],
                ],
                [
                    'slug' => 'example-orders-list-export-xlsx',
                    'mode' => 'generated',
                    'title' => 'Example Orders List Export',
                    'description' => 'Download a sample orders-list workbook using the same dummy data in a summary layout.',
                    'file_type' => 'XLSX',
                    'download_name' => 'example_orders_list_export.xlsx',
                    'roles' => ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'ContainersStaff', 'SuperAdmin'],
                ],
                [
                    'slug' => 'receiving-queue-export',
                    'mode' => 'module',
                    'title' => 'Receiving Queue Export',
                    'description' => 'Generated in the Warehouse Receiving queue from the currently visible filtered orders in Excel format.',
                    'file_type' => 'XLSX',
                    'module_path' => 'receiving.php',
                    'action_label' => 'Open Receiving',
                    'roles' => ['WarehouseStaff', 'SuperAdmin'],
                ],
                [
                    'slug' => 'orders-list-export',
                    'mode' => 'module',
                    'title' => 'Orders List Export',
                    'description' => 'Generated from the Orders list using the current search and status filters in Excel format.',
                    'file_type' => 'XLSX',
                    'module_path' => 'orders.php',
                    'action_label' => 'Open Orders',
                    'roles' => ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'ContainersStaff', 'SuperAdmin'],
                ],
            ],
        ],
        [
            'key' => 'hs-code-reference',
            'title' => 'HS Code & Reference Files',
            'description' => 'Verified Excel customs-tariff reference files already present in the project and used by the HS code catalog import flow.',
            'entries' => [
                [
                    'slug' => 'lebanon-customs-tariffs-xlsx',
                    'mode' => 'file',
                    'title' => 'Lebanon Customs Tariffs Workbook',
                    'description' => 'Spreadsheet copy of the Lebanon tariff catalog stored in the hs codes folder.',
                    'file_type' => 'XLSX',
                    'path' => $root . DIRECTORY_SEPARATOR . 'hs codes' . DIRECTORY_SEPARATOR . 'lebanon_customs_tariffs.xlsx',
                    'download_name' => 'lebanon_customs_tariffs.xlsx',
                    'roles' => ['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'SuperAdmin'],
                ],
            ],
        ],
    ];
}

function clmsVisibleDownloadsCatalog(array $roles): array
{
    $sections = [];

    foreach (clmsDownloadRegistry() as $section) {
        $entries = [];
        foreach ($section['entries'] as $entry) {
            if (!clmsDownloadRoleAllowed($roles, $entry['roles'] ?? [])) {
                continue;
            }

            if (($entry['mode'] ?? 'file') === 'file') {
                $path = $entry['path'] ?? '';
                if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path)) {
                    continue;
                }

                $entry['size_bytes'] = filesize($path) ?: 0;
                $entry['size_label'] = clmsDownloadHumanSize((int) $entry['size_bytes']);
                $entry['relative_path'] = str_replace('\\', '/', ltrim(str_replace(clmsDownloadsProjectRoot(), '', $path), '\\/'));
            }

            $entries[] = $entry;
        }

        if ($entries) {
            $section['entries'] = $entries;
            $sections[] = $section;
        }
    }

    return $sections;
}

function clmsFindDownloadEntry(string $slug, array $roles): ?array
{
    foreach (clmsVisibleDownloadsCatalog($roles) as $section) {
        foreach ($section['entries'] as $entry) {
            if (($entry['slug'] ?? '') === $slug) {
                return $entry;
            }
        }
    }

    return null;
}
