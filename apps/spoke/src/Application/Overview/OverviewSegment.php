<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Overview;

final readonly class OverviewSegment
{
    public function __construct(
        public string $label,
        public int $value,
        public int $percent,
        public string $tone,
    ) {
    }
}
