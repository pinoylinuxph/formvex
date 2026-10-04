<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Backup\BackupState;
use Formvex\Spoke\Domain\Backup\Contract\ScheduledBackupRepository;
use Formvex\Spoke\Domain\Backup\ScheduledBackupArchive;
use Formvex\Spoke\Domain\Backup\ScheduledBackupFrequency;
use Formvex\Spoke\Domain\Backup\ScheduledBackupSettings;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use PDO;
use RuntimeException;
use Throwable;

final class PdoScheduledBackupRepository implements ScheduledBackupRepository
{
    public function settings(PrivateStoragePaths $paths): ScheduledBackupSettings
    {
        $statement = $this->connection($paths)->query('SELECT * FROM scheduled_backup_settings WHERE singleton_id = 1');
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Scheduled backup settings are unavailable.');
        }

        return $this->mapSettings($this->normalizeRow($row));
    }

    public function saveSettings(PrivateStoragePaths $paths, ScheduledBackupSettings $settings, DateTimeImmutable $now): void
    {
        $statement = $this->connection($paths)->prepare(
            'UPDATE scheduled_backup_settings SET enabled = :enabled, frequency = :frequency, weekday = :weekday, hour = :hour, minute = :minute, retention_count = :retention_count, updated_at = :updated_at WHERE singleton_id = 1',
        );
        $statement->execute([
            'enabled' => $settings->enabled ? 1 : 0,
            'frequency' => $settings->frequency->value,
            'weekday' => $settings->weekday,
            'hour' => $settings->hour,
            'minute' => $settings->minute,
            'retention_count' => $settings->retentionCount,
            'updated_at' => $this->format($now),
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Scheduled backup settings could not be saved.');
        }
        $this->audit($this->connection($paths), 'spoke.scheduled_backup.settings_saved', 'success', null, $now);
    }

    public function claimDue(PrivateStoragePaths $paths, string $duePeriod, DateTimeImmutable $now): bool
    {
        $connection = $this->connection($paths);
        $connection->beginTransaction();
        try {
            $statement = $connection->prepare(
                "UPDATE scheduled_backup_settings SET last_due_period = :due_period, last_attempt_at = :attempted_at, last_status = 'running', last_error_code = NULL, updated_at = :updated_at WHERE singleton_id = 1 AND enabled = 1 AND (last_due_period IS NULL OR last_due_period <> :same_due_period)",
            );
            $timestamp = $this->format($now);
            $statement->execute([
                'due_period' => $duePeriod,
                'same_due_period' => $duePeriod,
                'attempted_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            if ($statement->rowCount() !== 1) {
                $connection->rollBack();

                return false;
            }
            $this->audit($connection, 'spoke.scheduled_backup.run_started', 'success', null, $now);
            $connection->commit();

            return true;
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $failure;
        }
    }

    public function list(PrivateStoragePaths $paths): array
    {
        $statement = $this->connection($paths)->query('SELECT * FROM scheduled_backup_archives ORDER BY created_at DESC, id DESC');
        $rows = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);
        $archives = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $archives[] = $this->mapArchive($this->normalizeRow($row));
            }
        }

        return $archives;
    }

    public function find(PrivateStoragePaths $paths, string $publicId): ?ScheduledBackupArchive
    {
        $statement = $this->connection($paths)->prepare('SELECT * FROM scheduled_backup_archives WHERE public_id = :public_id LIMIT 1');
        $statement->execute(['public_id' => $publicId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->mapArchive($this->normalizeRow($row)) : null;
    }

    public function create(PrivateStoragePaths $paths, string $publicId, string $duePeriod, string $storageKey, string $schemaVersion, DateTimeImmutable $now): void
    {
        $connection = $this->connection($paths);
        $statement = $connection->prepare(
            'INSERT INTO scheduled_backup_archives (public_id, due_period, status, storage_key, created_at, schema_version) VALUES (:public_id, :due_period, :status, :storage_key, :created_at, :schema_version)',
        );
        $statement->execute([
            'public_id' => $publicId,
            'due_period' => $duePeriod,
            'status' => BackupState::CREATING->value,
            'storage_key' => $storageKey,
            'created_at' => $this->format($now),
            'schema_version' => $schemaVersion,
        ]);
        $this->audit($connection, 'spoke.scheduled_backup.candidate_created', 'success', $publicId, $now);
    }

    public function complete(PrivateStoragePaths $paths, string $publicId, int $sizeBytes, string $sha256, DateTimeImmutable $now): void
    {
        $connection = $this->connection($paths);
        $connection->beginTransaction();
        try {
            $statement = $connection->prepare("UPDATE scheduled_backup_archives SET status = 'verified', completed_at = :completed_at, size_bytes = :size_bytes, sha256 = :sha256, failure_code = NULL WHERE public_id = :public_id AND status = 'creating'");
            $statement->execute([
                'completed_at' => $this->format($now),
                'size_bytes' => $sizeBytes,
                'sha256' => $sha256,
                'public_id' => $publicId,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Scheduled backup inventory could not be completed.');
            }
            $this->audit($connection, 'spoke.scheduled_backup.candidate_verified', 'success', $publicId, $now);
            $connection->commit();
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $failure;
        }
    }

    public function fail(PrivateStoragePaths $paths, string $publicId, string $failureCode, DateTimeImmutable $now): void
    {
        $statement = $this->connection($paths)->prepare("UPDATE scheduled_backup_archives SET status = 'failed', failure_code = :failure_code WHERE public_id = :public_id AND status = 'creating'");
        $statement->execute([
            'failure_code' => $this->safeCode($failureCode),
            'public_id' => $publicId,
        ]);
        $this->audit($this->connection($paths), 'spoke.scheduled_backup.failed', 'failure', $publicId, $now);
    }

    public function beginDownload(PrivateStoragePaths $paths, string $publicId, DateTimeImmutable $now): ScheduledBackupArchive
    {
        $connection = $this->connection($paths);
        $connection->beginTransaction();
        try {
            $statement = $connection->prepare("UPDATE scheduled_backup_archives SET active_downloads = active_downloads + 1, download_count = download_count + 1, last_download_at = :downloaded_at WHERE public_id = :public_id AND status = 'verified'");
            $statement->execute(['public_id' => $publicId, 'downloaded_at' => $this->format($now)]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('The scheduled backup is unavailable for download.');
            }
            $archive = $this->findOnConnection($connection, $publicId);
            if ($archive === null) {
                throw new RuntimeException('The scheduled backup is unavailable for download.');
            }
            $this->audit($connection, 'spoke.scheduled_backup.download_started', 'success', $publicId, $now);
            $connection->commit();

            return $archive;
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $failure;
        }
    }

    public function finishDownload(PrivateStoragePaths $paths, string $publicId, DateTimeImmutable $now): void
    {
        $statement = $this->connection($paths)->prepare('UPDATE scheduled_backup_archives SET active_downloads = CASE WHEN active_downloads > 0 THEN active_downloads - 1 ELSE 0 END, last_download_at = :finished_at WHERE public_id = :public_id');
        $statement->execute(['public_id' => $publicId, 'finished_at' => $this->format($now)]);
    }

    public function abandonedCandidates(PrivateStoragePaths $paths, DateTimeImmutable $cutoff): array
    {
        $statement = $this->connection($paths)->prepare("SELECT * FROM scheduled_backup_archives WHERE status = 'creating' AND created_at < :cutoff ORDER BY created_at ASC, id ASC");
        $statement->execute(['cutoff' => $this->format($cutoff)]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $archives = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $archives[] = $this->mapArchive($this->normalizeRow($row));
            }
        }

        return $archives;
    }

    public function recoverStaleDownloads(PrivateStoragePaths $paths, DateTimeImmutable $cutoff, DateTimeImmutable $now): int
    {
        $connection = $this->connection($paths);
        $statement = $connection->prepare("UPDATE scheduled_backup_archives SET active_downloads = 0 WHERE status = 'verified' AND active_downloads > 0 AND last_download_at IS NOT NULL AND last_download_at < :cutoff");
        $statement->execute(['cutoff' => $this->format($cutoff)]);
        $count = $statement->rowCount();
        if ($count > 0) {
            $this->audit($connection, 'spoke.scheduled_backup.stale_download_recovered', 'warning', null, $now);
        }

        return $count;
    }

    public function rotationCandidates(PrivateStoragePaths $paths): array
    {
        $statement = $this->connection($paths)->query("SELECT * FROM scheduled_backup_archives WHERE status = 'verified' ORDER BY created_at ASC, id ASC");
        $rows = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);
        $archives = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $archives[] = $this->mapArchive($this->normalizeRow($row));
            }
        }

        return $archives;
    }

    public function delete(PrivateStoragePaths $paths, string $publicId, DateTimeImmutable $now): void
    {
        $connection = $this->connection($paths);
        $connection->beginTransaction();
        try {
            $statement = $connection->prepare("DELETE FROM scheduled_backup_archives WHERE public_id = :public_id AND status = 'verified' AND active_downloads = 0");
            $statement->execute(['public_id' => $publicId]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('The scheduled backup is unavailable or is currently being downloaded.');
            }
            $this->audit($connection, 'spoke.scheduled_backup.rotated', 'success', $publicId, $now);
            $connection->commit();
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $failure;
        }
    }

    public function markRun(PrivateStoragePaths $paths, string $status, ?string $errorCode, ?string $candidateId, DateTimeImmutable $now): void
    {
        $success = $status === 'success';
        $statement = $this->connection($paths)->prepare(
            'UPDATE scheduled_backup_settings SET last_status = :status, last_error_code = :error_code, last_candidate_id = :candidate_id, last_success_at = CASE WHEN :success = 1 THEN :success_at ELSE last_success_at END, updated_at = :updated_at WHERE singleton_id = 1',
        );
        $timestamp = $this->format($now);
        $statement->execute([
            'status' => $status,
            'error_code' => $errorCode === null ? null : $this->safeCode($errorCode),
            'candidate_id' => $candidateId,
            'success' => $success ? 1 : 0,
            'success_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function mapSettings(array $row): ScheduledBackupSettings
    {
        $frequency = ScheduledBackupFrequency::tryFrom($this->stringValue($row, 'frequency'));
        if ($frequency === null) {
            throw new RuntimeException('Scheduled backup frequency is invalid.');
        }

        return new ScheduledBackupSettings(
            $this->intValue($row, 'enabled') === 1,
            $frequency,
            $this->intValue($row, 'weekday'),
            $this->intValue($row, 'hour'),
            $this->intValue($row, 'minute'),
            $this->intValue($row, 'retention_count'),
            $this->nullableString($row, 'last_due_period'),
            $this->nullableDate($row, 'last_attempt_at'),
            $this->nullableDate($row, 'last_success_at'),
            $this->nullableString($row, 'last_status'),
            $this->nullableString($row, 'last_error_code'),
            $this->nullableString($row, 'last_candidate_id'),
            $this->nullableDate($row, 'last_cleanup_at'),
            new DateTimeImmutable($this->stringValue($row, 'updated_at')),
        );
    }

    /** @param array<string, mixed> $row */
    private function mapArchive(array $row): ScheduledBackupArchive
    {
        return new ScheduledBackupArchive(
            $this->stringValue($row, 'public_id'),
            $this->stringValue($row, 'due_period'),
            BackupState::from($this->stringValue($row, 'status')),
            $this->stringValue($row, 'storage_key'),
            new DateTimeImmutable($this->stringValue($row, 'created_at')),
            $this->nullableDate($row, 'completed_at'),
            $this->intValue($row, 'size_bytes'),
            $this->nullableString($row, 'sha256'),
            $this->stringValue($row, 'schema_version'),
            $this->intValue($row, 'archive_format_version'),
            $this->nullableString($row, 'failure_code'),
            $this->intValue($row, 'active_downloads'),
            $this->intValue($row, 'download_count'),
            $this->nullableDate($row, 'last_download_at'),
        );
    }

    private function findOnConnection(PDO $connection, string $publicId): ?ScheduledBackupArchive
    {
        $statement = $connection->prepare('SELECT * FROM scheduled_backup_archives WHERE public_id = :public_id LIMIT 1');
        $statement->execute(['public_id' => $publicId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->mapArchive($this->normalizeRow($row)) : null;
    }

    private function audit(PDO $connection, string $event, string $outcome, ?string $publicId, DateTimeImmutable $now): void
    {
        $metadata = $publicId === null ? [] : ['archive_id' => $publicId];
        $statement = $connection->prepare('INSERT INTO audit_events (event_name, outcome, occurred_at, resource_type, resource_public_id, metadata_json) VALUES (:event_name, :outcome, :occurred_at, :resource_type, :resource_public_id, :metadata_json)');
        $statement->execute([
            'event_name' => $event,
            'outcome' => $outcome,
            'occurred_at' => $this->format($now),
            'resource_type' => 'scheduled_backup',
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
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /** @return array<string, mixed> */
    private function normalizeRow(mixed $row): array
    {
        if (!is_array($row)) {
            throw new RuntimeException('Scheduled backup storage contains invalid row state.');
        }
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
            throw new RuntimeException('Scheduled backup storage contains invalid text state.');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        return is_string($row[$key] ?? null) && $row[$key] !== '' ? $row[$key] : null;
    }

    /** @param array<string, mixed> $row */
    private function nullableDate(array $row, string $key): ?DateTimeImmutable
    {
        $value = $this->nullableString($row, $key);

        return $value === null ? null : new DateTimeImmutable($value);
    }

    /** @param array<string, mixed> $row */
    private function intValue(array $row, string $key): int
    {
        if (!is_int($row[$key] ?? null) && !(is_string($row[$key] ?? null) && is_numeric($row[$key]))) {
            throw new RuntimeException('Scheduled backup storage contains invalid numeric state.');
        }

        return (int) $row[$key];
    }

    private function safeCode(string $code): string
    {
        return preg_replace('/[^a-z0-9_]+/', '_', strtolower($code)) ?: 'scheduled_backup_failed';
    }
}
