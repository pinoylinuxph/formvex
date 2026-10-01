<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Filesystem;

use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use RuntimeException;

final class BoundedOperationalLogWriter
{
    public const MAX_BYTES_PER_FILE = 10485760;

    public const MAX_FILES_PER_CATEGORY = 5;

    public function append(PrivateStoragePaths $paths, string $category, string $event): void
    {
        if (preg_match('/\A[a-z0-9][a-z0-9_.-]{0,63}\z/', $category) !== 1 || preg_match('/\A[a-zA-Z0-9_.:-]{1,128}\z/', $event) !== 1) {
            throw new RuntimeException('The operational log category or event is invalid.');
        }

        if (!is_dir($paths->logs) && !mkdir($paths->logs, 0o700, true) && !is_dir($paths->logs)) {
            throw new RuntimeException('The operational log directory could not be created.');
        }

        $path = $paths->logs . DIRECTORY_SEPARATOR . $category . '.log';
        $lockPath = $path . '.lock';
        $line = gmdate('Y-m-d\TH:i:s\Z') . ' ' . $event . PHP_EOL;
        if (strlen($line) > self::MAX_BYTES_PER_FILE) {
            throw new RuntimeException('The operational log event is too large.');
        }

        $handle = fopen($lockPath, 'c+');
        if ($handle === false) {
            throw new RuntimeException('The operational log could not be opened.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('The operational log could not be written.');
            }
            $size = is_file($path) ? filesize($path) : 0;
            if ($size === false) {
                throw new RuntimeException('The operational log size could not be measured.');
            }
            if ($size + strlen($line) > self::MAX_BYTES_PER_FILE) {
                $this->rotate($path);
            }
            if (file_put_contents($path, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
                throw new RuntimeException('The operational log could not be written.');
            }
            chmod($path, 0o600);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
            @unlink($lockPath);
        }
    }

    private function rotate(string $path): void
    {
        for ($index = self::MAX_FILES_PER_CATEGORY - 1; $index >= 1; $index--) {
            $source = $path . '.' . $index;
            $target = $path . '.' . ($index + 1);
            if (is_file($source) && !rename($source, $target)) {
                throw new RuntimeException('The operational log could not be rotated.');
            }
        }

        if (is_file($path) && !rename($path, $path . '.1')) {
            throw new RuntimeException('The operational log could not be rotated.');
        }
    }
}
