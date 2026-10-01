<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Backup\Contract;

use Formvex\Spoke\Domain\Installation\Contract\StorageLock;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface BackupOperationLock
{
    public function acquire(PrivateStoragePaths $paths, string $operation): StorageLock;
}
