<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Abuse\CaptchaOutageState;
use Formvex\Spoke\Domain\Abuse\CaptchaOutageTransition;
use Formvex\Spoke\Domain\Abuse\Contract\AbuseSettingsStore;
use Formvex\Spoke\Domain\Abuse\Contract\CaptchaOutageStore;
use Formvex\Spoke\Domain\Abuse\Contract\RateLimitStore;
use Formvex\Spoke\Domain\Abuse\RateLimitResult;
use Formvex\Spoke\Domain\Abuse\SubmissionAbuseSettings;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\InstallationSettings\Exception\InstallationSettingsFailure;
use PDO;
use PDOStatement;
use Throwable;

final class PdoAbuseStore implements AbuseSettingsStore, RateLimitStore, CaptchaOutageStore
{
    public function get(PrivateStoragePaths $paths): SubmissionAbuseSettings
    {
        $connection = $this->connection($paths);
        $statement = $connection->query('SELECT * FROM submission_abuse_settings WHERE singleton_id = 1');
        $row = $statement === false ? false : $this->fetchRow($statement);

        if (!is_array($row)) {
            throw new InstallationSettingsFailure('abuse_settings_missing', 'Formvex could not load abuse-control settings. Run the installation repair procedure before continuing.');
        }

        $cidrs = $this->stringValue($row, 'trusted_proxy_cidrs');
        $trusted = $cidrs === '' ? [] : array_values(array_filter(array_map('trim', explode("\n", $cidrs)), static fn (string $value): bool => $value !== ''));

        try {
            return new SubmissionAbuseSettings(
                $this->integerValue($row, 'per_form_short_limit'),
                $this->integerValue($row, 'per_form_short_window_seconds'),
                $this->integerValue($row, 'per_form_hour_limit'),
                $this->integerValue($row, 'per_form_hour_window_seconds'),
                $this->integerValue($row, 'installation_hour_limit'),
                $this->integerValue($row, 'installation_hour_window_seconds'),
                $this->integerValue($row, 'flood_minute_limit'),
                $this->integerValue($row, 'flood_minute_window_seconds'),
                $this->integerValue($row, 'flood_hour_limit'),
                $this->integerValue($row, 'flood_hour_window_seconds'),
                $trusted,
            );
        } catch (InstallationSettingsFailure $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new InstallationSettingsFailure('abuse_settings_invalid', 'Formvex found invalid saved abuse-control settings.');
        }
    }

    public function save(PrivateStoragePaths $paths, SubmissionAbuseSettings $settings, DateTimeImmutable $now): void
    {
        $connection = $this->connection($paths);
        $statement = $connection->prepare(
            'UPDATE submission_abuse_settings SET per_form_short_limit = :per_form_short_limit, '
            . 'per_form_short_window_seconds = :per_form_short_window_seconds, per_form_hour_limit = :per_form_hour_limit, '
            . 'per_form_hour_window_seconds = :per_form_hour_window_seconds, installation_hour_limit = :installation_hour_limit, '
            . 'installation_hour_window_seconds = :installation_hour_window_seconds, flood_minute_limit = :flood_minute_limit, '
            . 'flood_minute_window_seconds = :flood_minute_window_seconds, flood_hour_limit = :flood_hour_limit, '
            . 'flood_hour_window_seconds = :flood_hour_window_seconds, trusted_proxy_cidrs = :trusted_proxy_cidrs, updated_at = :updated_at '
            . 'WHERE singleton_id = 1',
        );
        $statement->execute([
            'per_form_short_limit' => $settings->perFormShortLimit,
            'per_form_short_window_seconds' => $settings->perFormShortWindowSeconds,
            'per_form_hour_limit' => $settings->perFormHourLimit,
            'per_form_hour_window_seconds' => $settings->perFormHourWindowSeconds,
            'installation_hour_limit' => $settings->installationHourLimit,
            'installation_hour_window_seconds' => $settings->installationHourWindowSeconds,
            'flood_minute_limit' => $settings->floodMinuteLimit,
            'flood_minute_window_seconds' => $settings->floodMinuteWindowSeconds,
            'flood_hour_limit' => $settings->floodHourLimit,
            'flood_hour_window_seconds' => $settings->floodHourWindowSeconds,
            'trusted_proxy_cidrs' => implode("\n", $settings->normalizedTrustedProxyCidrs()),
            'updated_at' => $this->formatTimestamp($now),
        ]);

        if ($statement->rowCount() !== 1) {
            throw new InstallationSettingsFailure('abuse_settings_save_failed', 'Formvex could not save abuse-control settings. The previous values remain active.');
        }
    }

    public function recordAudit(PrivateStoragePaths $paths, string $eventName, string $outcome, DateTimeImmutable $occurredAt): void
    {
        $connection = $this->connection($paths);
        $statement = $connection->prepare('INSERT INTO audit_events (event_name, outcome, occurred_at) VALUES (:event_name, :outcome, :occurred_at)');
        $statement->execute([
            'event_name' => $eventName,
            'outcome' => $outcome,
            'occurred_at' => $this->formatTimestamp($occurredAt),
        ]);
    }

    public function consumeFlood(PrivateStoragePaths $paths, SubmissionAbuseSettings $settings, string $identity, DateTimeImmutable $now): RateLimitResult
    {
        return $this->consume($paths, $now, [
            ['flood_minute', $settings->floodMinuteLimit, $settings->floodMinuteWindowSeconds, ''],
            ['flood_hour', $settings->floodHourLimit, $settings->floodHourWindowSeconds, ''],
        ], $identity);
    }

    public function consumeAttempt(PrivateStoragePaths $paths, SubmissionAbuseSettings $settings, string $identity, string $publicFormId, DateTimeImmutable $now): RateLimitResult
    {
        return $this->consume($paths, $now, [
            ['attempt_form_short', $settings->perFormShortLimit, $settings->perFormShortWindowSeconds, $publicFormId],
            ['attempt_form_hour', $settings->perFormHourLimit, $settings->perFormHourWindowSeconds, $publicFormId],
            ['attempt_installation_hour', $settings->installationHourLimit, $settings->installationHourWindowSeconds, ''],
        ], $identity);
    }

    public function clearCounters(PrivateStoragePaths $paths): void
    {
        $this->connection($paths)->exec('DELETE FROM abuse_rate_counters');
    }

    public function cleanup(PrivateStoragePaths $paths, SubmissionAbuseSettings $settings, DateTimeImmutable $now): int
    {
        $longestWindow = max(
            $settings->perFormShortWindowSeconds,
            $settings->perFormHourWindowSeconds,
            $settings->installationHourWindowSeconds,
            $settings->floodMinuteWindowSeconds,
            $settings->floodHourWindowSeconds,
        );
        $cutoff = $now->getTimestamp() - $longestWindow - 3600;
        $statement = $this->connection($paths)->prepare('DELETE FROM abuse_rate_counters WHERE bucket_start < :cutoff');
        $statement->execute(['cutoff' => $cutoff]);

        return $statement->rowCount();
    }

    public function getOutageState(PrivateStoragePaths $paths): CaptchaOutageState
    {
        $connection = $this->connection($paths);
        $statement = $connection->query('SELECT * FROM captcha_outage_state WHERE singleton_id = 1');
        $row = $statement === false ? false : $this->fetchRow($statement);

        return is_array($row) ? $this->outage($row) : CaptchaOutageState::clear();
    }

    public function recordFailure(PrivateStoragePaths $paths, string $failureCode, DateTimeImmutable $now): CaptchaOutageTransition
    {
        $connection = $this->connection($paths);
        $connection->exec('BEGIN IMMEDIATE TRANSACTION');

        try {
            $state = $this->getOutageStateFromConnection($connection);
            $event = null;
            $openedAt = $state->openedAt ?? $now;
            $lastAlertAt = $state->lastAlertAt;

            if (!$state->open) {
                $event = 'spoke.abuse.captcha_outage_opened';
                $lastAlertAt = $now;
            } elseif ($lastAlertAt === null || $lastAlertAt->getTimestamp() <= $now->getTimestamp() - 3600) {
                $event = 'spoke.abuse.captcha_outage_reminder_due';
                $lastAlertAt = $now;
            }

            $updated = $this->updateOutage($connection, true, $openedAt, $now, $failureCode, $lastAlertAt, $state->lastSuccessAt);
            if ($event !== null) {
                $this->enqueueOperationalAlert($connection, $event, $now);
            }
            $connection->commit();

            return new CaptchaOutageTransition($updated, $event);
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            if ($failure instanceof InstallationSettingsFailure) {
                throw $failure;
            }

            throw new InstallationSettingsFailure('abuse_state_save_failed', 'Formvex could not save CAPTCHA availability state. The enabled CAPTCHA requirement remains active.');
        }
    }

    public function recordSuccess(PrivateStoragePaths $paths, DateTimeImmutable $now): CaptchaOutageTransition
    {
        $connection = $this->connection($paths);
        $connection->exec('BEGIN IMMEDIATE TRANSACTION');

        try {
            $state = $this->getOutageStateFromConnection($connection);
            $event = $state->open ? 'spoke.abuse.captcha_outage_recovered' : null;
            $updated = $this->updateOutage($connection, false, $state->openedAt, $state->lastFailureAt, $state->lastFailureCode, $state->lastAlertAt, $now);
            if ($event !== null) {
                $this->enqueueOperationalAlert($connection, $event, $now);
            }
            $connection->commit();

            return new CaptchaOutageTransition($updated, $event);
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            if ($failure instanceof InstallationSettingsFailure) {
                throw $failure;
            }

            throw new InstallationSettingsFailure('abuse_state_save_failed', 'Formvex could not save CAPTCHA availability state.');
        }
    }

    /** @param list<array{0: string, 1: int, 2: int, 3: string}> $windows */
    private function consume(PrivateStoragePaths $paths, DateTimeImmutable $now, array $windows, string $identity): RateLimitResult
    {
        $connection = $this->connection($paths);
        $connection->exec('BEGIN IMMEDIATE TRANSACTION');
        $nowSeconds = $now->getTimestamp();
        $limited = [];

        try {
            foreach ($windows as [$scope, $limit, $windowSeconds, $formPublicId]) {
                $bucketStart = intdiv($nowSeconds, $windowSeconds) * $windowSeconds;
                $statement = $connection->prepare(
                    'SELECT count FROM abuse_rate_counters WHERE scope = :scope AND identity = :identity AND form_public_id = :form_public_id AND bucket_start = :bucket_start AND window_seconds = :window_seconds LIMIT 1',
                );
                $statement->execute([
                    'scope' => $scope,
                    'identity' => $identity,
                    'form_public_id' => $formPublicId,
                    'bucket_start' => $bucketStart,
                    'window_seconds' => $windowSeconds,
                ]);
                $count = $statement->fetchColumn();

                if (($count === false ? 0 : (int) $count) >= $limit) {
                    $limited[] = $bucketStart + $windowSeconds - $nowSeconds;
                }
            }

            if ($limited !== []) {
                $connection->rollBack();

                return RateLimitResult::limited(max(1, min($limited)));
            }

            foreach ($windows as [$scope, $_limit, $windowSeconds, $formPublicId]) {
                $bucketStart = intdiv($nowSeconds, $windowSeconds) * $windowSeconds;
                $update = $connection->prepare(
                    'UPDATE abuse_rate_counters SET count = count + 1, updated_at = :updated_at WHERE scope = :scope AND identity = :identity AND form_public_id = :form_public_id AND bucket_start = :bucket_start AND window_seconds = :window_seconds',
                );
                $update->execute([
                    'updated_at' => $this->formatTimestamp($now),
                    'scope' => $scope,
                    'identity' => $identity,
                    'form_public_id' => $formPublicId,
                    'bucket_start' => $bucketStart,
                    'window_seconds' => $windowSeconds,
                ]);

                if ($update->rowCount() === 0) {
                    $insert = $connection->prepare(
                        'INSERT INTO abuse_rate_counters (scope, identity, form_public_id, bucket_start, window_seconds, count, updated_at) VALUES (:scope, :identity, :form_public_id, :bucket_start, :window_seconds, 1, :updated_at)',
                    );
                    $insert->execute([
                        'scope' => $scope,
                        'identity' => $identity,
                        'form_public_id' => $formPublicId,
                        'bucket_start' => $bucketStart,
                        'window_seconds' => $windowSeconds,
                        'updated_at' => $this->formatTimestamp($now),
                    ]);
                }
            }

            $connection->commit();

            return RateLimitResult::allowed();
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            if ($failure instanceof InstallationSettingsFailure) {
                throw $failure;
            }

            throw new InstallationSettingsFailure('abuse_counter_unavailable', 'Formvex could not update its abuse-protection counters. Your message was not accepted. Please try again later.');
        }
    }

    /** @return array<string, mixed> */
    private function getOutageRow(PDO $connection): array
    {
        $statement = $connection->query('SELECT * FROM captcha_outage_state WHERE singleton_id = 1');
        $row = $statement === false ? false : $this->fetchRow($statement);

        if (!is_array($row)) {
            throw new InstallationSettingsFailure('abuse_state_missing', 'Formvex could not load CAPTCHA availability state.');
        }

        return $row;
    }

    /** @return array<string, mixed>|false */
    private function fetchRow(PDOStatement $statement): array|false
    {
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return false;
        }

        $normalized = [];

        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function getOutageStateFromConnection(PDO $connection): CaptchaOutageState
    {
        return $this->outage($this->getOutageRow($connection));
    }

    private function updateOutage(PDO $connection, bool $open, ?DateTimeImmutable $openedAt, ?DateTimeImmutable $failureAt, ?string $failureCode, ?DateTimeImmutable $alertAt, ?DateTimeImmutable $successAt): CaptchaOutageState
    {
        $statement = $connection->prepare(
            'UPDATE captcha_outage_state SET is_open = :is_open, opened_at = :opened_at, last_failure_at = :last_failure_at, last_failure_code = :last_failure_code, last_alert_at = :last_alert_at, last_success_at = :last_success_at WHERE singleton_id = 1',
        );
        $statement->execute([
            'is_open' => $open ? 1 : 0,
            'opened_at' => $openedAt === null ? null : $this->formatTimestamp($openedAt),
            'last_failure_at' => $failureAt === null ? null : $this->formatTimestamp($failureAt),
            'last_failure_code' => $failureCode,
            'last_alert_at' => $alertAt === null ? null : $this->formatTimestamp($alertAt),
            'last_success_at' => $successAt === null ? null : $this->formatTimestamp($successAt),
        ]);

        return new CaptchaOutageState($open, $openedAt, $failureAt, $failureCode, $alertAt, $successAt);
    }

    private function enqueueOperationalAlert(PDO $connection, string $eventCode, DateTimeImmutable $occurredAt): void
    {
        $table = $connection->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'delivery_alerts' LIMIT 1");
        if ($table === false || $table->fetchColumn() === false) {
            return;
        }

        $timestamp = $this->formatTimestamp($occurredAt);
        $statement = $connection->prepare("INSERT OR IGNORE INTO delivery_alerts (event_code, state, attempt_count, due_at, created_at, updated_at) VALUES (:event_code, 'queued', 0, :due_at, :created_at, :updated_at)");
        $statement->execute(['event_code' => $eventCode, 'due_at' => $timestamp, 'created_at' => $timestamp, 'updated_at' => $timestamp]);
    }

    /** @param array<string, mixed> $row */
    private function outage(array $row): CaptchaOutageState
    {
        return new CaptchaOutageState(
            $this->integerValue($row, 'is_open') === 1,
            $this->nullableTimestamp($row['opened_at'] ?? null),
            $this->nullableTimestamp($row['last_failure_at'] ?? null),
            $row['last_failure_code'] === null ? null : $this->stringValue($row, 'last_failure_code'),
            $this->nullableTimestamp($row['last_alert_at'] ?? null),
            $this->nullableTimestamp($row['last_success_at'] ?? null),
        );
    }

    private function connection(PrivateStoragePaths $paths): PDO
    {
        if (!is_file($paths->databaseFile())) {
            throw new InstallationSettingsFailure('installation_required', 'Formvex is not initialized. Run the installation command before opening abuse controls.');
        }

        try {
            $connection = new PDO('sqlite:' . $paths->databaseFile(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
            $connection->exec('PRAGMA foreign_keys = ON');
            $connection->exec('PRAGMA busy_timeout = 5000');

            return $connection;
        } catch (Throwable) {
            throw new InstallationSettingsFailure('database_unavailable', 'Formvex could not access its local database. Check private storage and try again.');
        }
    }

    /** @param array<string, mixed> $row */
    private function stringValue(array $row, string $key): string
    {
        if (!isset($row[$key]) || !is_string($row[$key])) {
            throw new InstallationSettingsFailure('abuse_settings_invalid', 'Formvex found an invalid value in saved abuse-control state.');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function integerValue(array $row, string $key): int
    {
        if (!isset($row[$key]) || (!is_int($row[$key]) && !is_string($row[$key]) && !is_float($row[$key]))) {
            throw new InstallationSettingsFailure('abuse_settings_invalid', 'Formvex found an invalid numeric value in saved abuse-control state.');
        }

        return (int) $row[$key];
    }

    private function nullableTimestamp(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new InstallationSettingsFailure('abuse_state_invalid', 'Formvex found an invalid CAPTCHA availability timestamp.');
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new InstallationSettingsFailure('abuse_state_invalid', 'Formvex found an invalid CAPTCHA availability timestamp.');
        }
    }

    private function formatTimestamp(DateTimeImmutable $timestamp): string
    {
        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');
    }
}
