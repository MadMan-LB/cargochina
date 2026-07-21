<?php

require_once __DIR__ . '/OrderExcelService.php';

final class OrderBulkExcelService
{
    public function output(array $entries, string $prefix = 'orders'): void
    {
        if (!$entries) {
            throw new InvalidArgumentException('No downloadable records were selected.');
        }

        $stamp = date('Ymd_His');
        $service = new OrderExcelService();
        if (count($entries) === 1) {
            $entry = reset($entries);
            $filename = $this->safeFilename((string) ($entry['filename'] ?? ($prefix . '_' . $stamp . '.xlsx')));
            $service->exportOrder((array) ($entry['order'] ?? []), (array) ($entry['items'] ?? []), $filename);
        }

        $service->exportSelectedOrders(
            array_values($entries),
            $this->safeFilename($prefix . '_' . $stamp . '.xlsx')
        );
    }

    private function safeFilename(string $filename): string
    {
        $filename = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', trim($filename)) ?: 'download';
        $filename = trim($filename, '._-') ?: 'download';
        if (!str_ends_with(strtolower($filename), '.xlsx')) $filename .= '.xlsx';
        return substr($filename, 0, 180);
    }
}
