<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Abuse\Contract;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Abuse\RateLimitResult;
use Formvex\Spoke\Domain\Abuse\SubmissionAbuseSettings;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface RateLimitStore
{
    public function consumeFlood(PrivateStoragePaths $paths, SubmissionAbuseSettings $settings, string $identity, DateTimeImmutable $now): RateLimitResult;

    public function consumeAttempt(PrivateStoragePaths $paths, SubmissionAbuseSettings $settings, string $identity, string $publicFormId, DateTimeImmutable $now): RateLimitResult;

    public function clearCounters(PrivateStoragePaths $paths): void;

    public function cleanup(PrivateStoragePaths $paths, SubmissionAbuseSettings $settings, DateTimeImmutable $now): int;
}
