<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Scheduler\Contract\SchedulerHealthRepository;
use Formvex\Spoke\Domain\Scheduler\SchedulerHealth;
use Formvex\Spoke\Domain\Scheduler\SchedulerJobStatus;
use PDO;
use Throwable;

final class PdoSchedulerHealthRepository implements SchedulerHealthRepository
{
    private const DELIVERY_STALE_SECONDS = 300;

    private const RETENTION_STALE_SECONDS = 7200;

    public function status(PrivateStoragePaths $paths, DateTimeImmutable $now): SchedulerHealth
    {
        try {
            $connection = $this->connection($paths);
            $jobs = [
                'delivery' => $this->delivery($connection, $now),
                'retention' => $this->retention($connection, $now),
            ];

            return $this->health($jobs);
        } catch (Throwable) {
            $jobs = [
                'delivery' => $this->unavailable('delivery', 'Delivery worker', 'every minute', 'formvex:spoke:delivery:run', 'The delivery worker status could not be read from private storage.'),
                'retention' => $this->unavailable('retention', 'Retention cleanup', 'hourly', 'formvex:spoke:retention:run', 'The retention cleanup status could not be read from private storage.'),
            ];

            return new SchedulerHealth($jobs, 'failed', 'danger', 'Scheduler health could not be read from private storage. Review the local installation before relying on scheduled processing.');
        }
    }

    private function delivery(PDO $connection, DateTimeImmutable $now): SchedulerJobStatus
    {
        if (!$this->hasTable($connection, 'delivery_worker_heartbeat')) {
            return $this->unavailable('delivery', 'Delivery worker', 'every minute', 'formvex:spoke:delivery:run', 'The delivery worker heartbeat table is not available in this installation.');
        }

        $statement = $connection->query('SELECT last_success_at, last_result, updated_at FROM delivery_worker_heartbeat WHERE singleton_id = 1');
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return $this->unavailable('delivery', 'Delivery worker', 'every minute', 'formvex:spoke:delivery:run', 'The delivery worker has no heartbeat record yet.');
        }

        $lastSuccessAt = $this->timestamp($row['last_success_at'] ?? null);
        $lastAttemptAt = $this->timestamp($row['updated_at'] ?? null);
        $lastResult = is_string($row['last_result'] ?? null) ? $row['last_result'] : '';

        if ($lastResult === 'failure') {
            return new SchedulerJobStatus(
                'delivery',
                'Delivery worker',
                'Processes queued email and retry work.',
                'every minute',
                'formvex:spoke:delivery:run',
                'failed',
                'danger',
                $this->format($lastSuccessAt),
                $this->format($lastAttemptAt),
                'The latest delivery-worker execution failed before it could confirm a successful heartbeat.',
                'Check the hosting scheduler and run the delivery command manually to read its safe result.',
            );
        }

        if ($lastSuccessAt === null) {
            return new SchedulerJobStatus(
                'delivery',
                'Delivery worker',
                'Processes queued email and retry work.',
                'every minute',
                'formvex:spoke:delivery:run',
                'not_confirmed',
                'warning',
                null,
                $this->format($lastAttemptAt),
                'No successful delivery-worker heartbeat has been recorded.',
                'Add or repair the hosting scheduler entry, then run the delivery command once and refresh this page.',
            );
        }

        if ($this->age($lastSuccessAt, $now) >= self::DELIVERY_STALE_SECONDS) {
            return new SchedulerJobStatus(
                'delivery',
                'Delivery worker',
                'Processes queued email and retry work.',
                'every minute',
                'formvex:spoke:delivery:run',
                'stale',
                'warning',
                $this->format($lastSuccessAt),
                $this->format($lastAttemptAt),
                'The delivery worker has not reported a successful run within five minutes.',
                'Inspect the hosting scheduler and run the delivery command manually before relying on queued delivery.',
            );
        }

        return new SchedulerJobStatus(
            'delivery',
            'Delivery worker',
            'Processes queued email and retry work.',
            'every minute',
            'formvex:spoke:delivery:run',
            'healthy',
            'success',
            $this->format($lastSuccessAt),
            $this->format($lastAttemptAt),
            'The delivery worker reported a successful run within the expected interval.',
            'No scheduler action is required.',
        );
    }

    private function retention(PDO $connection, DateTimeImmutable $now): SchedulerJobStatus
    {
        if (!$this->hasColumn($connection, 'installation_settings', 'retention_last_run_at')) {
            return $this->unavailable('retention', 'Retention cleanup', 'hourly', 'formvex:spoke:retention:run', 'Retention cleanup status is not available in this installation.');
        }

        $columns = 'retention_last_run_at, retention_last_status';
        if ($this->hasColumn($connection, 'installation_settings', 'retention_last_success_at')) {
            $columns .= ', retention_last_success_at';
        }
        $statement = $connection->query('SELECT ' . $columns . ' FROM installation_settings WHERE singleton_id = 1');
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return $this->unavailable('retention', 'Retention cleanup', 'hourly', 'formvex:spoke:retention:run', 'Retention cleanup has no status record yet.');
        }

        $lastAttemptAt = $this->timestamp($row['retention_last_run_at'] ?? null);
        $lastSuccessAt = $this->timestamp($row['retention_last_success_at'] ?? null);
        if ($lastSuccessAt === null && ($row['retention_last_status'] ?? null) === 'completed') {
            $lastSuccessAt = $lastAttemptAt;
        }
        $lastStatus = is_string($row['retention_last_status'] ?? null) ? $row['retention_last_status'] : '';

        if ($lastStatus === 'partial_failure') {
            return new SchedulerJobStatus(
                'retention',
                'Retention cleanup',
                'Removes expired local records and preserves protected or active work.',
                'hourly',
                'formvex:spoke:retention:run',
                'failed',
                'danger',
                $this->format($lastSuccessAt),
                $this->format($lastAttemptAt),
                'The latest retention cleanup stopped after a safe failure and did not complete all work.',
                'Review the maintenance result, correct the reported condition, and run the retention command again.',
            );
        }

        if ($lastSuccessAt === null) {
            return new SchedulerJobStatus(
                'retention',
                'Retention cleanup',
                'Removes expired local records and preserves protected or active work.',
                'hourly',
                'formvex:spoke:retention:run',
                'not_confirmed',
                'warning',
                null,
                $this->format($lastAttemptAt),
                'No successful retention cleanup has been recorded.',
                'Add or repair the hourly hosting scheduler entry, then run the retention command once and refresh this page.',
            );
        }

        if ($this->age($lastSuccessAt, $now) >= self::RETENTION_STALE_SECONDS) {
            return new SchedulerJobStatus(
                'retention',
                'Retention cleanup',
                'Removes expired local records and preserves protected or active work.',
                'hourly',
                'formvex:spoke:retention:run',
                'stale',
                'warning',
                $this->format($lastSuccessAt),
                $this->format($lastAttemptAt),
                'The retention cleanup has not reported a successful run within two hours.',
                'Inspect the hourly hosting scheduler and run the retention command manually before relying on automatic cleanup.',
            );
        }

        return new SchedulerJobStatus(
            'retention',
            'Retention cleanup',
            'Removes expired local records and preserves protected or active work.',
            'hourly',
            'formvex:spoke:retention:run',
            'healthy',
            'success',
            $this->format($lastSuccessAt),
            $this->format($lastAttemptAt),
            'The retention cleanup reported a successful run within the expected interval.',
            'No scheduler action is required.',
        );
    }

    /** @param array<string, SchedulerJobStatus> $jobs */
    private function health(array $jobs): SchedulerHealth
    {
        foreach (['failed', 'stale', 'not_confirmed'] as $status) {
            foreach ($jobs as $job) {
                if ($job->status === $status) {
                    return new SchedulerHealth($jobs, $status, $job->severity, 'One or more scheduled tasks need administrator attention. Open a job row for the corrective action.');
                }
            }
        }

        return new SchedulerHealth($jobs, 'healthy', 'success', 'All required scheduled tasks have reported successful runs within their expected intervals.');
    }

    private function unavailable(string $key, string $label, string $schedule, string $command, string $message): SchedulerJobStatus
    {
        return new SchedulerJobStatus($key, $label, '', $schedule, $command, 'not_confirmed', 'warning', null, null, $message, 'Check the hosting scheduler and run the command once, then refresh this page.');
    }

    private function connection(PrivateStoragePaths $paths): PDO
    {
        return new PDO('sqlite:' . $paths->databaseFile(), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private function hasTable(PDO $connection, string $table): bool
    {
        $statement = $connection->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
        $statement->execute(['table' => $table]);

        return $statement->fetchColumn() !== false;
    }

    private function hasColumn(PDO $connection, string $table, string $column): bool
    {
        $statement = $connection->prepare('SELECT 1 FROM pragma_table_info(:table_name) WHERE name = :column_name LIMIT 1');
        $statement->execute(['table_name' => $table, 'column_name' => $column]);

        return $statement->fetchColumn() !== false;
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

    private function format(?DateTimeImmutable $timestamp): ?string
    {
        return $timestamp?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    private function age(DateTimeImmutable $timestamp, DateTimeImmutable $now): int
    {
        return max(0, $now->getTimestamp() - $timestamp->getTimestamp());
    }
}
