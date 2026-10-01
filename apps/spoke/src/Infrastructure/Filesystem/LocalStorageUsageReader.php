<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Filesystem;

use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Storage\Contract\StorageUsageReader;
use Formvex\Spoke\Domain\Storage\StorageSettings;
use Formvex\Spoke\Domain\Storage\StorageState;
use Formvex\Spoke\Domain\Storage\StorageUsage;
use RuntimeException;
use Throwable;

final class LocalStorageUsageReader implements StorageUsageReader
{
    public function read(PrivateStoragePaths $paths, StorageSettings $settings): StorageUsage
    {
        try {
            $breakdown = [
                'Database and SQLite journals' => $this->databaseBytes($paths->databaseFile()),
                'Operational logs' => $this->directoryBytes($paths->logs),
                'Diagnostics' => $this->directoryBytes($paths->diagnostics),
                'Active exports' => $this->exportBytes($paths->exports),
            ];
            $bytes = array_sum($breakdown);

            $physicalFreeBytes = disk_free_space($paths->applicationRoot);

            if ($physicalFreeBytes === false) {
                return StorageUsage::unavailable($settings);
            }

            $percent = $settings->allowanceBytes === 0
                ? 100
                : min(100, (int) ceil(($bytes * 100) / $settings->allowanceBytes));
            $state = $physicalFreeBytes <= 0 || $bytes >= $settings->allowanceBytes
                ? StorageState::FULL
                : ($percent >= $settings->criticalWarningPercent
                    ? StorageState::CRITICAL
                    : ($percent >= $settings->normalWarningPercent ? StorageState::WARNING : StorageState::NORMAL));

            return new StorageUsage($bytes, $settings->allowanceBytes, $percent, $state, (int) $physicalFreeBytes, $breakdown);
        } catch (Throwable) {
            return StorageUsage::unavailable($settings);
        }
    }

    private function databaseBytes(string $databaseFile): int
    {
        $bytes = $this->fileBytes($databaseFile);
        foreach ([$databaseFile . '-wal', $databaseFile . '-shm'] as $sidecar) {
            $bytes += $this->fileBytes($sidecar);
        }

        return $bytes;
    }

    private function directoryBytes(string $directory): int
    {
        if (!is_dir($directory) || is_link($directory)) {
            return 0;
        }

        $bytes = 0;
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_link($path)) {
                throw new RuntimeException('Storage symlink encountered.');
            }
            if (is_dir($path)) {
                $bytes += $this->directoryBytes($path);
            } elseif (is_file($path)) {
                $bytes += $this->fileBytes($path);
            }
        }

        return $bytes;
    }

    private function exportBytes(string $directory): int
    {
        if (!is_dir($directory) || is_link($directory)) {
            return 0;
        }

        $bytes = 0;
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_link($path)) {
                throw new RuntimeException('Storage symlink encountered.');
            }
            if (is_file($path) && str_ends_with(strtolower($entry), '.csv')) {
                $bytes += $this->fileBytes($path);
            }
        }

        return $bytes;
    }

    private function fileBytes(string $path): int
    {
        if (!is_file($path) || is_link($path)) {
            return 0;
        }

        $size = filesize($path);
        if ($size === false) {
            throw new RuntimeException('Storage file size unavailable.');
        }

        return $size;
    }
}
