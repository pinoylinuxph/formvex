<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Overview;

final readonly class OverviewStat
{
    public function __construct(
        public string $label,
        public string $value,
        public string $detail,
        public string $severity = 'neutral',
    ) {
    }
}
