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
        if (count($entries) === 1) {
            $entry = reset($entries);
            $filename = $this->safeFilename((string) ($entry['filename'] ?? ($prefix . '_' . $stamp . '.xlsx')), '.xlsx');
            (new OrderExcelService())->exportOrder(
                (array) ($entry['order'] ?? []),
                (array) ($entry['items'] ?? []),
                $filename
            );
        }

        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZIP downloads are not available because the PHP zip extension is disabled.');
        }

        $temporaryDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'clms_excel_' . bin2hex(random_bytes(8));
        if (!mkdir($temporaryDirectory, 0700, true) && !is_dir($temporaryDirectory)) {
            throw new RuntimeException('Could not prepare the Excel download.');
        }

        $zipPath = $temporaryDirectory . DIRECTORY_SEPARATOR . $this->safeFilename($prefix . '_' . $stamp . '.zip', '.zip');
        $createdFiles = [];
        $cleanup = static function () use (&$createdFiles, $zipPath, $temporaryDirectory): void {
            foreach ($createdFiles as $file) {
                if (is_file($file)) @unlink($file);
            }
            if (is_file($zipPath)) @unlink($zipPath);
            if (is_dir($temporaryDirectory)) @rmdir($temporaryDirectory);
        };
        register_shutdown_function($cleanup);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the Excel ZIP download.');
        }

        $usedNames = [];
        $excelService = new OrderExcelService();
        foreach ($entries as $index => $entry) {
            $baseName = $this->safeFilename(
                (string) ($entry['filename'] ?? ($prefix . '_' . ($index + 1) . '.xlsx')),
                '.xlsx'
            );
            $archiveName = $this->uniqueFilename($baseName, $usedNames);
            $filePath = $temporaryDirectory . DIRECTORY_SEPARATOR . $archiveName;
            $excelService->saveOrderXlsx(
                (array) ($entry['order'] ?? []),
                (array) ($entry['items'] ?? []),
                $filePath
            );
            $createdFiles[] = $filePath;
            if (!$zip->addFile($filePath, $archiveName)) {
                $zip->close();
                throw new RuntimeException('Could not add an Excel file to the ZIP download.');
            }
        }
        $zip->close();

        $downloadName = $this->safeFilename($prefix . '_' . $stamp . '.zip', '.zip');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . (string) filesize($zipPath));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        readfile($zipPath);
        exit;
    }

    private function safeFilename(string $filename, string $extension): string
    {
        $filename = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', trim($filename)) ?: 'download';
        $filename = trim($filename, '._-');
        if ($filename === '') $filename = 'download';
        if (!str_ends_with(strtolower($filename), strtolower($extension))) {
            $filename .= $extension;
        }
        return substr($filename, 0, 180);
    }

    private function uniqueFilename(string $filename, array &$usedNames): string
    {
        $candidate = $filename;
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $counter = 2;
        while (isset($usedNames[strtolower($candidate)])) {
            $candidate = $base . '_' . $counter . ($extension !== '' ? '.' . $extension : '');
            $counter++;
        }
        $usedNames[strtolower($candidate)] = true;
        return $candidate;
    }
}
