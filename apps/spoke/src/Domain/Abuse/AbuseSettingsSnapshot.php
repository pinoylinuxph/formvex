<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Abuse;

final readonly class AbuseSettingsSnapshot
{
    public function __construct(
        public SubmissionAbuseSettings $settings,
        public bool $turnstileSecretConfigured,
        public CaptchaOutageState $outageState,
    ) {
    }

    public function turnstileReady(): bool
    {
        return $this->turnstileSecretConfigured;
    }
}
