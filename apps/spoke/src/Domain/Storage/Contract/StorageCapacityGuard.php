<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Storage\Contract;

use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Storage\StorageCapacityDecision;

interface StorageCapacityGuard
{
    public function evaluate(PrivateStoragePaths $paths, int $additionalBytes = 0): StorageCapacityDecision;
}
