<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Storage\Contract\StorageExportRepository;
use Formvex\Spoke\Domain\Storage\StorageExport;
use PDO;
use RuntimeException;
use Throwable;

final class PdoStorageExportRepository implements StorageExportRepository
{
    public function create(PrivateStoragePaths $paths, StorageExport $export, string $filterSummary): void
    {
        $connection = $this->connection($paths);
        try {
            $connection->beginTransaction();
            $statement = $connection->prepare(
                'INSERT INTO storage_exports (public_id, row_count, file_size_bytes, filter_summary, created_at, expires_at, status) VALUES (:public_id, :row_count, :file_size_bytes, :filter_summary, :created_at, :expires_at, :status)',
            );
            $statement->execute([
                'public_id' => $export->publicId,
                'row_count' => $export->rowCount,
                'file_size_bytes' => $export->fileSizeBytes,
                'filter_summary' => $filterSummary,
                'created_at' => $this->formatTimestamp($export->createdAt),
                'expires_at' => $this->formatTimestamp($export->expiresAt),
                'status' => 'available',
            ]);
            $audit = $connection->prepare(
                'INSERT INTO audit_events (event_name, outcome, occurred_at, resource_type, resource_public_id) VALUES (:event_name, :outcome, :occurred_at, :resource_type, :resource_public_id)',
            );
            $audit->execute([
                'event_name' => 'spoke.storage.export_created',
                'outcome' => 'success',
                'occurred_at' => $this->formatTimestamp($export->createdAt),
                'resource_type' => 'storage_export',
                'resource_public_id' => $export->publicId,
            ]);
            $connection->commit();
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw new RuntimeException('The export record and audit event could not be saved.', 0, $failure);
        }
    }

    public function hasActive(PrivateStoragePaths $paths, DateTimeImmutable $now): bool
    {
        $connection = $this->connection($paths);
        if (!$this->hasTable($connection)) {
            return false;
        }
        $statement = $connection->prepare("SELECT 1 FROM storage_exports WHERE status IN ('available', 'downloaded') AND expires_at > :now LIMIT 1");
        $statement->execute(['now' => $this->formatTimestamp($now)]);

        return $statement->fetchColumn() !== false;
    }

    public function find(PrivateStoragePaths $paths, string $publicId): ?StorageExport
    {
        $connection = $this->connection($paths);
        if (!$this->hasTable($connection)) {
            return null;
        }
        $statement = $connection->prepare('SELECT public_id, row_count, file_size_bytes, created_at, expires_at, downloaded_at, status FROM storage_exports WHERE public_id = :public_id LIMIT 1');
        $statement->execute(['public_id' => $publicId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $row = $this->normalizeRow($row);

        return new StorageExport(
            $this->string($row, 'public_id'),
            $this->integer($row, 'row_count'),
            $this->integer($row, 'file_size_bytes'),
            $this->timestamp($row, 'created_at'),
            $this->timestamp($row, 'expires_at'),
            $this->nullableTimestamp($row, 'downloaded_at'),
            $this->string($row, 'status'),
        );
    }

    public function markDownloaded(PrivateStoragePaths $paths, string $publicId, DateTimeImmutable $now): void
    {
        $connection = $this->connection($paths);
        try {
            $connection->beginTransaction();
            $statement = $connection->prepare("UPDATE storage_exports SET downloaded_at = :downloaded_at, status = 'downloaded' WHERE public_id = :public_id AND status IN ('available', 'downloaded') AND expires_at > :now");
            $statement->execute([
                'downloaded_at' => $this->formatTimestamp($now),
                'public_id' => $publicId,
                'now' => $this->formatTimestamp($now),
            ]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('The export is no longer available.');
            }
            $audit = $connection->prepare(
                'INSERT INTO audit_events (event_name, outcome, occurred_at, resource_type, resource_public_id) VALUES (:event_name, :outcome, :occurred_at, :resource_type, :resource_public_id)',
            );
            $audit->execute([
                'event_name' => 'spoke.storage.export_downloaded',
                'outcome' => 'success',
                'occurred_at' => $this->formatTimestamp($now),
                'resource_type' => 'storage_export',
                'resource_public_id' => $publicId,
            ]);
            $connection->commit();
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw new RuntimeException('The export download could not be recorded.', 0, $failure);
        }
    }

    public function expire(PrivateStoragePaths $paths, DateTimeImmutable $now): int
    {
        $connection = $this->connection($paths);
        if (!$this->hasTable($connection)) {
            return 0;
        }
        try {
            $statement = $connection->prepare("SELECT public_id FROM storage_exports WHERE expires_at <= :now AND status <> 'expired'");
            $statement->execute(['now' => $this->formatTimestamp($now)]);
            $ids = array_values(array_filter($statement->fetchAll(PDO::FETCH_COLUMN), static fn (mixed $id): bool => is_string($id)));
            $update = $connection->prepare("UPDATE storage_exports SET status = 'expired' WHERE public_id = :public_id");
            foreach ($ids as $id) {
                $update->execute(['public_id' => $id]);
                $file = $paths->exports . DIRECTORY_SEPARATOR . $id . '.csv';
                if (is_file($file) && !unlink($file)) {
                    throw new RuntimeException('The expired export file could not be removed.');
                }
            }

            return count($ids);
        } catch (Throwable $failure) {
            throw new RuntimeException('Expired export cleanup could not complete.', 0, $failure);
        }
    }

    private function connection(PrivateStoragePaths $paths): PDO
    {
        $connection = new PDO('sqlite:' . $paths->databaseFile(), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $connection->exec('PRAGMA foreign_keys = ON');

        return $connection;
    }

    /**
     * @param array<mixed, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        return is_string($row[$key] ?? null) ? $row[$key] : '';
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        return is_int($row[$key] ?? null) || is_string($row[$key] ?? null) || is_float($row[$key] ?? null) ? (int) $row[$key] : 0;
    }

    /** @param array<string, mixed> $row */
    private function timestamp(array $row, string $key): DateTimeImmutable
    {
        return new DateTimeImmutable($this->string($row, $key), new DateTimeZone('UTC'));
    }

    /** @param array<string, mixed> $row */
    private function nullableTimestamp(array $row, string $key): ?DateTimeImmutable
    {
        return $this->string($row, $key) === '' ? null : $this->timestamp($row, $key);
    }

    private function formatTimestamp(DateTimeImmutable $timestamp): string
    {
        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');
    }

    private function hasTable(PDO $connection): bool
    {
        $statement = $connection->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'storage_exports' LIMIT 1");

        return $statement !== false && $statement->fetchColumn() !== false;
    }
}
