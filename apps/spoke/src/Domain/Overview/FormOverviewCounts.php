<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Overview;

final readonly class FormOverviewCounts
{
    public function __construct(
        public int $total,
        public int $active,
        public int $needsAttention,
    ) {
    }
}
