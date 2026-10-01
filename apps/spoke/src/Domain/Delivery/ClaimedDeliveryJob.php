<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Delivery;

use Formvex\Core\Delivery\DeliveryMessageSnapshot;

final readonly class ClaimedDeliveryJob
{
    public function __construct(
        public string $jobId,
        public int $attemptNumber,
        public string $leaseToken,
        public ?DeliveryMessageSnapshot $snapshot,
        public ?int $cycleId = null,
    ) {
    }
}
