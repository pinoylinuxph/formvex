<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Overview;

final readonly class OverviewWarning
{
    public function __construct(
        public string $title,
        public string $message,
        public string $severity,
        public string $route,
        public string $routeLabel,
    ) {
    }
}
