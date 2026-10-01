<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Backup\BackupArchive;
use Formvex\Spoke\Domain\Backup\BackupKind;
use Formvex\Spoke\Domain\Backup\BackupState;
use Formvex\Spoke\Domain\Backup\Contract\BackupRepository;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use PDO;
use RuntimeException;
use Throwable;

final class PdoBackupRepository implements BackupRepository
{
    public function list(PrivateStoragePaths $paths): array
    {
        $connection = $this->connection($paths);
        $statement = $connection->query('SELECT * FROM backup_archives ORDER BY created_at DESC, id DESC');
        $rows = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);

        $archives = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $archives[] = $this->map($this->normalizeRow($row));
            }
        }

        return $archives;
    }

    public function find(PrivateStoragePaths $paths, string $publicId): ?BackupArchive
    {
        $statement = $this->connection($paths)->prepare('SELECT * FROM backup_archives WHERE public_id = :public_id LIMIT 1');
        $statement->execute(['public_id' => $publicId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->map($this->normalizeRow($row)) : null;
    }

    public function create(PrivateStoragePaths $paths, string $publicId, BackupKind $kind, string $storageKey, string $schemaVersion, DateTimeImmutable $now): void
    {
        $connection = $this->connection($paths);
        $statement = $connection->prepare('INSERT INTO backup_archives (public_id, kind, status, storage_key, created_at, schema_version) VALUES (:public_id, :kind, :status, :storage_key, :created_at, :schema_version)');
        $statement->execute([
            'public_id' => $publicId,
            'kind' => $kind->value,
            'status' => BackupState::CREATING->value,
            'storage_key' => $storageKey,
            'created_at' => $this->format($now),
            'schema_version' => $schemaVersion,
        ]);
        $this->audit($connection, 'spoke.backup.creation_requested', 'success', $publicId, $kind->value, $now);
    }

    public function complete(PrivateStoragePaths $paths, string $publicId, int $sizeBytes, string $sha256, DateTimeImmutable $now): void
    {
        $connection = $this->connection($paths);
        $connection->beginTransaction();
        try {
            $statement = $connection->prepare("UPDATE backup_archives SET status = 'verified', completed_at = :completed_at, size_bytes = :size_bytes, sha256 = :sha256, failure_code = NULL WHERE public_id = :public_id AND status = 'creating'");
            $statement->execute(['completed_at' => $this->format($now), 'size_bytes' => $sizeBytes, 'sha256' => $sha256, 'public_id' => $publicId]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('The backup inventory state could not be completed.');
            }
            $this->audit($connection, 'spoke.backup.creation_completed', 'success', $publicId, null, $now);
            $connection->commit();
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw new RuntimeException('The backup inventory could not be completed.', 0, $failure);
        }
    }

    public function fail(PrivateStoragePaths $paths, string $publicId, string $failureCode): void
    {
        $statement = $this->connection($paths)->prepare("UPDATE backup_archives SET status = 'failed', failure_code = :failure_code WHERE public_id = :public_id AND status = 'creating'");
        $statement->execute(['failure_code' => preg_replace('/[^a-z0-9_]+/', '_', strtolower($failureCode)) ?: 'backup_failed', 'public_id' => $publicId]);
    }

    public function beginDownload(PrivateStoragePaths $paths, string $publicId, DateTimeImmutable $now): BackupArchive
    {
        $connection = $this->connection($paths);
        $connection->beginTransaction();
        try {
            $statement = $connection->prepare("UPDATE backup_archives SET active_downloads = active_downloads + 1, download_count = download_count + 1 WHERE public_id = :public_id AND status = 'verified'");
            $statement->execute(['public_id' => $publicId]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('The backup is unavailable for download.');
            }
            $archive = $this->findOnConnection($connection, $publicId);
            if ($archive === null) {
                throw new RuntimeException('The backup is unavailable for download.');
            }
            $this->audit($connection, 'spoke.backup.download_started', 'success', $publicId, null, $now);
            $connection->commit();

            return $archive;
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $failure;
        }
    }

    public function finishDownload(PrivateStoragePaths $paths, string $publicId): void
    {
        $statement = $this->connection($paths)->prepare('UPDATE backup_archives SET active_downloads = CASE WHEN active_downloads > 0 THEN active_downloads - 1 ELSE 0 END WHERE public_id = :public_id');
        $statement->execute(['public_id' => $publicId]);
    }

    public function delete(PrivateStoragePaths $paths, string $publicId, DateTimeImmutable $now): void
    {
        $connection = $this->connection($paths);
        $connection->beginTransaction();
        try {
            $statement = $connection->prepare("DELETE FROM backup_archives WHERE public_id = :public_id AND status = 'verified' AND active_downloads = 0");
            $statement->execute(['public_id' => $publicId]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('The backup is unavailable or is currently being downloaded.');
            }
            $this->audit($connection, 'spoke.backup.deleted', 'success', $publicId, null, $now);
            $connection->commit();
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $failure;
        }
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): BackupArchive
    {
        return new BackupArchive(
            $this->stringValue($row, 'public_id'),
            BackupKind::from($this->stringValue($row, 'kind')),
            BackupState::from($this->stringValue($row, 'status')),
            $this->stringValue($row, 'storage_key'),
            new DateTimeImmutable($this->stringValue($row, 'created_at')),
            isset($row['completed_at']) && is_string($row['completed_at']) && $row['completed_at'] !== '' ? new DateTimeImmutable($row['completed_at']) : null,
            $this->intValue($row, 'size_bytes'),
            isset($row['sha256']) && is_string($row['sha256']) ? $row['sha256'] : null,
            $this->stringValue($row, 'schema_version'),
            $this->intValue($row, 'archive_format_version'),
            isset($row['failure_code']) && is_string($row['failure_code']) ? $row['failure_code'] : null,
            $this->intValue($row, 'active_downloads'),
            $this->intValue($row, 'download_count'),
        );
    }

    private function findOnConnection(PDO $connection, string $publicId): ?BackupArchive
    {
        $statement = $connection->prepare('SELECT * FROM backup_archives WHERE public_id = :public_id LIMIT 1');
        $statement->execute(['public_id' => $publicId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->map($this->normalizeRow($row)) : null;
    }

    private function audit(PDO $connection, string $event, string $outcome, string $publicId, ?string $kind, DateTimeImmutable $now): void
    {
        $metadata = ['archive_id' => $publicId];
        if ($kind !== null) {
            $metadata['kind'] = $kind;
        }
        $statement = $connection->prepare('INSERT INTO audit_events (event_name, outcome, occurred_at, resource_type, resource_public_id, metadata_json) VALUES (:event_name, :outcome, :occurred_at, :resource_type, :resource_public_id, :metadata_json)');
        $statement->execute([
            'event_name' => $event,
            'outcome' => $outcome,
            'occurred_at' => $this->format($now),
            'resource_type' => 'backup_archive',
            'resource_public_id' => $publicId,
            'metadata_json' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ]);
    }

    private function connection(PrivateStoragePaths $paths): PDO
    {
        return new PDO('sqlite:' . $paths->databaseFile(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    }

    private function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');
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
    private function stringValue(array $row, string $key): string
    {
        if (!is_string($row[$key] ?? null) || $row[$key] === '') {
            throw new RuntimeException('The backup inventory contains invalid state.');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function intValue(array $row, string $key): int
    {
        if (!is_int($row[$key] ?? null) && !(is_string($row[$key] ?? null) && is_numeric($row[$key]))) {
            throw new RuntimeException('The backup inventory contains invalid numeric state.');
        }

        return (int) $row[$key];
    }
}
