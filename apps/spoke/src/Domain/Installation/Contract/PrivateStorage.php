<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Installation\Contract;

use Formvex\Spoke\Domain\Installation\InstallationConfiguration;
use Formvex\Spoke\Domain\Installation\InstallationMarker;
use Formvex\Spoke\Domain\Installation\PreflightCheck;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface PrivateStorage
{
    /**
     * @return list<PreflightCheck>
     */
    public function check(InstallationConfiguration $configuration): array;

    public function prepare(InstallationConfiguration $configuration): PrivateStoragePaths;

    public function acquireLock(PrivateStoragePaths $paths): StorageLock;

    public function readMarker(PrivateStoragePaths $paths): ?InstallationMarker;

    public function writeMarker(PrivateStoragePaths $paths, InstallationMarker $marker): void;
}
