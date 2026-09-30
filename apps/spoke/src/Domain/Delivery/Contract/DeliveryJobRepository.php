<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Delivery\Contract;

use DateTimeImmutable;
use Formvex\Core\Delivery\DeliveryOutcomeType;
use Formvex\Spoke\Domain\Delivery\ClaimedDeliveryJob;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface DeliveryJobRepository
{
    /** @return list<ClaimedDeliveryJob> */
    public function claimDueJobs(PrivateStoragePaths $paths, DateTimeImmutable $now, int $limit, string $leaseToken, DateTimeImmutable $leaseExpiresAt): array;

    public function markTransmitting(PrivateStoragePaths $paths, ClaimedDeliveryJob $job, DateTimeImmutable $now): bool;

    public function recordOutcome(PrivateStoragePaths $paths, ClaimedDeliveryJob $job, DeliveryOutcomeType $outcome, string $errorCode, ?DateTimeImmutable $nextDueAt, DateTimeImmutable $now): bool;
}
