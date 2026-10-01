<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Storage\Contract;

use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Storage\StorageSettings;
use Formvex\Spoke\Domain\Storage\StorageUsage;

interface StorageUsageReader
{
    public function read(PrivateStoragePaths $paths, StorageSettings $settings): StorageUsage;
}
