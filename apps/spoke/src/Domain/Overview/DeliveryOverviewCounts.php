<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Overview;

final readonly class DeliveryOverviewCounts
{
    public function __construct(
        public int $total,
        public int $queued,
        public int $processing,
        public int $sent,
        public int $failed,
        public int $uncertain,
    ) {
    }

    public function attention(): int
    {
        return $this->failed + $this->uncertain;
    }
}
