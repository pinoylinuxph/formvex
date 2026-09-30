<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Delivery\Contract;

use DateTimeImmutable;
use Formvex\Core\Delivery\DeliveryOutcomeType;
use Formvex\Spoke\Domain\Delivery\ClaimedOperationalAlert;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface OperationalAlertRepository
{
    public function hasDueAlert(PrivateStoragePaths $paths, DateTimeImmutable $now): bool;

    public function claimDueAlert(PrivateStoragePaths $paths, DateTimeImmutable $now, string $leaseToken, DateTimeImmutable $leaseExpiresAt): ?ClaimedOperationalAlert;

    public function recordOutcome(PrivateStoragePaths $paths, ClaimedOperationalAlert $alert, DeliveryOutcomeType $outcome, string $errorCode, ?DateTimeImmutable $nextDueAt, DateTimeImmutable $now): bool;
}
