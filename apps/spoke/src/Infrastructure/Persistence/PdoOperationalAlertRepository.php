<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Core\Delivery\DeliveryOutcomeType;
use Formvex\Spoke\Domain\Delivery\ClaimedOperationalAlert;
use Formvex\Spoke\Domain\Delivery\Contract\OperationalAlertRepository;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use PDO;
use RuntimeException;
use Throwable;

final class PdoOperationalAlertRepository implements OperationalAlertRepository
{
    public function enqueue(PrivateStoragePaths $paths, string $eventCode, DateTimeImmutable $dueAt): void
    {
        $connection = $this->connection($paths);
        $timestamp = $this->formatTimestamp($dueAt);
        $statement = $connection->prepare(
            "INSERT OR IGNORE INTO delivery_alerts (event_code, state, attempt_count, due_at, created_at, updated_at) VALUES (:event_code, 'queued', 0, :due_at, :created_at, :updated_at)",
        );
        $statement->execute(['event_code' => $eventCode, 'due_at' => $timestamp, 'created_at' => $timestamp, 'updated_at' => $timestamp]);
    }

    public function hasDueAlert(PrivateStoragePaths $paths, DateTimeImmutable $now): bool
    {
        $statement = $this->connection($paths)->prepare("SELECT 1 FROM delivery_alerts WHERE state = 'queued' AND due_at <= :due_at LIMIT 1");
        $statement->execute(['due_at' => $this->formatTimestamp($now)]);

        return $statement->fetchColumn() !== false;
    }

    public function claimDueAlert(PrivateStoragePaths $paths, DateTimeImmutable $now, string $leaseToken, DateTimeImmutable $leaseExpiresAt): ?ClaimedOperationalAlert
    {
        $connection = $this->connection($paths);
        $timestamp = $this->formatTimestamp($now);

        try {
            $connection->exec('BEGIN IMMEDIATE TRANSACTION');
            $stale = $connection->prepare("UPDATE delivery_alerts SET state = 'uncertain', last_error_code = 'stale_lease', lease_token = NULL, lease_expires_at = NULL, updated_at = :updated_at WHERE state = 'processing' AND lease_expires_at IS NOT NULL AND lease_expires_at <= :now");
            $stale->execute(['updated_at' => $timestamp, 'now' => $timestamp]);
            $statement = $connection->prepare("SELECT id, event_code, attempt_count FROM delivery_alerts WHERE state = 'queued' AND due_at <= :due_at ORDER BY due_at ASC, id ASC LIMIT 1");
            $statement->execute(['due_at' => $timestamp]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);

            if (!is_array($row) || !is_string($row['event_code'] ?? null)) {
                $connection->commit();

                return null;
            }

            $id = $this->integerValue($row['id'] ?? null);
            $attemptNumber = $this->integerValue($row['attempt_count'] ?? 0) + 1;
            $update = $connection->prepare("UPDATE delivery_alerts SET state = 'processing', attempt_count = :attempt_count, lease_token = :lease_token, lease_expires_at = :lease_expires_at, updated_at = :updated_at WHERE id = :id AND state = 'queued'");
            $update->execute(['attempt_count' => $attemptNumber, 'lease_token' => $leaseToken, 'lease_expires_at' => $this->formatTimestamp($leaseExpiresAt), 'updated_at' => $timestamp, 'id' => $id]);
            $connection->commit();

            return $update->rowCount() === 1 ? new ClaimedOperationalAlert($id, $row['event_code'], $attemptNumber, $leaseToken) : null;
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw new RuntimeException('The operational alert queue could not be claimed safely.', 0, $failure);
        }
    }

    public function recordOutcome(PrivateStoragePaths $paths, ClaimedOperationalAlert $alert, DeliveryOutcomeType $outcome, string $errorCode, ?DateTimeImmutable $nextDueAt, DateTimeImmutable $now): bool
    {
        $connection = $this->connection($paths);
        $state = match ($outcome) {
            DeliveryOutcomeType::ACCEPTED => 'sent',
            DeliveryOutcomeType::TEMPORARY => $nextDueAt === null ? 'failed' : 'queued',
            DeliveryOutcomeType::PERMANENT => 'failed',
            DeliveryOutcomeType::UNCERTAIN => 'uncertain',
        };
        $statement = $connection->prepare("UPDATE delivery_alerts SET state = :state, due_at = :due_at, last_error_code = :last_error_code, lease_token = NULL, lease_expires_at = NULL, updated_at = :updated_at WHERE id = :id AND state = 'processing' AND lease_token = :lease_token");
        $statement->execute([
            'state' => $state,
            'due_at' => $nextDueAt === null ? $this->formatTimestamp($now) : $this->formatTimestamp($nextDueAt),
            'last_error_code' => $errorCode === '' ? null : $errorCode,
            'updated_at' => $this->formatTimestamp($now),
            'id' => $alert->id,
            'lease_token' => $alert->leaseToken,
        ]);

        return $statement->rowCount() === 1;
    }

    private function connection(PrivateStoragePaths $paths): PDO
    {
        $connection = new PDO('sqlite:' . $paths->databaseFile(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        $connection->exec('PRAGMA busy_timeout = 5000');

        return $connection;
    }

    private function integerValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        throw new RuntimeException('The operational alert queue returned an invalid internal identifier.');
    }

    private function formatTimestamp(DateTimeImmutable $timestamp): string
    {
        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');
    }
}
