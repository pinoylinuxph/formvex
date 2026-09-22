<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Administration\AdministratorRecord;
use Formvex\Spoke\Domain\Administration\Contract\LocalAdministratorStore;
use Formvex\Spoke\Domain\Administration\Exception\AdministratorFailure;
use Formvex\Spoke\Domain\Administration\SessionRecord;
use Formvex\Spoke\Domain\Administration\ThrottleState;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use PDO;
use Throwable;

final class PdoLocalAdministratorStore implements LocalAdministratorStore
{
    public function findAdministrator(PrivateStoragePaths $paths): ?AdministratorRecord
    {
        $connection = $this->connection($paths);
        $statement = $connection->query(
            'SELECT login_identifier, password_hash, must_change_password, session_invalidation_generation, '
            . 'created_at, password_changed_at, last_login_at FROM local_administrators WHERE singleton_id = 1',
        );
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        if (!is_array($row)) {
            throw new AdministratorFailure('administrator_state_invalid');
        }

        return new AdministratorRecord(
            $this->stringValue($row, 'login_identifier'),
            $this->stringValue($row, 'password_hash'),
            $this->integerValue($row, 'must_change_password') === 1,
            $this->integerValue($row, 'session_invalidation_generation'),
            $this->timestampValue($row, 'created_at'),
            $this->nullableTimestampValue($row, 'password_changed_at'),
            $this->nullableTimestampValue($row, 'last_login_at'),
        );
    }

    public function bootstrapAdministrator(
        PrivateStoragePaths $paths,
        string $passwordHash,
        DateTimeImmutable $now,
    ): void {
        $connection = $this->connection($paths);

        try {
            $connection->beginTransaction();
            $existing = $connection->query('SELECT singleton_id FROM local_administrators WHERE singleton_id = 1');

            if ($existing !== false && $existing->fetchColumn() !== false) {
                throw new AdministratorFailure('administrator_already_exists');
            }

            $statement = $connection->prepare(
                'INSERT INTO local_administrators '
                . '(singleton_id, login_identifier, password_hash, must_change_password, '
                . 'session_invalidation_generation, created_at) '
                . 'VALUES (1, :login_identifier, :password_hash, 1, 1, :created_at)',
            );
            $statement->execute([
                'login_identifier' => 'admin',
                'password_hash' => $passwordHash,
                'created_at' => $this->formatTimestamp($now),
            ]);
            $this->insertAudit($connection, 'spoke.administrator.bootstrap_completed', 'success', $now);
            $connection->commit();
        } catch (AdministratorFailure $failure) {
            $this->rollback($connection);
            throw $failure;
        } catch (Throwable) {
            $this->rollback($connection);
            throw new AdministratorFailure('administrator_bootstrap_failed');
        }
    }

    public function resetAdministrator(
        PrivateStoragePaths $paths,
        string $passwordHash,
        DateTimeImmutable $now,
    ): void {
        $connection = $this->connection($paths);

        try {
            $connection->beginTransaction();
            $existing = $connection->query(
                'SELECT session_invalidation_generation FROM local_administrators WHERE singleton_id = 1',
            );
            $generation = $existing === false ? false : $existing->fetchColumn();

            if (!is_int($generation) && !is_string($generation)) {
                throw new AdministratorFailure('administrator_missing');
            }

            $update = $connection->prepare(
                'UPDATE local_administrators SET password_hash = :password_hash, must_change_password = 1, '
                . 'session_invalidation_generation = session_invalidation_generation + 1, '
                . 'password_changed_at = :password_changed_at, last_login_at = NULL WHERE singleton_id = 1',
            );
            $update->execute([
                'password_hash' => $passwordHash,
                'password_changed_at' => $this->formatTimestamp($now),
            ]);
            $this->revokeAll($connection, $now);
            $this->insertAudit($connection, 'spoke.administrator.password_reset_completed', 'success', $now);
            $connection->commit();
        } catch (AdministratorFailure $failure) {
            $this->rollback($connection);
            throw $failure;
        } catch (Throwable) {
            $this->rollback($connection);
            throw new AdministratorFailure('administrator_reset_failed');
        }
    }

    public function recordLoginSuccess(PrivateStoragePaths $paths, DateTimeImmutable $now): void
    {
        $connection = $this->connection($paths);
        $statement = $connection->prepare(
            'UPDATE local_administrators SET last_login_at = :last_login_at WHERE singleton_id = 1',
        );
        $statement->execute(['last_login_at' => $this->formatTimestamp($now)]);

        if ($statement->rowCount() !== 1) {
            throw new AdministratorFailure('administrator_missing');
        }
    }

    public function replacePasswordHash(PrivateStoragePaths $paths, string $passwordHash): void
    {
        $connection = $this->connection($paths);
        $statement = $connection->prepare(
            'UPDATE local_administrators SET password_hash = :password_hash WHERE singleton_id = 1',
        );
        $statement->execute(['password_hash' => $passwordHash]);

        if ($statement->rowCount() !== 1) {
            throw new AdministratorFailure('administrator_missing');
        }
    }

    public function changePassword(
        PrivateStoragePaths $paths,
        string $passwordHash,
        DateTimeImmutable $now,
    ): int {
        $connection = $this->connection($paths);

        try {
            $connection->beginTransaction();
            $update = $connection->prepare(
                'UPDATE local_administrators SET password_hash = :password_hash, must_change_password = 0, '
                . 'session_invalidation_generation = session_invalidation_generation + 1, '
                . 'password_changed_at = :password_changed_at WHERE singleton_id = 1',
            );
            $update->execute([
                'password_hash' => $passwordHash,
                'password_changed_at' => $this->formatTimestamp($now),
            ]);

            if ($update->rowCount() !== 1) {
                throw new AdministratorFailure('administrator_missing');
            }

            $this->revokeAll($connection, $now);
            $generationStatement = $connection->query(
                'SELECT session_invalidation_generation FROM local_administrators WHERE singleton_id = 1',
            );
            $generation = $generationStatement === false ? false : $generationStatement->fetchColumn();

            if (!is_int($generation) && !is_string($generation)) {
                throw new AdministratorFailure('administrator_state_invalid');
            }

            $this->insertAudit($connection, 'spoke.administrator.password_changed', 'success', $now);
            $connection->commit();

            return (int) $generation;
        } catch (AdministratorFailure $failure) {
            $this->rollback($connection);
            throw $failure;
        } catch (Throwable) {
            $this->rollback($connection);
            throw new AdministratorFailure('password_change_failed');
        }
    }

    public function findSession(PrivateStoragePaths $paths, string $sessionIdHash): ?SessionRecord
    {
        $connection = $this->connection($paths);
        $statement = $connection->prepare(
            'SELECT session_id_hash, csrf_token_hash, session_invalidation_generation, created_at, '
            . 'last_activity_at, expires_at, revoked_at FROM admin_sessions WHERE session_id_hash = :session_id_hash',
        );
        $statement->execute(['session_id_hash' => $sessionIdHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        if (!is_array($row)) {
            throw new AdministratorFailure('session_state_invalid');
        }

        return new SessionRecord(
            $this->stringValue($row, 'session_id_hash'),
            $this->stringValue($row, 'csrf_token_hash'),
            $this->integerValue($row, 'session_invalidation_generation'),
            $this->timestampValue($row, 'created_at'),
            $this->timestampValue($row, 'last_activity_at'),
            $this->timestampValue($row, 'expires_at'),
            $row['revoked_at'] !== null,
            false,
        );
    }

    public function saveSession(PrivateStoragePaths $paths, SessionRecord $session): void
    {
        $connection = $this->connection($paths);
        $statement = $connection->prepare(
            'INSERT INTO admin_sessions '
            . '(session_id_hash, administrator_singleton_id, csrf_token_hash, session_invalidation_generation, created_at, '
            . 'last_activity_at, expires_at) VALUES '
            . '(:session_id_hash, 1, :csrf_token_hash, :generation, :created_at, :last_activity_at, :expires_at)',
        );
        $statement->execute([
            'session_id_hash' => $session->sessionIdHash,
            'csrf_token_hash' => $session->csrfTokenHash,
            'generation' => $session->sessionInvalidationGeneration,
            'created_at' => $this->formatTimestamp($session->createdAt),
            'last_activity_at' => $this->formatTimestamp($session->lastActivityAt),
            'expires_at' => $this->formatTimestamp($session->expiresAt),
        ]);
    }

    public function touchSession(
        PrivateStoragePaths $paths,
        string $sessionIdHash,
        DateTimeImmutable $lastActivityAt,
        DateTimeImmutable $expiresAt,
    ): void {
        $connection = $this->connection($paths);
        $statement = $connection->prepare(
            'UPDATE admin_sessions SET last_activity_at = :last_activity_at, expires_at = :expires_at '
            . 'WHERE session_id_hash = :session_id_hash AND revoked_at IS NULL',
        );
        $statement->execute([
            'last_activity_at' => $this->formatTimestamp($lastActivityAt),
            'expires_at' => $this->formatTimestamp($expiresAt),
            'session_id_hash' => $sessionIdHash,
        ]);
    }

    public function revokeSession(PrivateStoragePaths $paths, string $sessionIdHash): void
    {
        $connection = $this->connection($paths);
        $statement = $connection->prepare(
            'UPDATE admin_sessions SET revoked_at = :revoked_at '
            . 'WHERE session_id_hash = :session_id_hash AND revoked_at IS NULL',
        );
        $statement->execute([
            'revoked_at' => $this->formatTimestamp(new DateTimeImmutable('now', new DateTimeZone('UTC'))),
            'session_id_hash' => $sessionIdHash,
        ]);
    }

    public function replaceSessionCsrfToken(
        PrivateStoragePaths $paths,
        string $sessionIdHash,
        string $csrfTokenHash,
    ): void {
        $connection = $this->connection($paths);
        $statement = $connection->prepare(
            'UPDATE admin_sessions SET csrf_token_hash = :csrf_token_hash '
            . 'WHERE session_id_hash = :session_id_hash AND revoked_at IS NULL',
        );
        $statement->execute([
            'csrf_token_hash' => $csrfTokenHash,
            'session_id_hash' => $sessionIdHash,
        ]);
    }

    public function revokeAllSessions(PrivateStoragePaths $paths): void
    {
        $connection = $this->connection($paths);
        $this->revokeAll($connection, new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    public function findThrottleState(PrivateStoragePaths $paths, string $throttleKeyHash): ?ThrottleState
    {
        $connection = $this->connection($paths);
        $statement = $connection->prepare(
            'SELECT failed_attempts, window_started_at, cooldown_until FROM admin_login_throttles '
            . 'WHERE throttle_key_hash = :throttle_key_hash',
        );
        $statement->execute(['throttle_key_hash' => $throttleKeyHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        if (!is_array($row)) {
            throw new AdministratorFailure('throttle_state_invalid');
        }

        return new ThrottleState(
            $this->integerValue($row, 'failed_attempts'),
            $this->timestampValue($row, 'window_started_at'),
            $this->nullableTimestampValue($row, 'cooldown_until'),
        );
    }

    public function recordLoginFailure(
        PrivateStoragePaths $paths,
        string $throttleKeyHash,
        DateTimeImmutable $now,
        int $windowSeconds,
        int $maximumFailures,
        int $cooldownSeconds,
    ): ThrottleState {
        $connection = $this->connection($paths);

        try {
            $connection->beginTransaction();
            $state = $this->findThrottleStateOnConnection($connection, $throttleKeyHash);
            $windowStartedAt = $state?->windowStartedAt;
            $failedAttempts = $state === null ? 0 : $state->failedAttempts;

            if ($windowStartedAt === null || $now->getTimestamp() - $windowStartedAt->getTimestamp() >= $windowSeconds) {
                $windowStartedAt = $now;
                $failedAttempts = 0;
            }

            $failedAttempts++;
            $cooldownUntil = $failedAttempts >= $maximumFailures
                ? $now->add(new DateInterval('PT' . $cooldownSeconds . 'S'))
                : null;
            $statement = $connection->prepare(
                'INSERT INTO admin_login_throttles '
                . '(throttle_key_hash, failed_attempts, window_started_at, cooldown_until, updated_at) '
                . 'VALUES (:throttle_key_hash, :failed_attempts, :window_started_at, :cooldown_until, :updated_at) '
                . 'ON CONFLICT(throttle_key_hash) DO UPDATE SET failed_attempts = excluded.failed_attempts, '
                . 'window_started_at = excluded.window_started_at, cooldown_until = excluded.cooldown_until, '
                . 'updated_at = excluded.updated_at',
            );
            $statement->execute([
                'throttle_key_hash' => $throttleKeyHash,
                'failed_attempts' => $failedAttempts,
                'window_started_at' => $this->formatTimestamp($windowStartedAt),
                'cooldown_until' => $cooldownUntil === null ? null : $this->formatTimestamp($cooldownUntil),
                'updated_at' => $this->formatTimestamp($now),
            ]);
            $connection->commit();

            return new ThrottleState($failedAttempts, $windowStartedAt, $cooldownUntil);
        } catch (AdministratorFailure $failure) {
            $this->rollback($connection);
            throw $failure;
        } catch (Throwable) {
            $this->rollback($connection);
            throw new AdministratorFailure('throttle_update_failed');
        }
    }

    public function clearLoginFailures(PrivateStoragePaths $paths, string $throttleKeyHash): void
    {
        $connection = $this->connection($paths);
        $statement = $connection->prepare(
            'DELETE FROM admin_login_throttles WHERE throttle_key_hash = :throttle_key_hash',
        );
        $statement->execute(['throttle_key_hash' => $throttleKeyHash]);
    }

    public function recordAuditEvent(
        PrivateStoragePaths $paths,
        string $eventName,
        string $outcome,
        DateTimeImmutable $occurredAt,
    ): void {
        $connection = $this->connection($paths);
        $this->insertAudit($connection, $eventName, $outcome, $occurredAt);
    }

    private function connection(PrivateStoragePaths $paths): PDO
    {
        if (!is_file($paths->databaseFile())) {
            throw new AdministratorFailure('installation_required');
        }

        try {
            $connection = new PDO('sqlite:' . $paths->databaseFile(), null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $connection->exec('PRAGMA foreign_keys = ON');

            return $connection;
        } catch (Throwable) {
            throw new AdministratorFailure('database_unavailable');
        }
    }

    private function findThrottleStateOnConnection(PDO $connection, string $throttleKeyHash): ?ThrottleState
    {
        $statement = $connection->prepare(
            'SELECT failed_attempts, window_started_at, cooldown_until FROM admin_login_throttles '
            . 'WHERE throttle_key_hash = :throttle_key_hash',
        );
        $statement->execute(['throttle_key_hash' => $throttleKeyHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        if (!is_array($row)) {
            throw new AdministratorFailure('throttle_state_invalid');
        }

        return new ThrottleState(
            $this->integerValue($row, 'failed_attempts'),
            $this->timestampValue($row, 'window_started_at'),
            $this->nullableTimestampValue($row, 'cooldown_until'),
        );
    }

    private function revokeAll(PDO $connection, DateTimeImmutable $now): void
    {
        $statement = $connection->prepare(
            'UPDATE admin_sessions SET revoked_at = :revoked_at WHERE revoked_at IS NULL',
        );
        $statement->execute(['revoked_at' => $this->formatTimestamp($now)]);
    }

    private function insertAudit(PDO $connection, string $eventName, string $outcome, DateTimeImmutable $occurredAt): void
    {
        $statement = $connection->prepare(
            'INSERT INTO audit_events (event_name, outcome, occurred_at) '
            . 'VALUES (:event_name, :outcome, :occurred_at)',
        );
        $statement->execute([
            'event_name' => $eventName,
            'outcome' => $outcome,
            'occurred_at' => $this->formatTimestamp($occurredAt),
        ]);
    }

    private function rollback(PDO $connection): void
    {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
    }

    /**
     * @param array<mixed, mixed> $row
     */
    private function stringValue(array $row, string $key): string
    {
        if (!isset($row[$key]) || !is_string($row[$key]) || $row[$key] === '') {
            throw new AdministratorFailure('administrator_state_invalid');
        }

        return $row[$key];
    }

    /**
     * @param array<mixed, mixed> $row
     */
    private function integerValue(array $row, string $key): int
    {
        if (!isset($row[$key]) || (!is_int($row[$key]) && !is_string($row[$key]) && !is_float($row[$key]))) {
            throw new AdministratorFailure('administrator_state_invalid');
        }

        return (int) $row[$key];
    }

    /**
     * @param array<mixed, mixed> $row
     */
    private function timestampValue(array $row, string $key): DateTimeImmutable
    {
        $value = $this->stringValue($row, $key);

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new AdministratorFailure('administrator_state_invalid');
        }
    }

    /**
     * @param array<mixed, mixed> $row
     */
    private function nullableTimestampValue(array $row, string $key): ?DateTimeImmutable
    {
        if ($row[$key] === null) {
            return null;
        }

        return $this->timestampValue($row, $key);
    }

    private function formatTimestamp(DateTimeImmutable $timestamp): string
    {
        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
