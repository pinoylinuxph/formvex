<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Scheduler;

final readonly class SchedulerHealth
{
    /** @param array<string, SchedulerJobStatus> $jobs */
    public function __construct(
        public array $jobs,
        public string $status,
        public string $severity,
        public string $message,
    ) {
    }

    public function job(string $key): SchedulerJobStatus
    {
        return $this->jobs[$key];
    }
}
