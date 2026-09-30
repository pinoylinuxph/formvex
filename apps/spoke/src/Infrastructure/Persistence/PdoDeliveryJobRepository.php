<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Core\Delivery\DeliveryMessageSnapshot;
use Formvex\Core\Delivery\DeliveryOutcomeType;
use Formvex\Spoke\Domain\Delivery\ClaimedDeliveryJob;
use Formvex\Spoke\Domain\Delivery\Contract\DeliveryJobRepository;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use PDO;
use RuntimeException;
use Throwable;

final class PdoDeliveryJobRepository implements DeliveryJobRepository
{
    public function claimDueJobs(PrivateStoragePaths $paths, DateTimeImmutable $now, int $limit, string $leaseToken, DateTimeImmutable $leaseExpiresAt): array
    {
        $connection = $this->connection($paths);
        $limit = max(1, min(100, $limit));
        $timestamp = $this->formatTimestamp($now);

        try {
            $connection->exec('BEGIN IMMEDIATE TRANSACTION');
            $this->recoverStaleClaims($connection, $timestamp);
            $statement = $connection->prepare(
                "SELECT id, job_id, attempt_count, snapshot_json FROM delivery_jobs WHERE state = 'queued' AND due_at <= :due_at ORDER BY due_at ASC, id ASC LIMIT {$limit}",
            );
            $statement->execute(['due_at' => $timestamp]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $claims = [];

            foreach ($rows as $row) {
                if (!is_array($row) || !is_string($row['job_id'] ?? null)) {
                    continue;
                }

                $jobId = $row['job_id'];
                $attemptNumber = $this->integerValue($row['attempt_count'] ?? 0) + 1;
                $update = $connection->prepare(
                    "UPDATE delivery_jobs SET state = 'processing', attempt_count = :attempt_count, lease_token = :lease_token, lease_expires_at = :lease_expires_at, updated_at = :updated_at WHERE id = :id AND state = 'queued'",
                );
                $update->execute([
                    'attempt_count' => $attemptNumber,
                    'lease_token' => $leaseToken,
                    'lease_expires_at' => $this->formatTimestamp($leaseExpiresAt),
                    'updated_at' => $timestamp,
                    'id' => $this->integerValue($row['id'] ?? null),
                ]);

                if ($update->rowCount() !== 1) {
                    continue;
                }

                $attempt = $connection->prepare(
                    "INSERT INTO delivery_attempts (job_id, attempt_number, outcome, started_at) SELECT id, :attempt_number, 'started', :started_at FROM delivery_jobs WHERE id = :id",
                );
                $attempt->execute([
                    'attempt_number' => $attemptNumber,
                    'started_at' => $timestamp,
                    'id' => $this->integerValue($row['id'] ?? null),
                ]);

                $snapshot = null;
                if (is_string($row['snapshot_json'] ?? null) && $row['snapshot_json'] !== '') {
                    try {
                        $data = json_decode($row['snapshot_json'], true, 20, JSON_THROW_ON_ERROR);
                        if (is_array($data) && !array_is_list($data)) {
                            $normalizedData = [];
                            foreach ($data as $key => $value) {
                                if (is_string($key)) {
                                    $normalizedData[$key] = $value;
                                }
                            }
                            $snapshot = DeliveryMessageSnapshot::fromArray($normalizedData);
                        }
                    } catch (Throwable) {
                        $snapshot = null;
                    }
                }

                $claims[] = new ClaimedDeliveryJob($jobId, $attemptNumber, $leaseToken, $snapshot);
            }

            $connection->commit();

            return $claims;
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw new RuntimeException('The delivery queue could not be claimed safely.', 0, $failure);
        }
    }

    public function markTransmitting(PrivateStoragePaths $paths, ClaimedDeliveryJob $job, DateTimeImmutable $now): bool
    {
        $connection = $this->connection($paths);
        $statement = $connection->prepare(
            "UPDATE delivery_jobs SET updated_at = :updated_at WHERE job_id = :job_id AND state = 'processing' AND lease_token = :lease_token",
        );
        $statement->execute([
            'updated_at' => $this->formatTimestamp($now),
            'job_id' => $job->jobId,
            'lease_token' => $job->leaseToken,
        ]);

        return $statement->rowCount() === 1;
    }

    public function recordOutcome(PrivateStoragePaths $paths, ClaimedDeliveryJob $job, DeliveryOutcomeType $outcome, string $errorCode, ?DateTimeImmutable $nextDueAt, DateTimeImmutable $now): bool
    {
        $connection = $this->connection($paths);
        $timestamp = $this->formatTimestamp($now);

        try {
            $connection->exec('BEGIN IMMEDIATE TRANSACTION');
            $state = match ($outcome) {
                DeliveryOutcomeType::ACCEPTED => 'sent',
                DeliveryOutcomeType::UNCERTAIN => 'uncertain',
                DeliveryOutcomeType::PERMANENT => 'failed',
                DeliveryOutcomeType::TEMPORARY => $nextDueAt === null ? 'failed' : 'queued',
            };
            $storedError = $errorCode !== '' ? $errorCode : null;
            $jobUpdate = $connection->prepare(
                'UPDATE delivery_jobs SET state = :state, due_at = :due_at, last_error_code = :last_error_code, last_outcome = :last_outcome, lease_token = NULL, lease_expires_at = NULL, updated_at = :updated_at WHERE job_id = :job_id AND state = \'processing\' AND lease_token = :lease_token',
            );
            $jobUpdate->execute([
                'state' => $state,
                'due_at' => $nextDueAt === null ? $timestamp : $this->formatTimestamp($nextDueAt),
                'last_error_code' => $storedError,
                'last_outcome' => $outcome->value,
                'updated_at' => $timestamp,
                'job_id' => $job->jobId,
                'lease_token' => $job->leaseToken,
            ]);

            if ($jobUpdate->rowCount() !== 1) {
                $connection->rollBack();

                return false;
            }

            $attempt = $connection->prepare(
                'UPDATE delivery_attempts SET outcome = :outcome, error_code = :error_code, completed_at = :completed_at, next_due_at = :next_due_at WHERE job_id = (SELECT id FROM delivery_jobs WHERE job_id = :job_id) AND attempt_number = :attempt_number AND outcome = \'started\'',
            );
            $attempt->execute([
                'outcome' => $outcome === DeliveryOutcomeType::TEMPORARY && $nextDueAt === null ? 'temporary_failure' : $outcome->value,
                'error_code' => $storedError,
                'completed_at' => $timestamp,
                'next_due_at' => $nextDueAt === null ? null : $this->formatTimestamp($nextDueAt),
                'job_id' => $job->jobId,
                'attempt_number' => $job->attemptNumber,
            ]);
            $connection->commit();

            return true;
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw new RuntimeException('The delivery outcome could not be recorded safely.', 0, $failure);
        }
    }

    private function recoverStaleClaims(PDO $connection, string $timestamp): void
    {
        $statement = $connection->prepare("SELECT id FROM delivery_jobs WHERE state = 'processing' AND lease_expires_at IS NOT NULL AND lease_expires_at <= :now");
        $statement->execute(['now' => $timestamp]);
        $ids = $statement->fetchAll(PDO::FETCH_COLUMN);

        foreach ($ids as $id) {
            $attempt = $connection->prepare("UPDATE delivery_attempts SET outcome = 'uncertain', error_code = 'stale_lease', completed_at = :completed_at WHERE job_id = :job_id AND outcome = 'started'");
            $attempt->execute(['completed_at' => $timestamp, 'job_id' => $this->integerValue($id)]);
            $job = $connection->prepare("UPDATE delivery_jobs SET state = 'uncertain', last_error_code = 'stale_lease', last_outcome = 'uncertain', lease_token = NULL, lease_expires_at = NULL, updated_at = :updated_at WHERE id = :id AND state = 'processing'");
            $job->execute(['updated_at' => $timestamp, 'id' => $this->integerValue($id)]);
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
        $connection->exec('PRAGMA busy_timeout = 5000');

        return $connection;
    }

    private function formatTimestamp(DateTimeImmutable $timestamp): string
    {
        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');
    }

    private function integerValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new RuntimeException('The delivery queue returned an invalid internal identifier.');
    }
}
