<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Administration;

use DateTimeImmutable;

final readonly class ThrottleState
{
    public function __construct(
        public int $failedAttempts,
        public DateTimeImmutable $windowStartedAt,
        public ?DateTimeImmutable $cooldownUntil,
    ) {
    }

    public function isCoolingDown(DateTimeImmutable $now): bool
    {
        return $this->cooldownUntil !== null && $this->cooldownUntil > $now;
    }
}
