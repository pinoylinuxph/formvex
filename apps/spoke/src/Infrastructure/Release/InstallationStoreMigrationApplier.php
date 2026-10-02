<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Release;

use Formvex\Spoke\Domain\Installation\Contract\InstallationStore;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Release\Contract\ReleaseMigrationApplier;

final readonly class InstallationStoreMigrationApplier implements ReleaseMigrationApplier
{
    public function __construct(private InstallationStore $installationStore)
    {
    }

    public function apply(PrivateStoragePaths $paths): string
    {
        return $this->installationStore->initialize($paths)->identity->schemaVersion;
    }
}
