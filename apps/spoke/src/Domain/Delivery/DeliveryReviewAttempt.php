<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Delivery;

final readonly class DeliveryReviewAttempt
{
    public function __construct(
        public int $cycleNumber,
        public string $origin,
        public int $attemptNumber,
        public string $outcome,
        public string $errorCode,
        public string $errorMessage,
        public string $startedAt,
        public ?string $completedAt,
        public ?string $nextDueAt,
    ) {
    }
}
