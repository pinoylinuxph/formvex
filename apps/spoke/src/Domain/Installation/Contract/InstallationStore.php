<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Installation\Contract;

use Formvex\Spoke\Domain\Installation\InstallationInitialization;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface InstallationStore
{
    public function initialize(PrivateStoragePaths $paths): InstallationInitialization;
}
