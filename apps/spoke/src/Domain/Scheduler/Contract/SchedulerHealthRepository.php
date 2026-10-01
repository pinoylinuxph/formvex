<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Scheduler\Contract;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Scheduler\SchedulerHealth;

interface SchedulerHealthRepository
{
    public function status(PrivateStoragePaths $paths, DateTimeImmutable $now): SchedulerHealth;
}
