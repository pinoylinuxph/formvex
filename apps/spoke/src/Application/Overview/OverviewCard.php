<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Overview;

final readonly class OverviewCard
{
    /**
     * @param list<OverviewStat> $stats
     * @param list<OverviewSegment> $segments
     */
    public function __construct(
        public string $label,
        public string $primaryValue,
        public string $status,
        public string $severity,
        public string $summary,
        public ?string $route,
        public ?string $routeLabel,
        public array $stats = [],
        public array $segments = [],
        public ?int $meterValue = null,
        public ?int $meterMax = null,
        public ?string $meterLabel = null,
    ) {
    }
}
