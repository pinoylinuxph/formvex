<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\InstallationSettings\InstallationSettings;
use Formvex\Spoke\Domain\Retention\Contract\RetentionRepository;
use Formvex\Spoke\Domain\Retention\RetentionCleanupResult;
use Formvex\Spoke\Domain\Retention\RetentionStatus;
use Formvex\Spoke\Domain\Storage\Contract\StorageExportRepository;
use PDO;
use RuntimeException;
use Throwable;

final class PdoRetentionRepository implements RetentionRepository
{
    public function __construct(private readonly ?StorageExportRepository $storageExportRepository = null)
    {
    }

    public function cleanup(PrivateStoragePaths $paths, InstallationSettings $settings, DateTimeImmutable $now, int $batchLimit): RetentionCleanupResult
    {
        $connection = $this->connection($paths);
        $scanned = 0;
        $deleted = 0;
        $deferred = 0;
        $failed = 0;

        try {
            $rows = $connection->query(
                'SELECT s.id, s.public_id, s.state, s.created_at, s.handled_at, s.trashed_at, s.restored_at, s.recovery_deadline, '
                . 'd.id AS delivery_id, d.state AS delivery_state, '
                . "(SELECT completed_at FROM delivery_attempts WHERE job_id = d.id AND outcome = 'accepted' ORDER BY completed_at DESC LIMIT 1) AS accepted_at, "
                . "(SELECT completed_at FROM delivery_attempts WHERE job_id = d.id AND outcome = 'uncertain' ORDER BY completed_at DESC LIMIT 1) AS uncertain_at "
                . 'FROM submissions s LEFT JOIN delivery_jobs d ON d.submission_id = s.id '
                . 'WHERE s.state IN (\'accepted\', \'handled\', \'trashed\') '
                . 'ORDER BY s.id LIMIT ' . $batchLimit,
            );

            $candidates = $rows === false ? [] : $rows->fetchAll(PDO::FETCH_ASSOC);
            foreach ($candidates as $rawRow) {
                if (!is_array($rawRow)) {
                    continue;
                }
                $row = $this->normalizeRow($rawRow);
                $scanned++;

                if (!$this->eligible($row, $settings, $now) && !$this->needsRecoveryDeadline($row, $settings)) {
                    $deferred++;
                    continue;
                }

                try {
                    $connection->exec('BEGIN IMMEDIATE TRANSACTION');
                    $current = $this->currentSubmission($connection, $this->integerValue($row, 'id'));

                    if ($current === null) {
                        $connection->rollBack();
                        $deferred++;
                        continue;
                    }

                    if ($this->needsRecoveryDeadline($current, $settings)) {
                        $recoveryDeadline = $this->addDays($this->timestamp($current['restored_at']) ?? $now, 30);
                        $connection->prepare('UPDATE submissions SET recovery_deadline = :recovery_deadline WHERE id = :id AND recovery_deadline IS NULL')->execute([
                            'recovery_deadline' => $this->formatTimestamp($recoveryDeadline),
                            'id' => $this->integerValue($current, 'id'),
                        ]);
                        $current['recovery_deadline'] = $this->formatTimestamp($recoveryDeadline);
                    }

                    if (!$this->eligible($current, $settings, $now)) {
                        $connection->commit();
                        $deferred++;
                        continue;
                    }

                    $this->deleteSubmission($connection, $current, $now);
                    $connection->commit();
                    $deleted++;
                } catch (Throwable $failure) {
                    if ($connection->inTransaction()) {
                        $connection->rollBack();
                    }
                    $failed++;
                    $this->recordRun($connection, $now, 'partial_failure', $scanned, $deleted, $deferred, $failed, 'submission_cleanup_failed');

                    return new RetentionCleanupResult('partial_failure', $scanned, $deleted, $deferred, $failed, 'submission_cleanup_failed', false);
                }
            }

            $this->cleanupTemporaryRecords($connection, $settings->auditRetentionDays, $now, $deferred);
            $this->cleanupAuditEvents($connection, $settings->auditRetentionDays, $now);
            $this->cleanupLogFiles($paths->logs, $settings->auditRetentionDays, $now);
            $this->storageExportRepository?->expire($paths, $now);
            $this->recordRun($connection, $now, 'completed', $scanned, $deleted, $deferred, $failed, null);

            return new RetentionCleanupResult('completed', $scanned, $deleted, $deferred, $failed);
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            $failed++;
            $this->recordRun($connection, $now, 'partial_failure', $scanned, $deleted, $deferred, $failed, 'retention_cleanup_failed');

            return new RetentionCleanupResult('partial_failure', $scanned, $deleted, $deferred, $failed, 'retention_cleanup_failed', false);
        }
    }

    public function status(PrivateStoragePaths $paths): RetentionStatus
    {
        $connection = $this->connection($paths);

        if (!$this->hasColumn($connection, 'installation_settings', 'retention_last_run_at')) {
            return RetentionStatus::notRun();
        }

        $hasSuccessColumn = $this->hasColumn($connection, 'installation_settings', 'retention_last_success_at');
        $columns = 'retention_last_run_at, retention_last_status, retention_last_scanned, retention_last_deleted, retention_last_deferred, retention_last_failed, retention_last_error_code';
        if ($hasSuccessColumn) {
            $columns .= ', retention_last_success_at';
        }
        $statement = $connection->query('SELECT ' . $columns . ' FROM installation_settings WHERE singleton_id = 1');
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return RetentionStatus::notRun();
        }

        $row = $this->normalizeRow($row);

        return new RetentionStatus(
            is_string($row['retention_last_run_at'] ?? null) && $row['retention_last_run_at'] !== '' ? $row['retention_last_run_at'] : null,
            is_string($row['retention_last_status'] ?? null) && $row['retention_last_status'] !== '' ? $row['retention_last_status'] : null,
            $this->integerValue($row, 'retention_last_scanned'),
            $this->integerValue($row, 'retention_last_deleted'),
            $this->integerValue($row, 'retention_last_deferred'),
            $this->integerValue($row, 'retention_last_failed'),
            is_string($row['retention_last_error_code'] ?? null) && $row['retention_last_error_code'] !== '' ? $row['retention_last_error_code'] : null,
            $hasSuccessColumn && is_string($row['retention_last_success_at'] ?? null) && $row['retention_last_success_at'] !== '' ? $row['retention_last_success_at'] : null,
        );
    }

    /** @param array<string, mixed> $row */
    private function eligible(array $row, InstallationSettings $settings, DateTimeImmutable $now): bool
    {
        $state = is_string($row['state'] ?? null) ? $row['state'] : '';
        $deliveryState = $row['delivery_state'] ?? null;

        if ($deliveryState === null || in_array($deliveryState, ['queued', 'processing'], true)) {
            return false;
        }

        if ($state === 'trashed') {
            $trashedAt = $this->timestamp($row['trashed_at'] ?? null);

            return $trashedAt !== null && $now >= $this->addDays($trashedAt, 7);
        }

        $originalDeadline = $this->originalDeadline($row, $settings);

        if ($originalDeadline === null) {
            return false;
        }

        $recoveryDeadline = $this->timestamp($row['recovery_deadline'] ?? null);
        if ($recoveryDeadline !== null) {
            return $now >= $recoveryDeadline;
        }

        $restoredAt = $this->timestamp($row['restored_at'] ?? null);
        if ($restoredAt !== null && $restoredAt > $originalDeadline) {
            return $now >= $this->addDays($restoredAt, 30);
        }

        return $now >= $originalDeadline;
    }

    /** @param array<string, mixed> $row */
    private function originalDeadline(array $row, InstallationSettings $settings): ?DateTimeImmutable
    {
        $deliveryState = $row['delivery_state'] ?? null;
        if ($deliveryState === 'failed') {
            $anchor = $this->timestamp($row['created_at'] ?? null);

            return $anchor === null ? null : $this->addDays($anchor, 365);
        }

        if ($deliveryState === 'uncertain') {
            $anchor = $this->timestamp($row['uncertain_at'] ?? null);

            return $anchor === null ? null : $this->addDays($anchor, $settings->uncertainRetentionDays);
        }

        if ($deliveryState !== 'sent') {
            return null;
        }

        $anchor = $this->timestamp($row['handled_at'] ?? null) ?? $this->timestamp($row['accepted_at'] ?? null);

        return $anchor === null ? null : $this->addDays($anchor, $settings->ordinaryRetentionDays);
    }

    /** @param array<string, mixed> $row */
    private function needsRecoveryDeadline(array $row, InstallationSettings $settings): bool
    {
        $restoredAt = $this->timestamp($row['restored_at'] ?? null);

        return $restoredAt !== null
            && $this->timestamp($row['recovery_deadline'] ?? null) === null
            && ($this->originalDeadline($row, $settings) ?? $restoredAt) < $restoredAt;
    }

    /** @return array<string, mixed>|null */
    private function currentSubmission(PDO $connection, int $id): ?array
    {
        $statement = $connection->prepare(
            'SELECT s.id, s.public_id, s.state, s.created_at, s.handled_at, s.trashed_at, s.restored_at, s.recovery_deadline, '
            . 'd.id AS delivery_id, d.state AS delivery_state, '
            . "(SELECT completed_at FROM delivery_attempts WHERE job_id = d.id AND outcome = 'accepted' ORDER BY completed_at DESC LIMIT 1) AS accepted_at, "
            . "(SELECT completed_at FROM delivery_attempts WHERE job_id = d.id AND outcome = 'uncertain' ORDER BY completed_at DESC LIMIT 1) AS uncertain_at "
            . 'FROM submissions s LEFT JOIN delivery_jobs d ON d.submission_id = s.id WHERE s.id = :id LIMIT 1',
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    /** @param array<string, mixed> $row */
    private function deleteSubmission(PDO $connection, array $row, DateTimeImmutable $now): void
    {
        $id = $this->integerValue($row, 'id');
        $publicId = $this->stringValue($row, 'public_id');
        $deliveryId = $this->integerValue($row, 'delivery_id', 0);

        if ($deliveryId > 0) {
            $cycles = $connection->prepare('SELECT id FROM delivery_attempt_cycles WHERE delivery_job_id = :job_id');
            $cycles->execute(['job_id' => $deliveryId]);
            $cycleIds = [];
            foreach ($cycles->fetchAll(PDO::FETCH_COLUMN) as $cycleValue) {
                if (is_int($cycleValue) || is_string($cycleValue) || is_float($cycleValue)) {
                    $cycleIds[] = (int) $cycleValue;
                }
            }
            foreach ($cycleIds as $cycleId) {
                $statement = $connection->prepare('DELETE FROM delivery_attempts WHERE cycle_id = :cycle_id');
                $statement->execute(['cycle_id' => $cycleId]);
            }
            $statement = $connection->prepare('DELETE FROM delivery_attempt_cycles WHERE delivery_job_id = :job_id');
            $statement->execute(['job_id' => $deliveryId]);
            $statement = $connection->prepare('DELETE FROM delivery_attempts WHERE job_id = :job_id');
            $statement->execute(['job_id' => $deliveryId]);
            $statement = $connection->prepare('DELETE FROM delivery_jobs WHERE id = :job_id');
            $statement->execute(['job_id' => $deliveryId]);
        }

        $statement = $connection->prepare('DELETE FROM submission_attempts WHERE submission_id = :submission_id');
        $statement->execute(['submission_id' => $id]);
        $statement = $connection->prepare('DELETE FROM submissions WHERE id = :id');
        $statement->execute(['id' => $id]);

        $audit = $connection->prepare(
            'INSERT INTO audit_events (event_name, outcome, occurred_at, resource_type, resource_public_id) VALUES (:event_name, :outcome, :occurred_at, :resource_type, :resource_public_id)',
        );
        $audit->execute([
            'event_name' => 'spoke.retention.submission_deleted',
            'outcome' => 'success',
            'occurred_at' => $this->formatTimestamp($now),
            'resource_type' => 'submission',
            'resource_public_id' => $publicId,
        ]);
    }

    private function cleanupTemporaryRecords(PDO $connection, int $auditRetentionDays, DateTimeImmutable $now, int &$deferred): void
    {
        $timestamp = $this->formatTimestamp($now);
        $connection->exec('BEGIN IMMEDIATE TRANSACTION');
        try {
            $this->deleteByTimestamp($connection, 'submission_attempts', 'expires_at', $timestamp);
            $this->deleteByTimestamp($connection, 'form_discovery_candidates', 'expires_at', $timestamp);
            $this->deleteByTimestamp($connection, 'form_discovery_capabilities', 'expires_at', $timestamp);
            $this->deleteQualificationRecords($connection, $timestamp);
            $this->deleteExpiredSessions($connection, $timestamp);
            $this->deleteExpiredLoginThrottles($connection, $now);
            $this->deleteExpiredAbuseCounters($connection, $now);
            $this->deleteTerminalAlerts($connection, $auditRetentionDays, $now);
            $connection->commit();
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $failure;
        }
    }

    private function deleteByTimestamp(PDO $connection, string $table, string $column, string $timestamp): void
    {
        $connection->prepare('DELETE FROM ' . $table . ' WHERE ' . $column . ' <= :timestamp')->execute(['timestamp' => $timestamp]);
    }

    private function deleteQualificationRecords(PDO $connection, string $timestamp): void
    {
        $connection->prepare(
            'DELETE FROM form_qualification_capabilities WHERE (submitted_at IS NULL AND expires_at <= :timestamp) OR (qualification_expires_at IS NOT NULL AND qualification_expires_at <= :timestamp)',
        )->execute(['timestamp' => $timestamp]);
    }

    private function deleteExpiredSessions(PDO $connection, string $timestamp): void
    {
        $connection->prepare('DELETE FROM admin_sessions WHERE expires_at <= :timestamp OR revoked_at IS NOT NULL')->execute(['timestamp' => $timestamp]);
    }

    private function deleteExpiredLoginThrottles(PDO $connection, DateTimeImmutable $now): void
    {
        $cutoff = $this->formatTimestamp($now->sub(new DateInterval('PT2H')));
        $connection->prepare('DELETE FROM admin_login_throttles WHERE (cooldown_until IS NOT NULL AND cooldown_until <= :now) OR updated_at <= :cutoff')->execute(['now' => $this->formatTimestamp($now), 'cutoff' => $cutoff]);
    }

    private function deleteExpiredAbuseCounters(PDO $connection, DateTimeImmutable $now): void
    {
        $connection->prepare('DELETE FROM abuse_rate_counters WHERE bucket_start + window_seconds <= :epoch')->execute(['epoch' => $now->getTimestamp()]);
    }

    private function deleteTerminalAlerts(PDO $connection, int $retentionDays, DateTimeImmutable $now): void
    {
        $cutoff = $this->formatTimestamp($now->sub(new DateInterval('P' . $retentionDays . 'D')));
        $connection->prepare("DELETE FROM delivery_alerts WHERE state IN ('sent', 'failed', 'uncertain') AND updated_at <= :cutoff")->execute(['cutoff' => $cutoff]);
    }

    private function cleanupAuditEvents(PDO $connection, int $retentionDays, DateTimeImmutable $now): void
    {
        $cutoff = $this->formatTimestamp($now->sub(new DateInterval('P' . $retentionDays . 'D')));
        $connection->prepare('DELETE FROM audit_events WHERE occurred_at < :cutoff')->execute(['cutoff' => $cutoff]);
    }

    private function cleanupLogFiles(string $directory, int $retentionDays, DateTimeImmutable $now): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $cutoff = $now->getTimestamp() - ($retentionDays * 86400);
        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (is_file($path) && !is_link($path) && filemtime($path) !== false && filemtime($path) < $cutoff) {
                if (!unlink($path)) {
                    throw new RuntimeException('log_cleanup_failed');
                }
            }
        }
    }

    private function recordRun(PDO $connection, DateTimeImmutable $now, string $status, int $scanned, int $deleted, int $deferred, int $failed, ?string $errorCode): void
    {
        $hasSuccessColumn = $this->hasColumn($connection, 'installation_settings', 'retention_last_success_at');
        $successColumn = $hasSuccessColumn && $status === 'completed' ? ', retention_last_success_at = :success_at' : '';
        $statement = $connection->prepare(
            'UPDATE installation_settings SET retention_last_run_at = :run_at, retention_last_status = :status, retention_last_scanned = :scanned, retention_last_deleted = :deleted, retention_last_deferred = :deferred, retention_last_failed = :failed, retention_last_error_code = :error_code' . $successColumn . ' WHERE singleton_id = 1',
        );
        $parameters = [
            'run_at' => $this->formatTimestamp($now),
            'status' => $status,
            'scanned' => $scanned,
            'deleted' => $deleted,
            'deferred' => $deferred,
            'failed' => $failed,
            'error_code' => $errorCode,
        ];
        if ($hasSuccessColumn && $status === 'completed') {
            $parameters['success_at'] = $this->formatTimestamp($now);
        }
        $statement->execute($parameters);
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

    private function addDays(DateTimeImmutable $timestamp, int $days): DateTimeImmutable
    {
        return $timestamp->add(new DateInterval('P' . $days . 'D'));
    }

    private function timestamp(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    private function formatTimestamp(DateTimeImmutable $timestamp): string
    {
        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');
    }

    private function hasColumn(PDO $connection, string $table, string $column): bool
    {
        $statement = $connection->prepare('SELECT 1 FROM pragma_table_info(:table_name) WHERE name = :column_name LIMIT 1');
        $statement->execute(['table_name' => $table, 'column_name' => $column]);

        return $statement->fetchColumn() !== false;
    }

    /** @param array<mixed, mixed> $row
     *  @return array<string, mixed>
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
    private function integerValue(array $row, string $key, int $default = 0): int
    {
        $value = $row[$key] ?? $default;

        if (!is_int($value) && !is_string($value) && !is_float($value)) {
            throw new RuntimeException('retention_row_invalid');
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $row */
    private function stringValue(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (!is_string($value)) {
            throw new RuntimeException('retention_row_invalid');
        }

        return $value;
    }
}
