<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Delivery\Contract\DeliveryControlRepository;
use Formvex\Spoke\Domain\Delivery\DeliveryControlFailure;
use Formvex\Spoke\Domain\Delivery\DeliveryControlStatus;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use PDO;
use Throwable;

final class PdoDeliveryControlRepository implements DeliveryControlRepository
{
    public function status(PrivateStoragePaths $paths): DeliveryControlStatus
    {
        $connection = $this->connection($paths);
        if (!$this->hasTable($connection, 'delivery_control')) {
            return new DeliveryControlStatus('running');
        }

        $statement = $connection->query('SELECT state, changed_at, changed_by FROM delivery_control WHERE singleton_id = 1');
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !is_string($row['state'] ?? null)) {
            throw new DeliveryControlFailure('storage_unavailable', 'The delivery control state could not be read safely. Run the installation migration and try again.');
        }

        return new DeliveryControlStatus(
            $row['state'],
            is_string($row['changed_at'] ?? null) ? $row['changed_at'] : null,
            is_string($row['changed_by'] ?? null) ? $row['changed_by'] : null,
        );
    }

    public function pause(PrivateStoragePaths $paths, string $actor, DateTimeImmutable $now): DeliveryControlStatus
    {
        return $this->transition($paths, 'running', 'paused', 'already_paused', 'Contact delivery is already paused.', 'spoke.delivery_paused', $actor, $now);
    }

    public function resume(PrivateStoragePaths $paths, string $actor, DateTimeImmutable $now): DeliveryControlStatus
    {
        return $this->transition($paths, 'paused', 'running', 'already_running', 'Contact delivery is already running.', 'spoke.delivery_resumed', $actor, $now);
    }

    private function transition(PrivateStoragePaths $paths, string $expected, string $next, string $sameStateCode, string $sameStateMessage, string $event, string $actor, DateTimeImmutable $now): DeliveryControlStatus
    {
        $connection = $this->connection($paths);
        $timestamp = $this->formatTimestamp($now);

        try {
            if (!$this->hasTable($connection, 'delivery_control')) {
                throw new DeliveryControlFailure('migration_required', 'Delivery pause and resume are unavailable until the local database migration is applied.');
            }
            $connection->exec('BEGIN IMMEDIATE TRANSACTION');
            $statement = $connection->query('SELECT state, changed_at, changed_by FROM delivery_control WHERE singleton_id = 1');
            $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);
            $state = is_array($row) && is_string($row['state'] ?? null) ? $row['state'] : '';
            if ($state !== $expected) {
                $connection->rollBack();

                throw new DeliveryControlFailure($sameStateCode, $sameStateMessage);
            }

            $update = $connection->prepare('UPDATE delivery_control SET state = :state, changed_at = :changed_at, changed_by = :changed_by WHERE singleton_id = 1 AND state = :expected_state');
            $update->execute(['state' => $next, 'changed_at' => $timestamp, 'changed_by' => $actor, 'expected_state' => $expected]);
            if ($update->rowCount() !== 1) {
                throw new DeliveryControlFailure('stale_state', 'The delivery control state changed before this action completed. Reload Delivery and try again.');
            }

            $audit = $connection->prepare(
                'INSERT INTO audit_events (event_name, outcome, occurred_at, resource_type, resource_public_id, metadata_json) VALUES (:event_name, \'success\', :occurred_at, \'delivery_control\', NULL, :metadata_json)',
            );
            $audit->execute([
                'event_name' => $event,
                'occurred_at' => $timestamp,
                'metadata_json' => json_encode(['actor' => $actor, 'previous_state' => $expected, 'new_state' => $next], JSON_THROW_ON_ERROR),
            ]);
            $connection->commit();

            return new DeliveryControlStatus($next, $timestamp, $actor);
        } catch (DeliveryControlFailure $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $failure;
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw new DeliveryControlFailure('transition_failed', 'The delivery state could not be changed safely. No delivery state or audit record was changed.', $failure);
        }
    }

    private function connection(PrivateStoragePaths $paths): PDO
    {
        $connection = new PDO('sqlite:' . $paths->databaseFile(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        $connection->exec('PRAGMA busy_timeout = 5000');

        return $connection;
    }

    private function hasTable(PDO $connection, string $table): bool
    {
        $statement = $connection->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table_name LIMIT 1");
        $statement->execute(['table_name' => $table]);

        return $statement->fetchColumn() !== false;
    }

    private function formatTimestamp(DateTimeImmutable $timestamp): string
    {
        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');
    }
}
