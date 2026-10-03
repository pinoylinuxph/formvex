<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Overview;

final readonly class OverviewStatusRow
{
    public function __construct(
        public string $label,
        public string $status,
        public string $detail,
        public string $severity,
        public string $route,
        public string $routeLabel,
    ) {
    }
}
