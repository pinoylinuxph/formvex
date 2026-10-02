<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Release;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Release\Contract\ReleaseSchedulerReadiness;
use Formvex\Spoke\Domain\Release\ReleaseOperationFailure;
use Formvex\Spoke\Domain\Scheduler\Contract\SchedulerHealthRepository;

final readonly class SchedulerReleaseReadiness implements ReleaseSchedulerReadiness
{
    public function __construct(private SchedulerHealthRepository $schedulerHealth)
    {
    }

    public function assertReady(PrivateStoragePaths $paths, DateTimeImmutable $now): void
    {
        if ($this->schedulerHealth->status($paths, $now)->status !== 'healthy') {
            throw new ReleaseOperationFailure('scheduler_not_ready', 'The application could not confirm successful delivery and retention scheduler heartbeats. Correct the scheduler before reopening the installation.');
        }
    }
}
