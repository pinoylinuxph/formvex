<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Administration\Contract;

use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface SpokeStorageResolver
{
    public function resolve(string $applicationRoot): PrivateStoragePaths;

    public function assertOperatorOwns(PrivateStoragePaths $paths): void;
}
