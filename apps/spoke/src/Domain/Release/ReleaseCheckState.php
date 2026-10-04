<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Release;

use DateTimeImmutable;
use DateTimeZone;

final readonly class ReleaseCheckState
{
    public function __construct(
        public string $status,
        public string $currentVersion,
        public ?string $availableVersion,
        public string $severity,
        public ?string $minimumSupportedVersion,
        public ?string $releaseNotesUrl,
        public ?string $packageUrl,
        public ?string $packageSha256,
        public ?DateTimeImmutable $publishedAt,
        public ?DateTimeImmutable $lastAttemptAt,
        public ?DateTimeImmutable $lastSuccessfulAt,
        public ?string $failureCode,
        public ?string $failureMessage,
        public ?string $acknowledgedVersion,
        public ?DateTimeImmutable $acknowledgedAt,
    ) {
    }

    public static function initial(string $currentVersion, DateTimeImmutable $now): self
    {
        return new self('never_checked', $currentVersion, null, 'normal', null, null, null, null, null, null, null, null, null, null, null);
    }

    public static function disabled(string $currentVersion, ?self $existing = null): self
    {
        if ($existing === null) {
            return new self('disabled', $currentVersion, null, 'normal', null, null, null, null, null, null, null, null, null, null, null);
        }

        return new self('disabled', $currentVersion, $existing->availableVersion, $existing->severity, $existing->minimumSupportedVersion, $existing->releaseNotesUrl, $existing->packageUrl, $existing->packageSha256, $existing->publishedAt, $existing->lastAttemptAt, $existing->lastSuccessfulAt, $existing->failureCode, $existing->failureMessage, $existing->acknowledgedVersion, $existing->acknowledgedAt);
    }

    public function withStatus(string $status, string $currentVersion): self
    {
        return new self($status, $currentVersion, $this->availableVersion, $this->severity, $this->minimumSupportedVersion, $this->releaseNotesUrl, $this->packageUrl, $this->packageSha256, $this->publishedAt, $this->lastAttemptAt, $this->lastSuccessfulAt, $this->failureCode, $this->failureMessage, $this->acknowledgedVersion, $this->acknowledgedAt);
    }

    public function isStale(DateTimeImmutable $now): bool
    {
        return $this->status !== 'disabled'
            && $this->lastSuccessfulAt !== null
            && $this->lastSuccessfulAt->getTimestamp() <= $now->setTimezone(new DateTimeZone('UTC'))->getTimestamp() - 172800;
    }

    public function displayStatus(DateTimeImmutable $now): string
    {
        return $this->isStale($now) ? 'stale' : $this->status;
    }

    public function canAcknowledge(): bool
    {
        return $this->status === 'available'
            && $this->availableVersion !== null
            && in_array($this->severity, ['normal', 'important'], true)
            && $this->acknowledgedVersion !== $this->availableVersion;
    }

    public function noticeVisible(): bool
    {
        return $this->status === 'available'
            && $this->availableVersion !== null
            && ($this->severity === 'critical' || $this->acknowledgedVersion !== $this->availableVersion);
    }
}
