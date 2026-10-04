<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Release\Contract\ReleaseCheckRepository;
use Formvex\Spoke\Domain\Release\ReleaseCheckSettings;
use Formvex\Spoke\Domain\Release\ReleaseCheckState;
use PDO;
use RuntimeException;
use Throwable;

final class PdoReleaseCheckRepository implements ReleaseCheckRepository
{
    public function settings(PrivateStoragePaths $paths): ReleaseCheckSettings
    {
        $row = $this->row($paths, 'SELECT enabled, updated_at FROM release_check_settings WHERE singleton_id = 1');

        return new ReleaseCheckSettings($this->integerValue($row, 'enabled') === 1, $this->date($this->stringValue($row, 'updated_at')));
    }

    public function saveSettings(PrivateStoragePaths $paths, bool $enabled, DateTimeImmutable $now): void
    {
        $connection = $this->connection($paths);
        try {
            $connection->beginTransaction();
            $timestamp = $this->timestamp($now);
            $statement = $connection->prepare('UPDATE release_check_settings SET enabled = :enabled, updated_at = :updated_at WHERE singleton_id = 1');
            $statement->execute(['enabled' => $enabled ? 1 : 0, 'updated_at' => $timestamp]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('release_check_settings_save_failed');
            }
            $audit = $connection->prepare(
                "INSERT INTO audit_events (event_name, outcome, occurred_at, metadata_json) VALUES ('spoke.release_check.settings_changed', 'success', :occurred_at, :metadata_json)",
            );
            $audit->execute(['occurred_at' => $timestamp, 'metadata_json' => json_encode(['enabled' => $enabled], JSON_THROW_ON_ERROR)]);
            $connection->commit();
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $failure;
        }
    }

    public function state(PrivateStoragePaths $paths): ReleaseCheckState
    {
        $row = $this->row($paths, 'SELECT * FROM release_check_state WHERE singleton_id = 1');

        return new ReleaseCheckState(
            $this->stringValue($row, 'status'),
            $this->stringValue($row, 'current_version'),
            $this->nullableString($row['available_version'] ?? null),
            $this->stringValue($row, 'severity'),
            $this->nullableString($row['minimum_supported_version'] ?? null),
            $this->nullableString($row['release_notes_url'] ?? null),
            $this->nullableString($row['package_url'] ?? null),
            $this->nullableString($row['package_sha256'] ?? null),
            $this->nullableDate($row['published_at'] ?? null),
            $this->nullableDate($row['last_attempt_at'] ?? null),
            $this->nullableDate($row['last_successful_at'] ?? null),
            $this->nullableString($row['failure_code'] ?? null),
            $this->nullableString($row['failure_message'] ?? null),
            $this->nullableString($row['acknowledged_version'] ?? null),
            $this->nullableDate($row['acknowledged_at'] ?? null),
        );
    }

    public function saveState(PrivateStoragePaths $paths, ReleaseCheckState $state, DateTimeImmutable $now): void
    {
        $connection = $this->connection($paths);
        try {
            $connection->beginTransaction();
            $statement = $connection->prepare(
                'UPDATE release_check_state SET status = :status, current_version = :current_version, available_version = :available_version, severity = :severity, minimum_supported_version = :minimum_supported_version, release_notes_url = :release_notes_url, package_url = :package_url, package_sha256 = :package_sha256, published_at = :published_at, last_attempt_at = :last_attempt_at, last_successful_at = :last_successful_at, failure_code = :failure_code, failure_message = :failure_message, acknowledged_version = :acknowledged_version, acknowledged_at = :acknowledged_at, updated_at = :updated_at WHERE singleton_id = 1',
            );
            $statement->execute($this->stateParameters($state, $now));
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('release_check_state_save_failed');
            }
            $audit = $connection->prepare(
                "INSERT INTO audit_events (event_name, outcome, occurred_at, metadata_json) VALUES ('spoke.release_check.completed', 'success', :occurred_at, :metadata_json)",
            );
            $audit->execute(['occurred_at' => $this->timestamp($now), 'metadata_json' => json_encode(['status' => $state->status], JSON_THROW_ON_ERROR)]);
            $connection->commit();
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $failure;
        }
    }

    public function acknowledge(PrivateStoragePaths $paths, string $releaseVersion, string $actor, DateTimeImmutable $now): void
    {
        $connection = $this->connection($paths);
        try {
            $connection->beginTransaction();
            $statement = $connection->prepare(
                "UPDATE release_check_state SET acknowledged_version = :release_version, acknowledged_at = :acknowledged_at, updated_at = :updated_at WHERE singleton_id = 1 AND status = 'available' AND available_version = :release_version AND severity IN ('normal', 'important')",
            );
            $timestamp = $this->timestamp($now);
            $statement->execute(['release_version' => $releaseVersion, 'acknowledged_at' => $timestamp, 'updated_at' => $timestamp]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('release_acknowledgement_invalid');
            }
            $audit = $connection->prepare(
                "INSERT INTO audit_events (event_name, outcome, occurred_at, resource_type, resource_public_id, metadata_json) VALUES ('spoke.release_check.acknowledged', 'success', :occurred_at, 'release', :release_version, :metadata_json)",
            );
            $audit->execute(['occurred_at' => $timestamp, 'release_version' => $releaseVersion, 'metadata_json' => json_encode(['actor' => $actor], JSON_THROW_ON_ERROR)]);
            $connection->commit();
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $failure;
        }
    }

    /** @return array<string, mixed> */
    private function stateParameters(ReleaseCheckState $state, DateTimeImmutable $now): array
    {
        return [
            'status' => $state->status,
            'current_version' => $state->currentVersion,
            'available_version' => $state->availableVersion,
            'severity' => $state->severity,
            'minimum_supported_version' => $state->minimumSupportedVersion,
            'release_notes_url' => $state->releaseNotesUrl,
            'package_url' => $state->packageUrl,
            'package_sha256' => $state->packageSha256,
            'published_at' => $state->publishedAt === null ? null : $this->timestamp($state->publishedAt),
            'last_attempt_at' => $state->lastAttemptAt === null ? null : $this->timestamp($state->lastAttemptAt),
            'last_successful_at' => $state->lastSuccessfulAt === null ? null : $this->timestamp($state->lastSuccessfulAt),
            'failure_code' => $state->failureCode,
            'failure_message' => $state->failureMessage,
            'acknowledged_version' => $state->acknowledgedVersion,
            'acknowledged_at' => $state->acknowledgedAt === null ? null : $this->timestamp($state->acknowledgedAt),
            'updated_at' => $this->timestamp($now),
        ];
    }

    /** @return array<string, mixed> */
    private function row(PrivateStoragePaths $paths, string $sql): array
    {
        $statement = $this->connection($paths)->query($sql);
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('release_check_state_missing');
        }

        $normalized = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function connection(PrivateStoragePaths $paths): PDO
    {
        $connection = new PDO('sqlite:' . $paths->databaseFile(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        $connection->exec('PRAGMA foreign_keys = ON');

        return $connection;
    }

    private function timestamp(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    private function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function nullableDate(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : $this->date($this->nullableString($value) ?? '');
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new RuntimeException('release_check_state_invalid');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function stringValue(array $row, string $key): string
    {
        if (!isset($row[$key]) || !is_string($row[$key])) {
            throw new RuntimeException('release_check_state_invalid');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function integerValue(array $row, string $key): int
    {
        if (!isset($row[$key]) || (!is_int($row[$key]) && !is_string($row[$key]))) {
            throw new RuntimeException('release_check_state_invalid');
        }

        return (int) $row[$key];
    }
}
