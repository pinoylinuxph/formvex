<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\InstallationSettings;

use DateInterval;
use DateTimeImmutable;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\InstallationSettings\Contract\InstallationSettingsStore;
use Formvex\Spoke\Domain\InstallationSettings\Contract\SmtpSecretStore;
use Formvex\Spoke\Domain\InstallationSettings\Contract\SmtpTestTransport;
use Formvex\Spoke\Domain\InstallationSettings\Exception\InstallationSettingsFailure;
use Formvex\Spoke\Domain\InstallationSettings\InstallationSettings;
use Formvex\Spoke\Domain\InstallationSettings\LoginThrottleSettings;
use Formvex\Spoke\Domain\InstallationSettings\SettingsSnapshot;
use Formvex\Spoke\Domain\InstallationSettings\SmtpEncryption;
use Formvex\Spoke\Domain\InstallationSettings\SmtpTestState;
use Formvex\Spoke\Domain\InstallationSettings\SmtpTestStatus;

final readonly class InstallationSettingsService
{
    private const SMTP_TEST_COOLDOWN_SECONDS = 60;

    public function __construct(
        private SpokeStorageResolver $storageResolver,
        private InstallationSettingsStore $settingsStore,
        private SmtpSecretStore $secretStore,
        private SmtpTestTransport $smtpTestTransport,
        private Clock $clock,
    ) {
    }

    public function snapshot(string $applicationRoot): SettingsSnapshot
    {
        $paths = $this->paths($applicationRoot);
        $settings = $this->settingsStore->get($paths);
        $state = $this->settingsStore->getTestState($paths);

        if ($state->status === SmtpTestStatus::PASSED && $state->testedRevision !== $settings->smtpConfigurationRevision) {
            $state = $state->stale($settings->smtpConfigurationRevision);
        }

        return new SettingsSnapshot(
            $settings,
            $state,
            $this->secretStore->isConfigured($paths, $settings->smtpSecretSlot),
        );
    }

    /** @param array<string, string> $input */
    public function saveIdentity(string $applicationRoot, array $input): SettingsSnapshot
    {
        $paths = $this->paths($applicationRoot);
        $current = $this->settingsStore->get($paths);
        $settings = $current->withIdentity(
            $this->displayName($input, 'website_display_name'),
            $this->bareDomain($input, 'bare_domain'),
            $this->wwwAlias($input, 'www_alias'),
            $this->email($input, 'operational_alert_email', 'operational-alert address'),
        );
        $now = $this->clock->now();
        $this->settingsStore->save($paths, $settings, $now);
        $this->settingsStore->recordAudit($paths, 'spoke.settings.identity_saved', 'success', $now);

        return $this->snapshot($applicationRoot);
    }

    /** @param array<string, string> $input */
    public function saveSmtp(string $applicationRoot, array $input): SettingsSnapshot
    {
        $paths = $this->paths($applicationRoot);
        $current = $this->settingsStore->get($paths);
        $encryption = SmtpEncryption::fromInput($this->required($input, 'smtp_encryption', 'encryption mode'));
        $password = $input['smtp_password'] ?? '';

        $senderEmail = $this->email($input, 'sender_email', 'sender address');
        $senderName = $this->optionalText($input, 'sender_name', 120);
        $smtpHost = $this->smtpHost($input);
        $smtpPort = $this->port($input);
        $smtpUsername = $this->text($input, 'smtp_username', 'SMTP username', 254);
        $smtpTimeout = $this->timeout($input);
        $smtpAttemptsPerMinute = $this->smtpAttemptsPerMinute($input, $current->smtpAttemptsPerMinute);
        $secretSlot = $current->smtpSecretSlot;

        if ($password !== '') {
            $secretSlot = $secretSlot === 'a' ? 'b' : 'a';
            $this->secretStore->write($paths, $secretSlot, $password);
        } elseif (!$this->secretStore->isConfigured($paths, $secretSlot)) {
            throw new InstallationSettingsFailure('smtp_password_missing', 'Enter the SMTP password before saving the SMTP settings.', ['smtp_password' => 'This field is required for the first SMTP password.']);
        }

        $settings = $current->withSmtp(
            $senderEmail,
            $senderName,
            $smtpHost,
            $smtpPort,
            $encryption,
            $smtpUsername,
            $smtpTimeout,
            $secretSlot,
        )->withSmtpPacing($smtpAttemptsPerMinute);

        try {
            $now = $this->clock->now();
            $this->settingsStore->save($paths, $settings, $now);
            $this->settingsStore->saveTestState($paths, $this->settingsStore->getTestState($paths)->stale($settings->smtpConfigurationRevision));
            $this->settingsStore->recordAudit($paths, 'spoke.settings.smtp_saved', 'success', $now);
        } catch (InstallationSettingsFailure $failure) {
            if ($password !== '') {
                $this->removeInactiveSecret($paths, $secretSlot, $current->smtpSecretSlot);
            }

            throw $failure;
        }

        if ($password !== '' && $secretSlot !== $current->smtpSecretSlot) {
            try {
                $this->secretStore->remove($paths, $current->smtpSecretSlot);
            } catch (InstallationSettingsFailure) {
                // The database points to the replacement secret. Cleanup is safe to retry later.
            }
        }

        return $this->snapshot($applicationRoot);
    }

    /** @param array<string, string> $input */
    public function saveSecurity(string $applicationRoot, array $input): SettingsSnapshot
    {
        $paths = $this->paths($applicationRoot);
        $current = $this->settingsStore->get($paths);
        $settings = $current->withLoginThrottle(new LoginThrottleSettings(
            $this->integer($input, 'maximum_failures', 'failed-login limit'),
            $this->integer($input, 'window_minutes', 'login window'),
            $this->integer($input, 'cooldown_minutes', 'login cooldown'),
        ));
        $now = $this->clock->now();
        $this->settingsStore->save($paths, $settings, $now);
        $this->settingsStore->recordAudit($paths, 'spoke.settings.security_saved', 'success', $now);

        return $this->snapshot($applicationRoot);
    }

    /** @param array<string, string> $input */
    public function saveDiscovery(string $applicationRoot, array $input): SettingsSnapshot
    {
        $paths = $this->paths($applicationRoot);
        $current = $this->settingsStore->get($paths);
        $kib = $this->integer($input, 'discovery_payload_limit_kib', 'discovery payload limit');

        if ($kib < 128 || $kib > 1024) {
            throw new InstallationSettingsFailure(
                'discovery_payload_limit_invalid',
                'The discovery payload limit must be between 128 KiB and 1,024 KiB.',
                ['discovery_payload_limit_kib' => 'Enter a value from 128 to 1,024 KiB.'],
            );
        }

        $settings = $current->withDiscoveryPayloadLimitBytes($kib * 1024);
        $now = $this->clock->now();
        $this->settingsStore->save($paths, $settings, $now);
        $this->settingsStore->recordAudit($paths, 'spoke.settings.discovery_saved', 'success', $now);

        return $this->snapshot($applicationRoot);
    }

    /** @param array<string, string> $input */
    public function saveRetention(string $applicationRoot, array $input): SettingsSnapshot
    {
        $paths = $this->paths($applicationRoot);
        $current = $this->settingsStore->get($paths);
        $settings = $current->withRetention(
            $this->retentionDays($input, 'ordinary_retention_days', 'ordinary submission retention'),
            $this->retentionDays($input, 'uncertain_retention_days', 'uncertain delivery retention'),
            $this->retentionDays($input, 'audit_retention_days', 'audit and operational-log retention'),
        );
        $now = $this->clock->now();
        $this->settingsStore->save($paths, $settings, $now);
        $this->settingsStore->recordAudit($paths, 'spoke.settings.retention_saved', 'success', $now);

        return $this->snapshot($applicationRoot);
    }

    public function sendSmtpTest(string $applicationRoot, string $recipient): SmtpTestState
    {
        $paths = $this->paths($applicationRoot);
        $snapshot = $this->snapshot($applicationRoot);
        $recipient = trim($recipient);
        $this->assertEmail($recipient, 'test recipient', 'test_recipient');

        if (!$snapshot->smtpReady()) {
            throw new InstallationSettingsFailure('smtp_not_configured', 'SMTP is not ready for testing. Save a valid host, sender address, username, and password before sending a test email.');
        }

        $now = $this->clock->now();
        $existing = $snapshot->testState;

        if ($existing->cooldownUntil !== null && $existing->cooldownUntil > $now) {
            throw new InstallationSettingsFailure(
                'smtp_test_cooldown',
                'The SMTP test cooldown is active. Wait until the displayed time before sending another test email.',
                [],
                max(1, $existing->cooldownUntil->getTimestamp() - $now->getTimestamp()),
            );
        }

        $result = $this->smtpTestTransport->send($snapshot->settings, $recipient);
        $completedAt = $this->clock->now();
        $state = new SmtpTestState(
            $result->status,
            $snapshot->settings->smtpConfigurationRevision,
            $result->status === SmtpTestStatus::PASSED ? null : $result->failureCode,
            $result->summary,
            $now,
            $completedAt,
            $completedAt->add(new DateInterval('PT' . self::SMTP_TEST_COOLDOWN_SECONDS . 'S')),
        );
        $this->settingsStore->saveTestState($paths, $state);
        $this->settingsStore->recordAudit($paths, 'spoke.settings.smtp_test', $result->status->value, $completedAt);

        return $state;
    }

    private function paths(string $applicationRoot): PrivateStoragePaths
    {
        return $this->storageResolver->resolve($applicationRoot);
    }

    /** @param array<string, string> $input */
    private function displayName(array $input, string $key): string
    {
        return $this->text($input, $key, 'website display name', 120);
    }

    /** @param array<string, string> $input */
    private function bareDomain(array $input, string $key): string
    {
        $value = $this->host($input, $key, 'bare domain');

        if (str_starts_with($value, 'www.')) {
            throw new InstallationSettingsFailure('bare_domain_invalid', 'Enter the bare domain without the www prefix.', [$key => 'Use a value such as example.com.']);
        }

        return $value;
    }

    /** @param array<string, string> $input */
    private function wwwAlias(array $input, string $key): ?string
    {
        $value = trim($input[$key] ?? '');

        if ($value === '') {
            return null;
        }

        $value = $this->host($input, $key, 'www alias');

        if (!str_starts_with($value, 'www.')) {
            throw new InstallationSettingsFailure('www_alias_invalid', 'The www alias must begin with www.', [$key => 'Use a value such as www.example.com.']);
        }

        return $value;
    }

    /** @param array<string, string> $input */
    private function smtpHost(array $input): string
    {
        return $this->host($input, 'smtp_host', 'SMTP host');
    }

    /** @param array<string, string> $input */
    private function host(array $input, string $key, string $label): string
    {
        $value = strtolower(trim($this->required($input, $key, $label)));
        $value = rtrim($value, '.');

        if ($value === '' || strlen($value) > 253 || preg_match('/[\s\x00-\x1F\x7F\/:?#*]/', $value) === 1) {
            throw new InstallationSettingsFailure($key . '_invalid', 'The ' . $label . ' must be a hostname or IP address, not a URL or path.', [$key => 'Enter a hostname or IP address without a scheme, path, port, or wildcard.']);
        }

        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return $value;
        }

        if (preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $value) !== 1) {
            throw new InstallationSettingsFailure($key . '_invalid', 'The ' . $label . ' is not valid. Check each hostname label and remove spaces or URL syntax.', [$key => 'Enter a valid hostname or IP address.']);
        }

        return $value;
    }

    /** @param array<string, string> $input */
    private function email(array $input, string $key, string $label): string
    {
        $value = trim($this->required($input, $key, $label));
        $this->assertEmail($value, $label, $key);

        return $value;
    }

    private function assertEmail(string $value, string $label, string $key = 'email'): void
    {
        if ($value === '' || strlen($value) > 254 || preg_match('/[\x00-\x1F\x7F,;\r\n]/', $value) === 1 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InstallationSettingsFailure($key . '_invalid', 'The ' . $label . ' must contain one valid email address.', [$key => 'Enter one valid email address without a display name or recipient list.']);
        }
    }

    /** @param array<string, string> $input */
    private function port(array $input): int
    {
        $value = $this->integer($input, 'smtp_port', 'SMTP port');

        if ($value < 1 || $value > 65535) {
            throw new InstallationSettingsFailure('smtp_port_invalid', 'The SMTP port must be between 1 and 65535.', ['smtp_port' => 'Enter a port from 1 to 65535.']);
        }

        return $value;
    }

    /** @param array<string, string> $input */
    private function timeout(array $input): int
    {
        $value = $this->integer($input, 'smtp_timeout_seconds', 'SMTP connection timeout');

        if ($value < 3 || $value > 60) {
            throw new InstallationSettingsFailure('smtp_timeout_invalid', 'The SMTP connection timeout must be between 3 and 60 seconds.', ['smtp_timeout_seconds' => 'Enter a timeout from 3 to 60 seconds.']);
        }

        return $value;
    }

    /** @param array<string, string> $input */
    private function smtpAttemptsPerMinute(array $input, int $default): int
    {
        if (!array_key_exists('smtp_attempts_per_minute', $input)) {
            return $default;
        }

        $value = $this->integer($input, 'smtp_attempts_per_minute', 'SMTP attempt limit');

        if ($value < 1 || $value > 60) {
            throw new InstallationSettingsFailure('smtp_pacing_invalid', 'The SMTP attempt limit must be between 1 and 60 attempts per minute.', ['smtp_attempts_per_minute' => 'Enter a value from 1 to 60 attempts per minute.']);
        }

        return $value;
    }

    /** @param array<string, string> $input */
    private function retentionDays(array $input, string $key, string $label): int
    {
        $value = $this->integer($input, $key, $label);

        if ($value < 1 || $value > 365) {
            throw new InstallationSettingsFailure('retention_days_invalid', 'The ' . $label . ' must be between 1 and 365 days.', [$key => 'Enter a value from 1 to 365 days.']);
        }

        return $value;
    }

    /** @param array<string, string> $input */
    private function text(array $input, string $key, string $label, int $maximumLength): string
    {
        $value = trim($this->required($input, $key, $label));

        if ($value === '' || strlen($value) > $maximumLength || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InstallationSettingsFailure($key . '_invalid', 'The ' . $label . ' is invalid or too long.', [$key => 'Enter a value from 1 to ' . $maximumLength . ' characters without control characters.']);
        }

        return $value;
    }

    /** @param array<string, string> $input */
    private function optionalText(array $input, string $key, int $maximumLength): ?string
    {
        $value = trim($input[$key] ?? '');

        if ($value === '') {
            return null;
        }

        if (strlen($value) > $maximumLength || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InstallationSettingsFailure($key . '_invalid', 'The sender display name is invalid or too long.', [$key => 'Enter no more than ' . $maximumLength . ' characters without control characters.']);
        }

        return $value;
    }

    /** @param array<string, string> $input */
    private function integer(array $input, string $key, string $label): int
    {
        $value = $input[$key] ?? null;

        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InstallationSettingsFailure($key . '_invalid', 'The ' . $label . ' must be a whole number.', [$key => 'Enter a whole number.']);
        }

        return (int) $value;
    }

    /** @param array<string, string> $input */
    private function required(array $input, string $key, string $label): string
    {
        $value = $input[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new InstallationSettingsFailure($key . '_required', 'The ' . $label . ' is required.', [$key => 'This field is required.']);
        }

        return $value;
    }

    private function removeInactiveSecret(PrivateStoragePaths $paths, string $candidateSlot, string $activeSlot): void
    {
        if ($candidateSlot === $activeSlot || !$this->secretStore->isConfigured($paths, $candidateSlot)) {
            return;
        }

        try {
            $this->secretStore->remove($paths, $candidateSlot);
        } catch (InstallationSettingsFailure) {
            // The database still points to the previous active secret. Cleanup is safe to retry later.
        }
    }
}
