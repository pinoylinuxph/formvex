<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\InstallationSettings;

use Formvex\Spoke\Domain\InstallationSettings\Exception\InstallationSettingsFailure;

final readonly class InstallationSettings
{
    public function __construct(
        public string $websiteDisplayName,
        public string $bareDomain,
        public ?string $wwwAlias,
        public string $operationalAlertEmail,
        public string $senderEmail,
        public ?string $senderName,
        public string $smtpHost,
        public int $smtpPort,
        public SmtpEncryption $smtpEncryption,
        public string $smtpUsername,
        public int $smtpTimeoutSeconds,
        public LoginThrottleSettings $loginThrottle,
        public int $smtpConfigurationRevision,
        public string $smtpSecretSlot,
        public int $discoveryPayloadLimitBytes = 131072,
        public int $smtpAttemptsPerMinute = 10,
        public int $ordinaryRetentionDays = 30,
        public int $uncertainRetentionDays = 90,
        public int $auditRetentionDays = 30,
    ) {
        if ($this->discoveryPayloadLimitBytes < 131072 || $this->discoveryPayloadLimitBytes > 1048576) {
            throw new InstallationSettingsFailure('discovery_payload_limit_invalid', 'The discovery payload limit must be between 128 KiB and 1,024 KiB.');
        }

        if ($this->smtpAttemptsPerMinute < 1 || $this->smtpAttemptsPerMinute > 60) {
            throw new InstallationSettingsFailure('smtp_pacing_invalid', 'The SMTP attempt limit must be between 1 and 60 attempts per minute.');
        }

        foreach ([
            'ordinary_retention_days' => $this->ordinaryRetentionDays,
            'uncertain_retention_days' => $this->uncertainRetentionDays,
            'audit_retention_days' => $this->auditRetentionDays,
        ] as $field => $days) {
            if ($days < 1 || $days > 365) {
                throw new InstallationSettingsFailure('retention_days_invalid', 'Retention periods must be between 1 and 365 days.', [$field => 'Enter a value from 1 to 365 days.']);
            }
        }
    }

    public static function defaults(): self
    {
        return new self(
            'Local Spoke',
            '',
            null,
            '',
            '',
            null,
            '',
            SmtpEncryption::SMTPS->defaultPort(),
            SmtpEncryption::SMTPS,
            '',
            10,
            new LoginThrottleSettings(),
            1,
            'a',
            131072,
            10,
            30,
            90,
            30,
        );
    }

    public function withIdentity(
        string $websiteDisplayName,
        string $bareDomain,
        ?string $wwwAlias,
        string $operationalAlertEmail,
    ): self {
        return new self(
            $websiteDisplayName,
            $bareDomain,
            $wwwAlias,
            $operationalAlertEmail,
            $this->senderEmail,
            $this->senderName,
            $this->smtpHost,
            $this->smtpPort,
            $this->smtpEncryption,
            $this->smtpUsername,
            $this->smtpTimeoutSeconds,
            $this->loginThrottle,
            $this->smtpConfigurationRevision,
            $this->smtpSecretSlot,
            $this->discoveryPayloadLimitBytes,
            $this->smtpAttemptsPerMinute,
            $this->ordinaryRetentionDays,
            $this->uncertainRetentionDays,
            $this->auditRetentionDays,
        );
    }

    public function withSmtp(
        string $senderEmail,
        ?string $senderName,
        string $smtpHost,
        int $smtpPort,
        SmtpEncryption $smtpEncryption,
        string $smtpUsername,
        int $smtpTimeoutSeconds,
        string $smtpSecretSlot,
    ): self {
        return new self(
            $this->websiteDisplayName,
            $this->bareDomain,
            $this->wwwAlias,
            $this->operationalAlertEmail,
            $senderEmail,
            $senderName,
            $smtpHost,
            $smtpPort,
            $smtpEncryption,
            $smtpUsername,
            $smtpTimeoutSeconds,
            $this->loginThrottle,
            $this->smtpConfigurationRevision + 1,
            $smtpSecretSlot,
            $this->discoveryPayloadLimitBytes,
            $this->smtpAttemptsPerMinute,
            $this->ordinaryRetentionDays,
            $this->uncertainRetentionDays,
            $this->auditRetentionDays,
        );
    }

    public function withLoginThrottle(LoginThrottleSettings $loginThrottle): self
    {
        return new self(
            $this->websiteDisplayName,
            $this->bareDomain,
            $this->wwwAlias,
            $this->operationalAlertEmail,
            $this->senderEmail,
            $this->senderName,
            $this->smtpHost,
            $this->smtpPort,
            $this->smtpEncryption,
            $this->smtpUsername,
            $this->smtpTimeoutSeconds,
            $loginThrottle,
            $this->smtpConfigurationRevision,
            $this->smtpSecretSlot,
            $this->discoveryPayloadLimitBytes,
            $this->smtpAttemptsPerMinute,
            $this->ordinaryRetentionDays,
            $this->uncertainRetentionDays,
            $this->auditRetentionDays,
        );
    }

    public function withDiscoveryPayloadLimitBytes(int $bytes): self
    {
        return new self(
            $this->websiteDisplayName,
            $this->bareDomain,
            $this->wwwAlias,
            $this->operationalAlertEmail,
            $this->senderEmail,
            $this->senderName,
            $this->smtpHost,
            $this->smtpPort,
            $this->smtpEncryption,
            $this->smtpUsername,
            $this->smtpTimeoutSeconds,
            $this->loginThrottle,
            $this->smtpConfigurationRevision,
            $this->smtpSecretSlot,
            $bytes,
            $this->smtpAttemptsPerMinute,
            $this->ordinaryRetentionDays,
            $this->uncertainRetentionDays,
            $this->auditRetentionDays,
        );
    }

    public function withSmtpPacing(int $attemptsPerMinute): self
    {
        return new self(
            $this->websiteDisplayName,
            $this->bareDomain,
            $this->wwwAlias,
            $this->operationalAlertEmail,
            $this->senderEmail,
            $this->senderName,
            $this->smtpHost,
            $this->smtpPort,
            $this->smtpEncryption,
            $this->smtpUsername,
            $this->smtpTimeoutSeconds,
            $this->loginThrottle,
            $this->smtpConfigurationRevision,
            $this->smtpSecretSlot,
            $this->discoveryPayloadLimitBytes,
            $attemptsPerMinute,
            $this->ordinaryRetentionDays,
            $this->uncertainRetentionDays,
            $this->auditRetentionDays,
        );
    }

    public function withRetention(int $ordinaryRetentionDays, int $uncertainRetentionDays, int $auditRetentionDays): self
    {
        return new self(
            $this->websiteDisplayName,
            $this->bareDomain,
            $this->wwwAlias,
            $this->operationalAlertEmail,
            $this->senderEmail,
            $this->senderName,
            $this->smtpHost,
            $this->smtpPort,
            $this->smtpEncryption,
            $this->smtpUsername,
            $this->smtpTimeoutSeconds,
            $this->loginThrottle,
            $this->smtpConfigurationRevision,
            $this->smtpSecretSlot,
            $this->discoveryPayloadLimitBytes,
            $this->smtpAttemptsPerMinute,
            $ordinaryRetentionDays,
            $uncertainRetentionDays,
            $auditRetentionDays,
        );
    }
}
