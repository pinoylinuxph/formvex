<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Retention\Contract;

use Formvex\Spoke\Domain\Installation\Contract\StorageLock;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface RetentionLock
{
    public function acquire(PrivateStoragePaths $paths): StorageLock;
}
