<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\InstallationSettings;

use DateTimeImmutable;

final readonly class SmtpTestState
{
    public function __construct(
        public SmtpTestStatus $status,
        public int $testedRevision,
        public ?string $failureCode,
        public ?string $summary,
        public ?DateTimeImmutable $requestedAt,
        public ?DateTimeImmutable $completedAt,
        public ?DateTimeImmutable $cooldownUntil,
    ) {
    }

    public static function notConfigured(): self
    {
        return new self(SmtpTestStatus::NOT_CONFIGURED, 0, null, 'SMTP is not configured.', null, null, null);
    }

    public function stale(int $revision): self
    {
        return new self(SmtpTestStatus::STALE, $revision, 'smtp_test_stale', 'SMTP settings changed. Send a new test email before using this configuration.', null, null, null);
    }
}
