<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\InstallationSettings;

final readonly class SettingsSnapshot
{
    public function __construct(
        public InstallationSettings $settings,
        public SmtpTestState $testState,
        public bool $smtpSecretConfigured,
    ) {
    }

    public function smtpReady(): bool
    {
        return $this->settings->smtpHost !== ''
            && $this->settings->smtpUsername !== ''
            && $this->settings->senderEmail !== ''
            && $this->smtpSecretConfigured;
    }
}
