<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Filesystem;

use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Storage\SubmissionExportData;
use RuntimeException;
use Throwable;

final class CsvExportWriter
{
    public function write(PrivateStoragePaths $paths, string $publicId, SubmissionExportData $data): int
    {
        if (!preg_match('/\A[0-9a-fA-F-]{16,80}\z/', $publicId)) {
            throw new RuntimeException('The export identifier is invalid.');
        }

        $temporary = $paths->exports . DIRECTORY_SEPARATOR . '.export-' . $publicId . '-' . bin2hex(random_bytes(8)) . '.tmp';
        $target = $paths->exports . DIRECTORY_SEPARATOR . $publicId . '.csv';
        $handle = fopen($temporary, 'x');
        if ($handle === false) {
            throw new RuntimeException('The private export file could not be created.');
        }

        try {
            $headers = ['Accepted time', 'Form', 'Configuration version', 'Record type', 'Classification', 'Lifecycle', 'Delivery', 'Delivery outcome', 'Handled time', 'Trashed time', 'Restored time'];
            $usedHeaders = array_fill_keys($headers, true);
            foreach ($data->fieldColumns as $column) {
                $header = $column['label'];
                $suffix = 2;
                while (isset($usedHeaders[$header])) {
                    $header = $column['label'] . ' (' . $suffix++ . ')';
                }
                $usedHeaders[$header] = true;
                $headers[] = $header;
            }
            $this->row($handle, $headers);
            foreach ($data->rows as $item) {
                $values = [
                    $item['accepted_at'],
                    $item['form'],
                    $item['configuration_version'],
                    $item['record_type'],
                    $item['classification'],
                    $item['lifecycle'],
                    $item['delivery'],
                    $item['delivery_outcome'],
                    $item['handled_at'],
                    $item['trashed_at'],
                    $item['restored_at'],
                ];
                foreach ($data->fieldColumns as $column) {
                    $values[] = $item['fields'][$column['key']] ?? '';
                }
                $this->row($handle, $values);
            }
            if (!fflush($handle)) {
                throw new RuntimeException('The private export file could not be flushed.');
            }
            fclose($handle);
            $handle = null;
            chmod($temporary, 0o600);
            if (!rename($temporary, $target)) {
                throw new RuntimeException('The private export file could not be published atomically.');
            }
            $size = filesize($target);
            if ($size === false) {
                throw new RuntimeException('The published export size could not be measured.');
            }

            return $size;
        } catch (Throwable $failure) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($temporary);
            @unlink($target);
            throw $failure;
        }
    }

    /**
     * @param resource $handle
     * @param list<string> $values
     */
    private function row($handle, array $values): void
    {
        $safe = [];
        foreach ($values as $value) {
            $safe[] = $this->safeCell($value);
        }
        if (fputcsv($handle, $safe, ',', '"', '', "\r\n") === false) {
            throw new RuntimeException('The CSV row could not be written.');
        }
    }

    private function safeCell(string $value): string
    {
        return preg_match('/\A[=+\-@]/u', $value) === 1 ? "'" . $value : $value;
    }
}
