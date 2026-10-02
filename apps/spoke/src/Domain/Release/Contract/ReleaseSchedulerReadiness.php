<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Release\Contract;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface ReleaseSchedulerReadiness
{
    public function assertReady(PrivateStoragePaths $paths, DateTimeImmutable $now): void;
}
