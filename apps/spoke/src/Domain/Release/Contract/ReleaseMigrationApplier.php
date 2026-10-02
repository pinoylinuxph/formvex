<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Release\Contract;

use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface ReleaseMigrationApplier
{
    public function apply(PrivateStoragePaths $paths): string;
}
