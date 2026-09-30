<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Delivery;

final readonly class ClaimedOperationalAlert
{
    public function __construct(
        public int $id,
        public string $eventCode,
        public int $attemptNumber,
        public string $leaseToken,
    ) {
    }
}
