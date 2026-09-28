<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\InstallationSettings\Contract\InstallationSettingsStore;
use Formvex\Spoke\Domain\InstallationSettings\Exception\InstallationSettingsFailure;
use Formvex\Spoke\Domain\InstallationSettings\InstallationSettings;
use Formvex\Spoke\Domain\InstallationSettings\LoginThrottleSettings;
use Formvex\Spoke\Domain\InstallationSettings\SmtpEncryption;
use Formvex\Spoke\Domain\InstallationSettings\SmtpTestState;
use Formvex\Spoke\Domain\InstallationSettings\SmtpTestStatus;
use PDO;
use Throwable;
use ValueError;

final class PdoInstallationSettingsStore implements InstallationSettingsStore
{
    public function get(PrivateStoragePaths $paths): InstallationSettings
    {
        $connection = $this->connection($paths);
        $statement = $connection->query('SELECT * FROM installation_settings WHERE singleton_id = 1');
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new InstallationSettingsFailure('settings_state_missing', 'Formvex could not load installation settings. Run the installation repair procedure before continuing.');
        }

        try {
            return new InstallationSettings(
                $this->stringValue($row, 'website_display_name'),
                $this->stringValue($row, 'bare_domain'),
                $this->nullableStringValue($row, 'www_alias'),
                $this->stringValue($row, 'operational_alert_email'),
                $this->stringValue($row, 'sender_email'),
                $this->nullableStringValue($row, 'sender_name'),
                $this->stringValue($row, 'smtp_host'),
                $this->integerValue($row, 'smtp_port'),
                SmtpEncryption::fromInput($this->stringValue($row, 'smtp_encryption')),
                $this->stringValue($row, 'smtp_username'),
                $this->integerValue($row, 'smtp_timeout_seconds'),
                new LoginThrottleSettings(
                    $this->integerValue($row, 'maximum_login_failures'),
                    $this->integerValue($row, 'login_window_minutes'),
                    $this->integerValue($row, 'login_cooldown_minutes'),
                ),
                $this->integerValue($row, 'smtp_configuration_revision'),
                $this->stringValue($row, 'smtp_secret_slot'),
            );
        } catch (InstallationSettingsFailure $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new InstallationSettingsFailure('settings_state_invalid', 'Formvex could not read the saved installation settings because their stored values are invalid.');
        }
    }

    public function save(PrivateStoragePaths $paths, InstallationSettings $settings, DateTimeImmutable $now): void
    {
        $connection = $this->connection($paths);

        try {
            $connection->beginTransaction();
            $statement = $connection->prepare(
                'UPDATE installation_settings SET website_display_name = :website_display_name, '
                . 'bare_domain = :bare_domain, www_alias = :www_alias, '
                . 'operational_alert_email = :operational_alert_email, sender_email = :sender_email, '
                . 'sender_name = :sender_name, smtp_host = :smtp_host, smtp_port = :smtp_port, '
                . 'smtp_encryption = :smtp_encryption, smtp_username = :smtp_username, '
                . 'smtp_timeout_seconds = :smtp_timeout_seconds, maximum_login_failures = :maximum_login_failures, '
                . 'login_window_minutes = :login_window_minutes, login_cooldown_minutes = :login_cooldown_minutes, '
                . 'smtp_configuration_revision = :smtp_configuration_revision, smtp_secret_slot = :smtp_secret_slot, '
                . 'updated_at = :updated_at WHERE singleton_id = 1',
            );
            $statement->execute([
                'website_display_name' => $settings->websiteDisplayName,
                'bare_domain' => $settings->bareDomain,
                'www_alias' => $settings->wwwAlias,
                'operational_alert_email' => $settings->operationalAlertEmail,
                'sender_email' => $settings->senderEmail,
                'sender_name' => $settings->senderName,
                'smtp_host' => $settings->smtpHost,
                'smtp_port' => $settings->smtpPort,
                'smtp_encryption' => $settings->smtpEncryption->value,
                'smtp_username' => $settings->smtpUsername,
                'smtp_timeout_seconds' => $settings->smtpTimeoutSeconds,
                'maximum_login_failures' => $settings->loginThrottle->maximumFailures,
                'login_window_minutes' => $settings->loginThrottle->windowMinutes,
                'login_cooldown_minutes' => $settings->loginThrottle->cooldownMinutes,
                'smtp_configuration_revision' => $settings->smtpConfigurationRevision,
                'smtp_secret_slot' => $settings->smtpSecretSlot,
                'updated_at' => $this->formatTimestamp($now),
            ]);

            if ($statement->rowCount() !== 1) {
                throw new InstallationSettingsFailure('settings_save_failed', 'Formvex could not save the installation settings. The previous settings remain active.');
            }

            $connection->commit();
        } catch (InstallationSettingsFailure $failure) {
            $this->rollback($connection);
            throw $failure;
        } catch (Throwable) {
            $this->rollback($connection);
            throw new InstallationSettingsFailure('settings_save_failed', 'Formvex could not save the installation settings. The previous settings remain active.');
        }
    }

    public function getTestState(PrivateStoragePaths $paths): SmtpTestState
    {
        $connection = $this->connection($paths);
        $statement = $connection->query('SELECT * FROM smtp_test_state WHERE singleton_id = 1');
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return SmtpTestState::notConfigured();
        }

        try {
            $status = SmtpTestStatus::from($this->stringValue($row, 'status'));
        } catch (ValueError) {
            throw new InstallationSettingsFailure('settings_state_invalid', 'Formvex found an invalid status in the saved SMTP test state.');
        }

        return new SmtpTestState(
            $status,
            $this->integerValue($row, 'tested_revision'),
            $this->nullableStringValue($row, 'failure_code'),
            $this->nullableStringValue($row, 'summary'),
            $this->nullableTimestampValue($row, 'requested_at'),
            $this->nullableTimestampValue($row, 'completed_at'),
            $this->nullableTimestampValue($row, 'cooldown_until'),
        );
    }

    public function saveTestState(PrivateStoragePaths $paths, SmtpTestState $state): void
    {
        $connection = $this->connection($paths);
        $statement = $connection->prepare(
            'UPDATE smtp_test_state SET status = :status, tested_revision = :tested_revision, '
            . 'failure_code = :failure_code, summary = :summary, requested_at = :requested_at, '
            . 'completed_at = :completed_at, cooldown_until = :cooldown_until WHERE singleton_id = 1',
        );
        $statement->execute([
            'status' => $state->status->value,
            'tested_revision' => $state->testedRevision,
            'failure_code' => $state->failureCode,
            'summary' => $state->summary,
            'requested_at' => $state->requestedAt === null ? null : $this->formatTimestamp($state->requestedAt),
            'completed_at' => $state->completedAt === null ? null : $this->formatTimestamp($state->completedAt),
            'cooldown_until' => $state->cooldownUntil === null ? null : $this->formatTimestamp($state->cooldownUntil),
        ]);

        if ($statement->rowCount() !== 1) {
            throw new InstallationSettingsFailure('smtp_test_state_save_failed', 'Formvex could not save the SMTP test result. Run the test again after checking the local database.');
        }
    }

    public function recordAudit(
        PrivateStoragePaths $paths,
        string $eventName,
        string $outcome,
        DateTimeImmutable $occurredAt,
    ): void {
        $connection = $this->connection($paths);
        $statement = $connection->prepare(
            'INSERT INTO audit_events (event_name, outcome, occurred_at) VALUES (:event_name, :outcome, :occurred_at)',
        );
        $statement->execute([
            'event_name' => $eventName,
            'outcome' => $outcome,
            'occurred_at' => $this->formatTimestamp($occurredAt),
        ]);
    }

    private function connection(PrivateStoragePaths $paths): PDO
    {
        if (!is_file($paths->databaseFile())) {
            throw new InstallationSettingsFailure('installation_required', 'Formvex is not initialized. Run the installation command before opening Settings.');
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
            throw new InstallationSettingsFailure('database_unavailable', 'Formvex could not access its local database. Check the private storage and try again.');
        }
    }

    /** @param array<mixed, mixed> $row */
    private function stringValue(array $row, string $key): string
    {
        if (!isset($row[$key]) || !is_string($row[$key])) {
            throw new InstallationSettingsFailure('settings_state_invalid', 'Formvex found an invalid value in the saved installation settings.');
        }

        return $row[$key];
    }

    /** @param array<mixed, mixed> $row */
    private function nullableStringValue(array $row, string $key): ?string
    {
        if ($row[$key] === null) {
            return null;
        }

        return $this->stringValue($row, $key);
    }

    /** @param array<mixed, mixed> $row */
    private function integerValue(array $row, string $key): int
    {
        if (!isset($row[$key]) || (!is_int($row[$key]) && !is_string($row[$key]) && !is_float($row[$key]))) {
            throw new InstallationSettingsFailure('settings_state_invalid', 'Formvex found an invalid numeric value in the saved installation settings.');
        }

        return (int) $row[$key];
    }

    /** @param array<mixed, mixed> $row */
    private function nullableTimestampValue(array $row, string $key): ?DateTimeImmutable
    {
        if ($row[$key] === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($this->stringValue($row, $key), new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new InstallationSettingsFailure('settings_state_invalid', 'Formvex found an invalid timestamp in the saved SMTP test state.');
        }
    }

    private function formatTimestamp(DateTimeImmutable $timestamp): string
    {
        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');
    }

    private function rollback(PDO $connection): void
    {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
    }
}
