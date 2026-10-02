<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Release;

final readonly class HealthCheckResult
{
    public function __construct(
        public bool $healthy,
        public string $status,
        public string $message,
    ) {
    }
}
