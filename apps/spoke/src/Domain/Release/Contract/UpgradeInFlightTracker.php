<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Release\Contract;

use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface UpgradeInFlightTracker
{
    public function begin(PrivateStoragePaths $paths, string $kind): ?string;

    public function finish(PrivateStoragePaths $paths, string $leaseId): void;

    public function startDraining(PrivateStoragePaths $paths): void;

    public function stopDraining(PrivateStoragePaths $paths): void;

    public function activeCount(PrivateStoragePaths $paths): int;
}
