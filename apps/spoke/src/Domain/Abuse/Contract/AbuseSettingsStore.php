<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Abuse\Contract;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Abuse\SubmissionAbuseSettings;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface AbuseSettingsStore
{
    public function get(PrivateStoragePaths $paths): SubmissionAbuseSettings;

    public function save(PrivateStoragePaths $paths, SubmissionAbuseSettings $settings, DateTimeImmutable $now): void;

    public function recordAudit(PrivateStoragePaths $paths, string $eventName, string $outcome, DateTimeImmutable $occurredAt): void;
}
