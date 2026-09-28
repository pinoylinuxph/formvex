<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\InstallationSettings;

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
    ) {
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
        );
    }
}
