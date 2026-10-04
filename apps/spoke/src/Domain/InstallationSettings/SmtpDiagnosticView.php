<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\InstallationSettings;

use DateTimeImmutable;

final readonly class SmtpDiagnosticView
{
    public function __construct(
        public SmtpDiagnosticStatus $status,
        public string $label,
        public string $severity,
        public string $explanation,
        public string $guidance,
        public string $configurationStatus,
        public ?DateTimeImmutable $testedAt,
        public ?string $failureStage,
        public bool $testAllowed,
    ) {
    }

    public static function unavailable(): self
    {
        return new self(
            SmtpDiagnosticStatus::UNAVAILABLE,
            'Unavailable',
            'danger',
            'The saved SMTP diagnostic state could not be read safely.',
            'Check the private installation storage and database, then refresh Diagnostics. No SMTP test was sent.',
            'Not available',
            null,
            null,
            false,
        );
    }
}
