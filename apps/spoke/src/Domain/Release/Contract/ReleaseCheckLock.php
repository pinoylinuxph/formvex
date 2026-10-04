<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Release\Contract;

use Formvex\Spoke\Domain\Installation\Contract\StorageLock;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface ReleaseCheckLock
{
    public function acquire(PrivateStoragePaths $paths): StorageLock;
}
