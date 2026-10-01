<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Scheduler;

final readonly class SchedulerJobStatus
{
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $schedule,
        public string $command,
        public string $status,
        public string $severity,
        public ?string $lastSuccessfulAt,
        public ?string $lastAttemptAt,
        public string $message,
        public string $action,
    ) {
    }
}
